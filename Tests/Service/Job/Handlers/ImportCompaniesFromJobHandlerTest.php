<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Company;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Company\CompanyImporter;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportCompaniesFromJobHandler;
use PHPUnit\Framework\TestCase;

/**
 * Toujours une mise a jour PAR ID (external_ref = id d entreprise Mautic,
 * connu avec certitude), jamais de creation : c est le point qui distingue
 * cet outil d'ImportContactsFromJobHandler et qui merite un test dedie,
 * meme structure sinon (filtrage/mapping declaratifs communs, deja couverts
 * par JobItemFilterTest).
 */
class ImportCompaniesFromJobHandlerTest extends TestCase
{
    public function testMappedItemUpdatesTheCompanyByIdAndRecordsSucceeded(): void
    {
        $sourceItem = (new WittyBackgroundJobItem())->setExternalRef('10')->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)->setData(['industry' => 'Software']);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $recorded = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$recorded): void {
            if ($entity instanceof WittyBackgroundJobItem) {
                $recorded[] = $entity;
            }
        });

        $company = new Company();

        $importer = $this->createMock(CompanyImporter::class);
        $importer->expects($this->once())->method('updateById')->with(10, ['companyindustry' => 'Software'])->willReturn($company);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry'], 'filters' => []]);

        (new ImportCompaniesFromJobHandler($importer, $em))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_SUCCEEDED, $recorded[0]->getStatus());
    }

    public function testMissingCompanyIsRecordedAsFailedNeverCreated(): void
    {
        $sourceItem = (new WittyBackgroundJobItem())->setExternalRef('999')->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)->setData(['industry' => 'Finance']);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $recorded = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$recorded): void {
            if ($entity instanceof WittyBackgroundJobItem) {
                $recorded[] = $entity;
            }
        });

        $importer = $this->createMock(CompanyImporter::class);
        $importer->method('updateById')->willReturn(null);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry'], 'filters' => []]);

        (new ImportCompaniesFromJobHandler($importer, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJobItem::STATUS_FAILED, $recorded[0]->getStatus());
    }

    public function testFilteredOutItemNeverReachesTheImporter(): void
    {
        $sourceItem = (new WittyBackgroundJobItem())->setExternalRef('10')->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)->setData(['estimated_num_employees' => 3]);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);

        $importer = $this->createMock(CompanyImporter::class);
        $importer->expects($this->never())->method('updateById');

        $job = (new WittyBackgroundJob())->setParams([
            'source_job_id' => 1,
            'mapping'       => ['companyindustry' => 'industry'],
            'filters'       => [['op' => 'field_equals', 'path' => 'estimated_num_employees', 'value' => 50]],
        ]);

        (new ImportCompaniesFromJobHandler($importer, $em))->processChunk($job);
    }
}
