<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\BulkCreateContactsTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * A la difference d'import_leads_from_file (email obligatoire dans le
 * mapping), un contact issu d'une recherche de prospects peut n'avoir aucun
 * email (ex. resultats Prospeo avant enrichissement) : le dedoublonnage ne
 * s'applique qu'aux entrees qui en fournissent un, les autres sont toujours
 * creees. C'est le cas qui merite un test dedie, en plus du plafond et du
 * rattachement optionnel a un segment.
 */
class BulkCreateContactsToolTest extends TestCase
{
    public function testEmptyContactsListIsRejected(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $output = $this->tool($leadModel, $this->createMock(ListModel::class), true)->execute(['contacts' => []]);

        $this->assertSame('error', $output['status']);
    }

    public function testTooManyContactsIsRejectedWithoutSaving(): void
    {
        $contacts = array_fill(0, 501, ['email' => 'x@example.test']);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $output = $this->tool($leadModel, $this->createMock(ListModel::class), true)->execute(['contacts' => $contacts]);

        $this->assertSame('error', $output['status']);
    }

    public function testCompletelyEmptyEntriesAreSkippedButOthersStillPreviewed(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $output = $this->tool($leadModel, $this->createMock(ListModel::class), true)->execute([
            'contacts' => [
                ['firstname' => 'Jane', 'company' => 'Acme'],
                ['firstname' => '', 'lastname' => ''],
                'pas-un-objet',
            ],
        ]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(1, $output['preview']['valid_count']);
        $this->assertSame(2, $output['preview']['invalid_count']);
    }

    public function testContactsWithoutEmailAreAlwaysCreatedNeverDeduplicated(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->expects($this->never())->method('findBy');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->exactly(2))->method('setFieldValues');
        $leadModel->expects($this->exactly(2))->method('saveEntity');

        $tool   = $this->tool($leadModel, $this->createMock(ListModel::class), false);
        $output = $tool->execute([
            'contacts' => [
                ['firstname' => 'Jane', 'company' => 'Acme'],
                ['firstname' => 'John', 'company' => 'Acme'],
            ],
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(2, $output['created']);
        $this->assertSame(0, $output['updated']);
    }

    public function testDuplicateEmailUpdatesInsteadOfCreating(): void
    {
        $existingLead = new Lead();

        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => 'existing@example.test' === $criteria['email'] ? [$existingLead] : [],
        );

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->exactly(2))->method('saveEntity');

        $tool   = $this->tool($leadModel, $this->createMock(ListModel::class), false);
        $output = $tool->execute([
            'contacts' => [
                ['email' => 'new@example.test'],
                ['email' => 'existing@example.test'],
            ],
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $output['created']);
        $this->assertSame(1, $output['updated']);
    }

    public function testUnknownSegmentIsRejected(): void
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->willReturn(null);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $output = $this->tool($leadModel, $listModel, false)->execute([
            'contacts'   => [['firstname' => 'Jane']],
            'segment_id' => 99,
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testContactsAreAddedToTheSegmentWhenProvided(): void
    {
        $segment = $this->createMock(LeadList::class);
        $segment->method('getId')->willReturn(7);
        $segment->method('getName')->willReturn('Prospects Q1');

        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->with(7)->willReturn($segment);
        $listModel->expects($this->exactly(2))->method('addLead')->with($this->isInstanceOf(Lead::class), [7], true);

        $tool   = $this->tool($leadModel, $listModel, false);
        $output = $tool->execute([
            'contacts'   => [['firstname' => 'Jane'], ['firstname' => 'John']],
            'segment_id' => 7,
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(2, $output['added_to_segment']);
    }

    public function testConfirmationPreviewNamesTheSegment(): void
    {
        $segment = $this->createMock(LeadList::class);
        $segment->method('getId')->willReturn(7);
        $segment->method('getName')->willReturn('Prospects Q1');

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->willReturn($segment);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $output = $this->tool($leadModel, $listModel, true)->execute([
            'contacts'   => [['firstname' => 'Jane']],
            'segment_id' => 7,
        ]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame('Prospects Q1', $output['preview']['segment']);
    }

    private function tool(LeadModel $leadModel, ListModel $listModel, bool $requiresConfirmation): BulkCreateContactsTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new BulkCreateContactsTool($leadModel, $listModel, $config);
    }
}
