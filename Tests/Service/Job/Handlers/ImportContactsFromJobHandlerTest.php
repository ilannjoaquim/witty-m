<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Contact\ContactImporter;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichPeopleJobHandler;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportContactsFromJobHandler;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le point qui merite un test dedie : les elements du job source ne sont lus
 * QUE s ils sont status=succeeded (un echec de recherche n a rien a apporter
 * a un import), le filtrage/mapping declaratif s applique avant toute
 * ecriture, et un element ecarte (filtre ou sans champ mappable) est quand
 * meme trace comme WittyBackgroundJobItem skipped, jamais silencieusement
 * ignore. Egalement : le choix AUTOMATIQUE entre mise a jour par id
 * (job source = enrichissement, cf. testEnrichmentSourceUpdatesByIdNeverCreates())
 * et dedoublonnage par email (tout le reste, cf. les tests existants) selon
 * le type du job source — jamais laisse a l appreciation de l agent.
 */
class ImportContactsFromJobHandlerTest extends TestCase
{
    public function testAllowsMultiplePassesPerTick(): void
    {
        // Aucun appel API externe (donnees deja stockees par le job source) :
        // peut enchainer plusieurs lots dans le meme passage de cron.
        $handler = new ImportContactsFromJobHandler(
            $this->createMock(ContactImporter::class),
            $this->createMock(ListModel::class),
            $this->createMock(EntityManagerInterface::class),
            $this->guard(),
        );

        $this->assertTrue($handler->allowsMultiplePassesPerTick());
    }

    public function testOnlySucceededSourceItemsAreConsidered(): void
    {
        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->expects($this->once())->method('findForJob')
            ->with(42, WittyBackgroundJobItem::STATUS_SUCCEEDED, $this->anything(), 0, true)
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);

