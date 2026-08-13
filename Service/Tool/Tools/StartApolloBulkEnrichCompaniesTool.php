<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichCompaniesJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lance en arriere-plan un enrichissement Apollo (organisations) sur une
 * liste d'entreprises Mautic DEJA EXISTANTES — cf.
 * Service/Job/Handlers/ApolloBulkEnrichCompaniesJobHandler.php pour le detail.
 *
 * Contrairement a start_apollo_bulk_enrich_people (qui prend un segment
 * entier, Mautic sachant deja lister ses membres), Mautic n a pas de notion
 * de "segment d entreprises" : company_ids est une liste explicite,
 * recuperable via search_companies/list_entities(entity=company) au
 * prealable. Les identifiants envoyes a Apollo sont derives directement des
 * champs deja connus de chaque entreprise (nom, site) — rien a retaper.
 */
class StartApolloBulkEnrichCompaniesTool extends AbstractTool
{
    private const MAX_COMPANIES = 5000;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'start_apollo_bulk_enrich_companies';
    }

    public function getDescription(): string
    {
        return 'Lance en arriere-plan un enrichissement Apollo (industrie, taille, technologies) sur une liste '
            .'d entreprises Mautic deja existantes (company_ids — recupere-les via search_companies/list_entities '
            .'au prealable, aucun segment d entreprises n existe cote Mautic). Pour une seule entreprise deja '
            .'identifiee, prefere enrich_company/bulk_enrich_companies (synchrones). Ne renvoie jamais de resultat '
            .'directement : un job_id a suivre via check_bulk_job, puis list_bulk_job_items une fois status=completed.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'company_ids' => [
                'type'        => 'array',
                'items'       => ['type' => 'integer'],
                'description' => 'Identifiants d entreprises Mautic existantes, maximum '.self::MAX_COMPANIES.'.',
            ],
        ], ['company_ids']);
    }

    public function execute(array $arguments): array
    {
        if (!$this->config->isApolloConfigured()) {
            return ['status' => 'error', 'error' => 'Apollo n est pas configure.'];
        }

        $companyIds = array_values(array_unique(array_filter(array_map('intval', (array) ($arguments['company_ids'] ?? [])), static fn (int $id): bool => $id > 0)));

        if ([] === $companyIds) {
            return ['status' => 'error', 'error' => 'company_ids est obligatoire et ne peut pas etre vide.'];
        }

        if (count($companyIds) > self::MAX_COMPANIES) {
            return ['status' => 'error', 'error' => sprintf('Trop d entreprises (%d, maximum %d).', count($companyIds), self::MAX_COMPANIES)];
        }

        $job = (new WittyBackgroundJob())
            ->setType(ApolloBulkEnrichCompaniesJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf('Enrichissement Apollo (entreprises) — %d entreprises', count($companyIds)))
            ->setParams(['company_ids' => $companyIds])
            ->setTotalItems(count($companyIds));

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d lance en arriere-plan (%d entreprises, ~10 par lot). '
                .'Utilise check_bulk_job(job_id=%d) pour suivre la progression.',
                $job->getId(),
                count($companyIds),
                $job->getId(),
            ),
        ]);
    }
}
