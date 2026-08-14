<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportCompaniesFromJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Applique en arriere-plan les resultats d'un job d'enrichissement
 * d'entreprises DEJA TERMINE (typiquement start_apollo_bulk_enrich_companies)
 * sur les entreprises Mautic correspondantes — cf.
 * Service/Job/Handlers/ImportCompaniesFromJobHandler.php.
 *
 * Toujours une mise a jour, jamais une creation : le job source connait deja
 * l id Mautic de chaque entreprise (c est lui qui l a fourni au depart).
 * `mapping`/`filters` declaratifs, meme principe que
 * start_contacts_import_from_job — voir sa docblock pour la decision produit
 * qui a mene a ce choix plutot qu a un script genere.
 *
 * Un job source FAILED reste exploitable (memes raisons que
 * start_contacts_import_from_job) : accepte au meme titre qu'un completed,
 * tant qu'il a au moins un resultat exploitable.
 */
class StartCompaniesImportFromJobTool extends AbstractTool
{
    private const FILTER_OPS = ['has_field', 'field_not_empty', 'field_empty', 'field_equals', 'field_not_equals', 'field_matches'];

    private const IMPORTABLE_SOURCE_STATUSES = [WittyBackgroundJob::STATUS_COMPLETED, WittyBackgroundJob::STATUS_FAILED];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private WittyConfig $config,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getName(): string
    {
        return 'start_companies_import_from_job';
    }

    public function getDescription(): string
    {
        return 'Applique en arriere-plan les resultats d un job d enrichissement d entreprises deja termine '
            .'(ex. start_apollo_bulk_enrich_companies) sur les entreprises Mautic correspondantes — met toujours a '
            .'jour une entreprise existante, ne cree jamais. mapping : alias de champ entreprise Mautic -> chemin '
            .'dans les donnees du job source (notation pointee pour un champ imbrique). Appelle '
            .'list_bulk_job_items(job_id=source_job_id, limit=1) avant pour voir la forme exacte des donnees. '
            .'filters (optionnel) : regles combinees en ET, operateurs has_field/field_not_empty/field_empty/'
            .'field_equals/field_not_equals/field_matches (regex). Un job source failed reste utilisable (les '
            .'resultats deja obtenus avant le plantage sont exploitables), seul un job encore en cours '
            .'(queued/running) est refuse.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:editown';
    }

    public function getObjectType(): ?string
    {
        return 'company';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'source_job_id' => ['type' => 'integer', 'description' => 'Job d enrichissement d entreprises deja termine (status=completed ou failed).'],
            'mapping'       => ['type' => 'object', 'description' => 'alias_champ_entreprise -> chemin (notation pointee) dans les donnees du job source.'],
            'filters'       => [
                'type'        => 'array',
                'description' => 'Regles combinees en ET, appliquees avant le mapping.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'op'      => ['type' => 'string', 'enum' => self::FILTER_OPS],
                        'path'    => ['type' => 'string'],
                        'value'   => ['description' => 'Pour field_equals/field_not_equals.'],
                        'pattern' => ['type' => 'string', 'description' => 'Pour field_matches : expression reguliere PCRE complete.'],
                    ],
                    'required' => ['op', 'path'],
                ],
            ],
        ], ['source_job_id', 'mapping']);
    }

    public function execute(array $arguments): array
    {
        $sourceJobId = (int) ($arguments['source_job_id'] ?? 0);
        $mapping     = (array) ($arguments['mapping'] ?? []);
        $filters     = array_values((array) ($arguments['filters'] ?? []));

        if ([] === $mapping) {
            return ['status' => 'error', 'error' => 'mapping est obligatoire et ne peut pas etre vide.'];
        }

        $unknownAliases = $this->fieldWriteGuard->unknownAliases(array_keys($mapping), 'company');

        if ([] !== $unknownAliases) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu dans mapping : %s. Verifie l orthographe avec l outil list_fields (object: 'company') avant de reessayer.",
                    implode(', ', $unknownAliases),
                ),
            ];
        }

        foreach ($filters as $filter) {
            $op = (string) (is_array($filter) ? ($filter['op'] ?? '') : '');

            if (!in_array($op, self::FILTER_OPS, true)) {
                return ['status' => 'error', 'error' => sprintf('Operateur de filtre inconnu : %s. Valeurs acceptees : %s', $op, implode(', ', self::FILTER_OPS))];
            }

            if ('' === trim((string) (is_array($filter) ? ($filter['path'] ?? '') : ''))) {
                return ['status' => 'error', 'error' => 'path est obligatoire pour chaque filtre.'];
            }
        }

        $user      = $this->userHelper->getUser();
        $sourceJob = $this->entityManager->getRepository(WittyBackgroundJob::class)->find($sourceJobId);

        if (!$sourceJob instanceof WittyBackgroundJob || $sourceJob->getCreatedBy()?->getId() !== $user->getId()) {
            return ['status' => 'error', 'error' => sprintf('Job source #%d introuvable.', $sourceJobId)];
        }

        $sourceStatus = $sourceJob->getStatus();

        if (!in_array($sourceStatus, self::IMPORTABLE_SOURCE_STATUSES, true)) {
            return ['status' => 'error', 'error' => sprintf('Job source #%d n est pas termine (status=%s) : attends qu il passe a completed (ou failed, un echec en cours de route reste exploitable pour les resultats deja obtenus).', $sourceJobId, $sourceStatus)];
        }

        /** @var WittyBackgroundJobItemRepository $itemRepository */
        $itemRepository = $this->entityManager->getRepository(WittyBackgroundJobItem::class);
        // onlyUnconsumed=true : meme raison que StartContactsImportFromJobTool
        // (un import precedent du meme job source, ou une croissance via
        // resume_bulk_job, ne doivent jamais faire recompter ce qui a deja
        // ete transmis a Mautic).
        $available = $itemRepository->countForJob($sourceJobId, WittyBackgroundJobItem::STATUS_SUCCEEDED, true);

        if (0 === $available) {
            return ['status' => 'error', 'error' => sprintf('Job source #%d n a aucun resultat exploitable non deja importe (status=succeeded, pas encore consomme).', $sourceJobId)];
        }

        $partial = WittyBackgroundJob::STATUS_FAILED === $sourceStatus;

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'type'          => 'companies_import_from_job',
                'source_job_id' => $sourceJobId,
                'available'     => $available,
                'mapping'       => $mapping,
                'filters'       => [] !== $filters ? $filters : null,
                'partial'       => $partial ? true : null,
                'source_status' => $partial ? $sourceStatus : null,
            ], static fn ($value): bool => null !== $value));
        }

        $job = (new WittyBackgroundJob())
            ->setType(ImportCompaniesFromJobHandler::TYPE)
            ->setCreatedBy($user)
            ->setLabel(sprintf(
                '%s entreprises depuis job #%d (%d resultats)',
                $partial ? 'Import partiel' : 'Import',
                $sourceJobId,
                $available,
            ))
            ->setParams([
                'source_job_id' => $sourceJobId,
                'mapping'       => $mapping,
                'filters'       => $filters,
            ])
            ->setTotalItems($available);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'partial' => $partial,
            'message' => sprintf(
                'Job #%d lance en arriere-plan (%d resultats%s a appliquer, ~50 par lot). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                $available,
                $partial ? ' — import PARTIEL, le job source a echoue avant sa cible' : '',
                $job->getId(),
            ),
        ]);
    }
}