        $importer = $this->createMock(ContactImporter::class);
        $listModel = $this->createMock(ListModel::class);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 42, 'mapping' => ['email' => 'email'], 'filters' => []]);

        (new ImportContactsFromJobHandler($importer, $listModel, $em, $this->guard()))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testFilteredOutItemIsRecordedAsSkippedNotImported(): void
    {
        $sourceItem = $this->sourceItem('r1', ['has_email' => false]);

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

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->never())->method('importOne');

        $job = (new WittyBackgroundJob())->setParams([
            'source_job_id' => 1,
            'mapping'       => ['email' => 'email'],
            'filters'       => [['op' => 'field_equals', 'path' => 'has_email', 'value' => true]],
        ]);

        (new ImportContactsFromJobHandler($importer, $this->createMock(ListModel::class), $em, $this->guard()))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_SKIPPED, $recorded[0]->getStatus());
        // Un element ecarte par un filtre doit rester eligible a un futur
        // import avec des filtres differents : jamais marque consomme.
        $this->assertNull($sourceItem->getConsumedAt());
    }

    public function testMappedItemIsImportedAndRecordedAsSucceeded(): void
    {
        $sourceItem = $this->sourceItem('r1', ['useremail' => ['email' => 'a@b.com'], 'first_name' => 'Jane']);

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

        $lead = new Lead();
        (new ReflectionProperty(Lead::class, 'id'))->setValue($lead, 77);

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->once())->method('importOne')
            ->with(['email' => 'a@b.com', 'firstname' => 'Jane'], null)
            ->willReturn(['created' => true, 'lead' => $lead]);

        $job = (new WittyBackgroundJob())->setParams([
            'source_job_id' => 1,
            // Notation pointee : le champ imbrique d un resultat Apollo waterfall.
            'mapping'       => ['email' => 'useremail.email', 'firstname' => 'first_name'],
            'filters'       => [],
        ]);

        (new ImportContactsFromJobHandler($importer, $this->createMock(ListModel::class), $em, $this->guard()))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_SUCCEEDED, $recorded[0]->getStatus());
        $this->assertSame(77, $recorded[0]->getData()['contact_id']);
        // Element reellement transmis a l importeur : marque consomme, pour
        // qu un futur import du meme job source (ex. apres resume_bulk_job)
        // ne le retraite jamais et ne cree pas de doublon.
        $this->assertNotNull($sourceItem->getConsumedAt());
    }

    public function testAlreadyConsumedItemsAreExcludedFromTheQuery(): void
    {
        // Ne verifie pas le filtrage SQL lui-meme (couvert par le
        // comportement reel de onlyUnconsumed dans le repository, teste
        // separement) mais que le handler DEMANDE bien onlyUnconsumed=true —
        // sans ca, un deuxieme import du meme job source relirait tout
        // depuis le debut.
        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->expects($this->once())->method('findForJob')
            ->with($this->anything(), $this->anything(), $this->anything(), $this->anything(), true)
            ->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 1, 'mapping' => ['email' => 'email'], 'filters' => []]);

        (new ImportContactsFromJobHandler($this->createMock(ContactImporter::class), $this->createMock(ListModel::class), $em, $this->guard()))->processChunk($job);
    }

    public function testSegmentIsResolvedAndPassedToTheImporter(): void
    {
        $sourceItem = $this->sourceItem('r1', ['email' => 'a@b.com']);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($itemRepository);

        $segment = $this->createMock(LeadList::class);

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->with(9)->willReturn($segment);

        $lead = new Lead();

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->once())->method('importOne')->with($this->anything(), $segment)->willReturn(['created' => true, 'lead' => $lead]);

        $job = (new WittyBackgroundJob())->setParams([
            'source_job_id' => 1, 'mapping' => ['email' => 'email'], 'filters' => [], 'segment_id' => 9,
        ]);

        (new ImportContactsFromJobHandler($importer, $listModel, $em, $this->guard()))->processChunk($job);
    }

    public function testEnrichmentSourceUpdatesByIdNeverCreates(): void
    {
        // external_ref = id du contact Mautic, pas un profil externe.
        $sourceItem = $this->sourceItem('42', ['title' => 'CEO']);

        $sourceJob = (new WittyBackgroundJob())->setType(ApolloBulkEnrichPeopleJobHandler::TYPE)->setLabel('L');

        $jobRepository = $this->createMock(WittyBackgroundJobRepository::class);
        $jobRepository->method('find')->with(77)->willReturn($sourceJob);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [WittyBackgroundJob::class, $jobRepository],
            [WittyBackgroundJobItem::class, $itemRepository],
        ]);

        $lead = new Lead();
        (new ReflectionProperty(Lead::class, 'id'))->setValue($lead, 42);

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->never())->method('importOne');
        $importer->expects($this->once())->method('updateById')->with(42, $this->anything(), null)->willReturn($lead);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 77, 'mapping' => ['title' => 'title'], 'filters' => []]);

        (new ImportContactsFromJobHandler($importer, $this->createMock(ListModel::class), $em, $this->guard()))->processChunk($job);
    }

    public function testEnrichmentSourceRecordsFailureWhenLeadNoLongerExists(): void
    {
        $sourceItem = $this->sourceItem('999', ['title' => 'CEO']);

        $sourceJob = (new WittyBackgroundJob())->setType(ApolloBulkEnrichPeopleJobHandler::TYPE)->setLabel('L');

        $jobRepository = $this->createMock(WittyBackgroundJobRepository::class);
        $jobRepository->method('find')->willReturn($sourceJob);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('findForJob')->willReturn([$sourceItem]);

        $recorded = [];
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [WittyBackgroundJob::class, $jobRepository],
            [WittyBackgroundJobItem::class, $itemRepository],
        ]);
        $em->method('persist')->willReturnCallback(function ($entity) use (&$recorded): void {
            if ($entity instanceof WittyBackgroundJobItem) {
                $recorded[] = $entity;
            }
        });

        $importer = $this->createMock(ContactImporter::class);
        $importer->method('updateById')->willReturn(null);

        $job = (new WittyBackgroundJob())->setParams(['source_job_id' => 77, 'mapping' => ['title' => 'title'], 'filters' => []]);

        (new ImportContactsFromJobHandler($importer, $this->createMock(ListModel::class), $em, $this->guard()))->processChunk($job);

        $this->assertCount(1, $recorded);
        $this->assertSame(WittyBackgroundJobItem::STATUS_FAILED, $recorded[0]->getStatus());
    }

    private function sourceItem(string $ref, array $data): WittyBackgroundJobItem
    {
        return (new WittyBackgroundJobItem())->setExternalRef($ref)->setStatus(WittyBackgroundJobItem::STATUS_SUCCEEDED)->setData($data);
    }

    private function guard(): FieldWriteGuard
    {
        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('prepare')->willReturnCallback(
            static fn (array $fields): array => ['fields' => $fields, 'unknown' => []],
        );

        return $guard;
    }
}
