<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportContactsFromJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Convertit en arriere-plan les resultats d'un job de recherche DEJA TERMINE
 * (start_apollo_bulk_enrich_people, start_quickenrich_bulk_search,
 * start_bulk_mcp_search) en contacts Mautic — cf.
 * Service/Job/Handlers/ImportContactsFromJobHandler.php pour le detail.
 *
 * `mapping` associe un alias de champ contact Mautic a un chemin dans les
 * donnees du job source (notation pointee pour un champ imbrique, ex.
 * "useremail.email"). Appelle list_bulk_job_items(job_id=source_job_id,
 * limit=1) au prealable pour voir la forme exacte des donnees et ecrire un
 * mapping correct : ne devine jamais les noms de champs.
 *
 * `filters` est optionnel : une liste de regles combinees en ET (toutes
 * doivent passer), operateurs fixes plutot que du code libre — voir
 * Service/Job/JobItemFilter.php.
 *
 * Un job source FAILED ou CANCELLED reste exploitable : un plantage en cours
 * de route (ex. QuickEnrich qui casse a 10 000/23 603) ou une annulation
 * manuelle (cf. CancelBulkJobTool) laissent quand meme les items deja
 * enregistres en status=succeeded, valides individuellement. Un job source
 * dans l un de ces deux etats est donc accepte au meme titre qu'un completed,
 * tant qu'il a au moins un resultat exploitable — seul un job encore
 * QUEUED/RUNNING est refuse (encore en train d'ecrire, rien de definitif a
 * lire).
 */
class StartContactsImportFromJobTool extends AbstractTool
{
    private const FILTER_OPS = ['has_field', 'field_not_empty', 'field_empty', 'field_equals', 'field_not_equals', 'field_matches'];

    private const IMPORTABLE_SOURCE_STATUSES = [WittyBackgroundJob::STATUS_COMPLETED, WittyBackgroundJob::STATUS_FAILED, WittyBackgroundJob::STATUS_CANCELLED];

    /** Sous-ensemble de IMPORTABLE_SOURCE_STATUSES qui declenche un import marque partial:true. */
    private const PARTIAL_SOURCE_STATUSES = [WittyBackgroundJob::STATUS_FAILED, WittyBackgroundJob::STATUS_CANCELLED];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private ListModel $listModel,
        private UserHelper $userHelper,
        private WittyConfig $config,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function isWriteOperation(): bool
    {
        // A la difference de start_apollo_bulk_enrich_people/start_quickenrich_bulk_search/
        // start_bulk_mcp_search (qui ne font que declencher une recherche
        // externe, rien a voir avec Mautic), ce job cree/met a jour de vrais
        // contacts Mautic — meme categorie que bulk_create_contacts, meme
        // exigence de confirmation.
        return true;
    }

    public function getName(): string
    {
        return 'start_contacts_import_from_job';
    }

