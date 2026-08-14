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
    public function testAllowsMultiplePassesPerTick(): void
    {
        // Exception justifiee (cf. docblock de classe) au defaut "false" des
        // autres handlers a fournisseur externe : le debit Contact Finder
        // (120/minute) est precisement connu et auto-applique en interne
        // (throttle), donc plusieurs passages enchaines restent surs.
        $handler = new QuickenrichBulkSearchJobHandler($this->createMock(QuickenrichClient::class), $this->createMock(EntityManagerInterface::class));

        $this->assertTrue($handler->allowsMultiplePassesPerTick());
    }

    /**
     * Contrairement a QuickenrichBulkEnrichPeopleJobHandler (plusieurs appels
     * DANS un meme processChunk(), throttle par variable locale), celui-ci ne
     * fait qu'UN appel par processChunk() : le throttle doit donc survivre
     * d'un passage a l'autre via resumeCursor['last_call_at'] — c'est ce que
     * ce test verifie specifiquement, en rejouant deux passages successifs
     * sur le MEME job (comme le ferait le multi-passage reel).
     */
    public function testThrottlePersistsAcrossSuccessivePassesViaResumeCursor(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willReturn(['data' => array_fill(0, 100, [])]);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams(['body' => [], 'target_count' => 1000]);

        $handler = new QuickenrichBulkSearchJobHandler($client, $em);

        $start = microtime(true);
        $handler->processChunk($job); // 1er passage : jamais retarde (rien a attendre).
        $handler->processChunk($job); // 2e passage : doit attendre MIN_CALL_INTERVAL_SECONDS.
        $elapsed = microtime(true) - $start;

        // Marge sous les 550ms reels (limite Contact Finder), pour ne jamais
        // etre fragile sur une machine lente, tout en detectant sans
        // ambiguite une regression qui supprimerait le throttle.
        $this->assertGreaterThan(0.3, $elapsed);
        $this->assertArrayHasKey('last_call_at', $job->getResumeCursor());
    }

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
