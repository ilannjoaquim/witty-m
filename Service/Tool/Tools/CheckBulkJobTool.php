<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Consulte la progression d'un job de fond lance par un des outils
 * start_*bulk* (start_apollo_bulk_enrich_people, start_quickenrich_bulk_search,
 * start_bulk_mcp_search). Fournis job_id pour un job precis (le cas normal,
 * recu en retour du start), ou rien pour lister tes jobs recents. Une fois
 * status=completed, appelle list_bulk_job_items pour revoir les resultats.
 *
 * Scope par utilisateur (comme check_waterfall_enrichment) : un job_id
 * n'appartenant pas a l'utilisateur courant est traite comme introuvable,
 * jamais expose.
 */
class CheckBulkJobTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'check_bulk_job';
    }

    public function getDescription(): string
    {
        return 'Consulte la progression d un job de fond (lance par start_apollo_bulk_enrich_people, '
            .'start_quickenrich_bulk_search ou start_bulk_mcp_search). job_id pour un job precis, ou rien pour '
            .'lister tes jobs recents (status filtre optionnel : queued/running/completed/failed/cancelled). '
            .'Un job status=failed avec succeeded_items > 0 reste exploitable : ne le traite pas comme perdu. '
            .'Deux options, pas besoin de relancer toute la recherche depuis le debut : resume_bulk_job pour '
            .'reprendre le job source lui-meme la ou il s est arrete (utile si error_message ressemble a un '
            .'incident ponctuel du fournisseur), ou start_contacts_import_from_job/start_companies_import_from_job '
            .'pour deja convertir ce qui a ete obtenu.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'job_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['queued', 'running', 'completed', 'failed', 'cancelled'], 'description' => 'Filtre applique seulement quand job_id n est pas fourni.'],
            'limit'  => ['type' => 'integer', 'description' => 'Defaut 20, maximum 50.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        /** @var WittyBackgroundJobRepository $repository */
        $repository = $this->entityManager->getRepository(WittyBackgroundJob::class);
        $user       = $this->userHelper->getUser();

        if (!empty($arguments['job_id'])) {
            $job = $repository->find((int) $arguments['job_id']);

            if (!$job instanceof WittyBackgroundJob || $job->getCreatedBy()?->getId() !== $user->getId()) {
                return ['status' => 'error', 'error' => sprintf('Job #%d introuvable.', (int) $arguments['job_id'])];
            }

            return $this->ok(['job' => $this->serialize($job)]);
        }

        $limit  = max(1, min(50, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
        $status = $arguments['status'] ?? null;
        $status = is_string($status) && '' !== $status ? $status : null;

        $jobs = $repository->findRecentForUser((int) $user->getId(), $status, $limit);

        return $this->ok(['jobs' => array_map($this->serialize(...), $jobs)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WittyBackgroundJob $job): array
    {
        return array_filter([
            'job_id'          => $job->getId(),
            'type'            => $job->getType(),
            'label'           => $job->getLabel(),
            'status'          => $job->getStatus(),
            'total_items'     => $job->getTotalItems(),
            'processed_items' => $job->getProcessedItems(),
            'succeeded_items' => $job->getSucceededItems(),
            'failed_items'    => $job->getFailedItems(),
            'error_message'   => $job->getErrorMessage(),
            'resume_count'    => $job->getResumeCount() > 0 ? $job->getResumeCount() : null,
            'date_added'      => $job->getDateAdded()->format(\DateTimeInterface::ATOM),
            'date_completed'  => $job->getDateCompleted()?->format(\DateTimeInterface::ATOM),
        ], static fn ($value): bool => null !== $value);
    }
}