    public function getDescription(): string
    {
        return 'Convertit en arriere-plan les resultats d un job de recherche deja termine (Apollo/QuickEnrich/MCP) '
            .'en contacts Mautic, sans repasser les donnees par toi — pour des volumes que start_bulk_create_contacts '
            .'ne peut pas absorber. mapping : alias de champ contact Mautic -> chemin dans les donnees du job source '
            .'(notation pointee pour un champ imbrique). Appelle list_bulk_job_items(job_id=source_job_id, limit=1) '
            .'avant pour voir la forme exacte des donnees. filters (optionnel) : regles combinees en ET, operateurs '
            .'has_field/field_not_empty/field_empty/field_equals/field_not_equals/field_matches (regex) — pour '
            .'ecarter les lignes sans interet (ex. QuickEnrich sans email ni telephone) avant creation. Un job '
            .'source failed ou cancelled reste utilisable (les resultats deja obtenus avant le plantage/l annulation '
            .'sont exploitables), seul un job encore en cours (queued/running) est refuse.';
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:create';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'source_job_id' => ['type' => 'integer', 'description' => 'Job de recherche deja termine (status=completed, failed ou cancelled) dont les resultats seront importes.'],
            'mapping'       => ['type' => 'object', 'description' => 'alias_champ_contact -> chemin (notation pointee) dans les donnees du job source.'],
            'filters'       => [
                'type'        => 'array',
                'description' => 'Regles combinees en ET, appliquees avant le mapping.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'op'      => ['type' => 'string', 'enum' => self::FILTER_OPS],
                        'path'    => ['type' => 'string', 'description' => 'Chemin (notation pointee) dans les donnees du job source.'],
                        'value'   => ['description' => 'Pour field_equals/field_not_equals.'],
                        'pattern' => ['type' => 'string', 'description' => 'Pour field_matches : expression reguliere PCRE complete, ex. "/^.+@.+$/i".'],
                    ],
                    'required' => ['op', 'path'],
                ],
            ],
            'segment_id' => ['type' => 'integer', 'description' => 'Segment existant auquel rattacher les contacts crees/mis a jour.'],
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

        $unknownAliases = $this->fieldWriteGuard->unknownAliases(array_keys($mapping), 'lead');

        if ([] !== $unknownAliases) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu dans mapping : %s. Verifie l orthographe avec l outil list_fields (object: 'contact') avant de reessayer.",
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
            return ['status' => 'error', 'error' => sprintf('Job source #%d n est pas termine (status=%s) : attends qu il passe a completed (un job failed ou cancelled reste exploitable pour les resultats deja obtenus).', $sourceJobId, $sourceStatus)];
        }

        /** @var WittyBackgroundJobItemRepository $itemRepository */
        $itemRepository = $this->entityManager->getRepository(WittyBackgroundJobItem::class);
        // onlyUnconsumed=true : si ce job source a deja fait l objet d un
        // import precedent (courant apres un resume_bulk_job qui l a fait
        // grossir entre-temps), ne recompte que ce qui n a pas encore ete
        // transmis a Mautic — jamais les elements deja importes.
        $available = $itemRepository->countForJob($sourceJobId, WittyBackgroundJobItem::STATUS_SUCCEEDED, true);

        if (0 === $available) {
            return ['status' => 'error', 'error' => sprintf('Job source #%d n a aucun resultat exploitable non deja importe (status=succeeded, pas encore consomme).', $sourceJobId)];
        }

        $segmentId = (int) ($arguments['segment_id'] ?? 0);
        $segment   = null;

        if ($segmentId > 0) {
            $segment = $this->listModel->getEntity($segmentId);

            if (null === $segment) {
                return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', $segmentId)];
            }
        }

        $partial = in_array($sourceStatus, self::PARTIAL_SOURCE_STATUSES, true);

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'type'          => 'contacts_import_from_job',
                'source_job_id' => $sourceJobId,
                'available'     => $available,
                'mapping'       => $mapping,
                'filters'       => [] !== $filters ? $filters : null,
                'segment'       => $segment?->getName(),
                // Signale explicitement un import PARTIEL (job source echoue
                // ou annule en cours de route) : l'utilisateur doit le savoir
                // avant de valider, ce n'est pas la totalite de ce qui etait
                // vise.
                'partial'       => $partial ? true : null,
                'source_status' => $partial ? $sourceStatus : null,
            ], static fn ($value): bool => null !== $value));
        }

        $job = (new WittyBackgroundJob())
            ->setType(ImportContactsFromJobHandler::TYPE)
            ->setCreatedBy($user)
            ->setLabel(sprintf(
                '%s contacts depuis job #%d (%d resultats)',
                $partial ? 'Import partiel' : 'Import',
                $sourceJobId,
                $available,
            ))
            ->setParams(array_filter([
                'source_job_id' => $sourceJobId,
                'mapping'       => $mapping,
                'filters'       => $filters,
                'segment_id'    => $segmentId > 0 ? $segmentId : null,
            ], static fn ($value): bool => null !== $value))
            ->setTotalItems($available);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'partial' => $partial,
            'message' => sprintf(
                'Job #%d lance en arriere-plan (%d resultats%s a convertir, ~50 par lot). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                $available,
                $partial ? sprintf(' — import PARTIEL, le job source est %s avant sa cible', WittyBackgroundJob::STATUS_CANCELLED === $sourceStatus ? 'annule' : 'en echec') : '',
                $job->getId(),
            ),
        ]);
    }
}
