<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\QuickenrichBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use PHPUnit\Framework\TestCase;

/**
 * Ce qui merite un test dedie ici, au-dela du simple relais vers
 * QuickenrichClient : la logique de fin de pagination — une page pleine sous
 * la cible continue, une page partielle ou vide termine meme sous la cible
 * (fin reelle des resultats cote QuickEnrich), et atteindre exactement
 * target_count termine aussi meme sur une page pleine.
 */
class QuickenrichBulkSearchJobHandlerTest extends TestCase
{
    public function testFullPageUnderTargetKeepsJobRunning(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willReturn(['data' => array_fill(0, 100, ['first_name' => 'X'])]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(100))->method('persist');

        $job = (new WittyBackgroundJob())->setParams(['body' => ['has_email' => true], 'target_count' => 250]);

        (new QuickenrichBulkSearchJobHandler($client, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_QUEUED, $job->getStatus());
        $this->assertSame(100, $job->getProcessedItems());
        $this->assertSame(2, $job->getResumeCursor()['page']);
    }

    public function testPartialPageCompletesEvenUnderTarget(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willReturn(['data' => array_fill(0, 30, [])]);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams(['body' => [], 'target_count' => 250]);

        (new QuickenrichBulkSearchJobHandler($client, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testReachingTargetCountCompletesEvenOnAFullPage(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willReturn(['data' => array_fill(0, 100, [])]);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams(['body' => [], 'target_count' => 100]);

        (new QuickenrichBulkSearchJobHandler($client, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testExceptionFailsTheJobWithoutPersistingAnything(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willThrowException(new QuickenrichException('QuickEnrich (HTTP 429) : quota'));

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $job = (new WittyBackgroundJob())->setParams(['body' => [], 'target_count' => 100]);

        (new QuickenrichBulkSearchJobHandler($client, $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
        $this->assertStringContainsString('quota', (string) $job->getErrorMessage());
    }
}
