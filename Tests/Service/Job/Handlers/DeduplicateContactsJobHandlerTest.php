<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Deduplicate\ContactMerger;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\DeduplicateContactsJobHandler;
use PHPUnit\Framework\TestCase;

/**
 * Verifie le CABLAGE du handler (quel contact est traite comme gagnant,
 * comment un contact deja parti est gere, comment le curseur/le statut
 * avancent) -- ContactMerger lui-meme est du code coeur Mautic deja fiable,
 * mocke ici plutot que reexecute.
 */
class DeduplicateContactsJobHandlerTest extends TestCase
{
    private function lead(int $id): Lead
    {
        $lead = new Lead();
        $lead->setId($id);

        return $lead;
    }

    public function testFirstIdOfEachGroupIsTheWinnerTheRestAreMergedIntoIt(): void
    {
        $winner = $this->lead(10);
        $loser1 = $this->lead(11);
        $loser2 = $this->lead(12);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturnMap([
            [10, $winner],
            [11, $loser1],
            [12, $loser2],
        ]);

        $merger = $this->createMock(ContactMerger::class);
        $merger->expects($this->exactly(2))
            ->method('merge')
            ->with($winner, $this->logicalOr($loser1, $loser2))
            ->willReturn($winner);

        $handler = new DeduplicateContactsJobHandler($leadModel, $merger, $this->createMock(EntityManagerInterface::class));

        $job = (new WittyBackgroundJob())->setParams(['groups' => [[10, 11, 12]]]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
        $this->assertSame(2, $job->getSucceededItems());
        $this->assertSame(2, $job->getProcessedItems());
    }

    public function testMissingWinnerSkipsTheWholeGroupWithoutCallingMerge(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn(null);

        $merger = $this->createMock(ContactMerger::class);
        $merger->expects($this->never())->method('merge');

        $handler = new DeduplicateContactsJobHandler($leadModel, $merger, $this->createMock(EntityManagerInterface::class));

        $job = (new WittyBackgroundJob())->setParams(['groups' => [[10, 11, 12]]]);
        $handler->processChunk($job);

        $this->assertSame(2, $job->getFailedItems());
        $this->assertSame(0, $job->getSucceededItems());
    }

    public function testMissingLoserIsSkippedButOtherLosersInTheSameGroupStillMerge(): void
    {
        $winner = $this->lead(10);
        $loser2 = $this->lead(12);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturnMap([
            [10, $winner],
            [11, null], // deja fusionne/supprime avant ce chunk
            [12, $loser2],
        ]);

        $merger = $this->createMock(ContactMerger::class);
        $merger->expects($this->once())->method('merge')->with($winner, $loser2)->willReturn($winner);

        $handler = new DeduplicateContactsJobHandler($leadModel, $merger, $this->createMock(EntityManagerInterface::class));

        $job = (new WittyBackgroundJob())->setParams(['groups' => [[10, 11, 12]]]);
        $handler->processChunk($job);

        $this->assertSame(1, $job->getSucceededItems());
        $this->assertSame(1, $job->getFailedItems());
    }

    public function testAMergeExceptionForOnePairDoesNotStopTheRestOfTheJob(): void
    {
        $winner = $this->lead(10);
        $loser1 = $this->lead(11);
        $loser2 = $this->lead(12);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturnMap([
            [10, $winner],
            [11, $loser1],
            [12, $loser2],
        ]);

        $merger = $this->createMock(ContactMerger::class);
        $merger->method('merge')->willReturnCallback(function (Lead $w, Lead $l) use ($loser1) {
            if ($l === $loser1) {
                throw new \RuntimeException('boom');
            }

            return $w;
        });

        $handler = new DeduplicateContactsJobHandler($leadModel, $merger, $this->createMock(EntityManagerInterface::class));

        $job = (new WittyBackgroundJob())->setParams(['groups' => [[10, 11, 12]]]);
        $handler->processChunk($job);

        $this->assertSame(1, $job->getSucceededItems());
        $this->assertSame(1, $job->getFailedItems());
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testResumeCursorAdvancesAndJobStaysRunningWhenMoreGroupsRemain(): void
    {
        // 21 groupes de 2 ids : au-dela de BATCH_SIZE (20), le job doit rester
        // running et le curseur avancer de 20, pas de 21.
        $groups = [];
        for ($i = 0; $i < 21; ++$i) {
            $groups[] = [1000 + $i, 2000 + $i];
        }

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturnCallback(fn (int $id) => $this->lead($id));

        $merger = $this->createMock(ContactMerger::class);
        $merger->method('merge')->willReturnArgument(0);

        $handler = new DeduplicateContactsJobHandler($leadModel, $merger, $this->createMock(EntityManagerInterface::class));

        $job = (new WittyBackgroundJob())->setParams(['groups' => $groups]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_RUNNING, $job->getStatus());
        $this->assertSame(20, $job->getResumeCursor()['offset']);
    }

    public function testEmptyGroupsListCompletesImmediately(): void
    {
        $handler = new DeduplicateContactsJobHandler(
            $this->createMock(LeadModel::class),
            $this->createMock(ContactMerger::class),
            $this->createMock(EntityManagerInterface::class),
        );

        $job = (new WittyBackgroundJob())->setParams(['groups' => []]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }
}
