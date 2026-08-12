<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Recupere une page de resultats d'un job de fond, une fois check_bulk_job
 * signale status=completed (ou meme en cours, pour un apercu partiel). Un job
 * peut porter des dizaines de milliers d'elements : jamais tout renvoye d'un
 * coup, toujours par page bornee (limit/offset).
 *
 * Aucune ecriture automatique sur un contact Mautic ne part d'ici : chaque
 * "data" est un resultat en ATTENTE de revue, c'est a toi d'appeler
 * update_contact/bulk_create_contacts pour l'appliquer, avec le flux de
 * confirmation standard — meme principe que check_waterfall_enrichment.
 */
class ListBulkJobItemsTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 50;
    private const MAX_LIMIT     = 200;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'list_bulk_job_items';
    }

    public function getDescription(): string
    {
        return 'Recupere une page de resultats d un job de fond (job_id). Chaque element porte external_ref '
            .'(identifiant d origine — ex. contact_id Mautic pour un job Apollo) et data (le resultat, en attente '
            .'que tu l appliques toi-meme via update_contact/bulk_create_contacts). Pagine (limit/offset) : ne '
            .'jamais supposer avoir tout recupere sans verifier total.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'job_id' => ['type' => 'integer'],
            'status' => ['type' => 'string', 'enum' => ['succeeded', 'failed', 'skipped']],
            'limit'  => ['type' => 'integer', 'description' => 'Defaut '.self::DEFAULT_LIMIT.', maximum '.self::MAX_LIMIT.'.'],
            'offset' => ['type' => 'integer', 'description' => 'Defaut 0.'],
        ], ['job_id']);
    }

    public function execute(array $arguments): array
    {
        $jobId = (int) ($arguments['job_id'] ?? 0);
        $job   = $this->entityManager->getRepository(WittyBackgroundJob::class)->find($jobId);
        $user  = $this->userHelper->getUser();

        if (!$job instanceof WittyBackgroundJob || $job->getCreatedBy()?->getId() !== $user->getId()) {
            return ['status' => 'error', 'error' => sprintf('Job #%d introuvable.', $jobId)];
        }

        $status = $arguments['status'] ?? null;
        $status = is_string($status) && '' !== $status ? $status : null;
        $limit  = max(1, min(self::MAX_LIMIT, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));
        $offset = max(0, (int) ($arguments['offset'] ?? 0));

        /** @var WittyBackgroundJobItemRepository $repository */
        $repository = $this->entityManager->getRepository(WittyBackgroundJobItem::class);

        $items = $repository->findForJob($jobId, $status, $limit, $offset);
        $total = $repository->countForJob($jobId, $status);

        return $this->ok([
            'items'  => array_map($this->serialize(...), $items),
            'total'  => $total,
            'offset' => $offset,
            'limit'  => $limit,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WittyBackgroundJobItem $item): array
    {
        return array_filter([
            'external_ref'  => $item->getExternalRef(),
            'status'        => $item->getStatus(),
            'data'          => $item->getData(),
            'error_message' => $item->getErrorMessage(),
        ], static fn ($value): bool => null !== $value);
    }
}
