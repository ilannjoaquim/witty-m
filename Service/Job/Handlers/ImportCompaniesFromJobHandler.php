<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Company\CompanyImporter;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Job\JobItemFilter;

/**
 * Applique les resultats d'un job d'enrichissement d'entreprises DEJA TERMINE
 * (typiquement apollo_bulk_enrich_companies) sur les entreprises Mautic
 * correspondantes, par lots, sans jamais faire repasser les donnees par le
 * modele — meme principe qu'ImportContactsFromJobHandler.
 *
 * Toujours une mise a jour PAR ID (`external_ref` du job source = id
 * d'entreprise Mautic, connu avec certitude puisque fourni a la creation du
 * job d'enrichissement), jamais de creation : contrairement aux contacts, il
 * n'existe pas de scenario "recherche externe -> nouvelle entreprise" ici,
 * seulement "entreprise deja connue -> enrichissement".
 */
class ImportCompaniesFromJobHandler implements JobHandlerInterface
{
    public const TYPE = 'import_companies_from_job';

    private const BATCH_SIZE = 50;

    public function __construct(
        private CompanyImporter $importer,
        private EntityManagerInterface $em,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params      = $job->getParams();
        $sourceJobId = (int) ($params['source_job_id'] ?? 0);
        $mapping     = (array) ($params['mapping'] ?? []);
        $filters     = (array) ($params['filters'] ?? []);

        $cursor = $job->getResumeCursor() ?? ['offset' => 0];
        $offset = (int) ($cursor['offset'] ?? 0);

        /** @var WittyBackgroundJobItemRepository $sourceRepository */
        $sourceRepository = $this->em->getRepository(WittyBackgroundJobItem::class);
        $sourceItems      = $sourceRepository->findForJob($sourceJobId, WittyBackgroundJobItem::STATUS_SUCCEEDED, self::BATCH_SIZE, $offset);

        if ([] === $sourceItems) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        foreach ($sourceItems as $sourceItem) {
            $data = $sourceItem->getData() ?? [];

            if (!JobItemFilter::matchesAll($data, $filters)) {
                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Ecarte par les filtres.');
                continue;
            }

            $fields = $this->applyMapping($data, $mapping);

            if ([] === $fields) {
                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Aucun champ mappable (mapping/donnees incompatibles).');
                continue;
            }

            $companyId = (int) $sourceItem->getExternalRef();
            $company   = $this->importer->updateById($companyId, $fields);

            if (null === $company) {
                $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_FAILED, null, sprintf('Entreprise #%d introuvable (supprimee depuis ?).', $companyId));
                continue;
            }

            $this->recordItem($job, $sourceItem->getExternalRef(), WittyBackgroundJobItem::STATUS_SUCCEEDED, ['company_id' => $company->getId()]);
        }

        $job->setResumeCursor(['offset' => $offset + count($sourceItems)]);
        $job->setProcessedItems($job->getProcessedItems() + count($sourceItems));

        if (count($sourceItems) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $mapping
     *
     * @return array<string, string>
     */
    private function applyMapping(array $data, array $mapping): array
    {
        $fields = [];

        foreach ($mapping as $alias => $path) {
            $value = JobItemFilter::resolvePath($data, (string) $path);

            if (null === $value) {
                continue;
            }

            $value = trim((string) $value);

            if ('' !== $value) {
                $fields[(string) $alias] = $value;
            }
        }

        return $fields;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    private function recordItem(WittyBackgroundJob $job, string $externalRef, string $status, ?array $data, ?string $error = null): void
    {
        $item = (new WittyBackgroundJobItem())
            ->setJob($job)
            ->setExternalRef($externalRef)
            ->setStatus($status)
            ->setData($data)
            ->setErrorMessage($error);

        $this->em->persist($item);

        if (WittyBackgroundJobItem::STATUS_SUCCEEDED === $status) {
            $job->setSucceededItems($job->getSucceededItems() + 1);
        } else {
            $job->setFailedItems($job->getFailedItems() + 1);
        }
    }
}
