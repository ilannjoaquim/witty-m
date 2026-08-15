<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Command\ProcessBackgroundJobsCommand;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerRegistry;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Un seul job a la fois ne suffit pas a couvrir le multi-passage : le vrai
 * risque est qu'un job multi-passages (import) affame les autres jobs en
 * attente dans le MEME passage de cron, ou a l'inverse ne rende jamais la
 * main une fois termine. Couvert ici :
 * - un job multi-passages qui termine vite laisse bien la main a la suite de
 *   la liste dans le MEME passage (pas besoin d'attendre le prochain cron) ;
 * - deux jobs multi-passages independants avancent bien chacun le leur, pas
 *   de compteur partage par erreur entre deux jobs du meme type ;
 * - un budget de temps epuise EN COURS d'un job multi-passages empeche bien
 *   les jobs suivants d'etre touches sur CE passage (le prochain cron les
 *   reprendra en priorite, cf. WittyBackgroundJobRepository::findRunnable(),
 *   trie par dernier traitement) — verifie avec un budget reellement tres
 *   court plutot que suppose, via le parametre de test dedie de la commande.
 */
class ProcessBackgroundJobsCommandTest extends TestCase
{
    public function testMultiPassHandlerIsCalledRepeatedlyUntilJobBecomesTerminal(): void
    {
        $job = $this->job('import_contacts_from_job', 'A');

        $handler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 3);

        $this->runCommand([$job], [$handler]);

        $this->assertSame(3, $handler->callsFor($job));
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testSinglePassHandlerIsCalledOnlyOnceEvenIfStillRunning(): void
    {
        $job = $this->job('quickenrich_bulk_search_contacts', 'A');

        // completeAfterCallsPerJob tres eleve : ce job ne devient jamais
        // terminal (simule une recherche qui a encore des pages a paginer).
        // Si le handler etait a tort rappele plusieurs fois, ce test le
        // detecterait (callsFor($job) > 1).
        $handler = new RecordingJobHandler('quickenrich_bulk_search_contacts', false, completeAfterCallsPerJob: 999);

        $this->runCommand([$job], [$handler]);

        $this->assertSame(1, $handler->callsFor($job));
        $this->assertSame(WittyBackgroundJob::STATUS_RUNNING, $job->getStatus());
    }

    public function testAFastMultiPassJobStillLeavesRoomForTheNextJobInTheSameRun(): void
    {
        $importJob  = $this->job('import_contacts_from_job', 'A');
        $searchJob  = $this->job('quickenrich_bulk_search_contacts', 'B');

        $importHandler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 2);
        $searchHandler = new RecordingJobHandler('quickenrich_bulk_search_contacts', false, completeAfterCallsPerJob: 999);

        // Ordre de findRunnable() : importJob en premier (comme trie par
        // lastTickAt), donc le job multi-passages est traite avant l'autre.
        $this->runCommand([$importJob, $searchJob], [$importHandler, $searchHandler]);

        $this->assertSame(2, $importHandler->callsFor($importJob));
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $importJob->getStatus());
        // Le point verifie ici : searchJob n'est pas laisse de cote juste
        // parce qu'il est apres un job multi-passages dans la liste.
        $this->assertSame(1, $searchHandler->callsFor($searchJob));
    }

    public function testTwoIndependentMultiPassJobsEachAdvanceOnTheirOwnCounter(): void
    {
        $jobA = $this->job('import_contacts_from_job', 'A');
        $jobB = $this->job('import_contacts_from_job', 'B');

        // Meme type, donc le MEME handler traite les deux : le point verifie
        // ici est qu'il ne confond jamais leurs etats respectifs (pas de
        // compteur scalaire partage entre jobs, cf. RecordingJobHandler).
        $handler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 3);

        $this->runCommand([$jobA, $jobB], [$handler]);

        $this->assertSame(3, $handler->callsFor($jobA));
        $this->assertSame(3, $handler->callsFor($jobB));
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $jobA->getStatus());
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $jobB->getStatus());
    }

    public function testExhaustedTimeBudgetStopsBeforeTouchingTheNextJob(): void
    {
        $slowJob  = $this->job('import_contacts_from_job', 'A');
        $otherJob = $this->job('import_contacts_from_job', 'B');

        // Chaque appel dort reellement 30ms ; budget de 10ms accorde a la
        // commande (parametre de test dedie) : des le premier passage sur
        // slowJob, le budget est deja depasse, otherJob ne doit jamais etre
        // touche sur CE passage.
        $handler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 999, sleepSecondsPerCall: 0.03);

        $this->runCommand([$slowJob, $otherJob], [$handler], maxRuntimeSeconds: 0.01);

        $this->assertGreaterThanOrEqual(1, $handler->callsFor($slowJob));
        $this->assertSame(0, $handler->callsFor($otherJob));
    }

    /**
     * Bug reel constate en production ("EntityManagerClosed") : le
     * persist()/flush() final de tick() n'etait protege par AUCUN try/catch,
     * ni celui autour de processChunk() (deja termine a ce stade), ni un
     * autre dans execute() — une erreur Doctrine qui ferme l'EntityManager
     * (deadlock entre deux passages de cron qui se chevauchent, plus probable
     * depuis le multi-passage qui allonge la duree de vie d'un passage)
     * remontait donc telle quelle et plantait TOUTE la commande. Ce test
     * verifie que ce n'est plus le cas : la commande se termine proprement
     * (Command::FAILURE, jamais une exception non rattrapee qui remonterait
     * jusqu'ici).
     */
    public function testEntityManagerClosedStopsTheRunGracefullyInsteadOfCrashing(): void
    {
        $job     = $this->job('import_contacts_from_job', 'A');
        $handler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 999);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('findRunnable')->willReturn([$job]);

        $em = $this->createMock(EntityManagerInterface::class);
        // Deja ferme AVANT meme le premier persist()/flush() de tick() :
        // simule une fermeture survenue plus tot dans le meme processus
        // (ex. un job precedent dans la meme boucle multi-passages).
        $em->method('isOpen')->willReturn(false);

        $command = new ProcessBackgroundJobsCommand(
            new JobHandlerRegistry([$handler]),
            $repository,
            $em,
            $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
        // Un seul tick tente : la boucle s'est bien arretee au premier signal
        // d'EntityManager ferme, pas apres avoir enchaine d'autres passages
        // voues au meme echec.
        $this->assertSame(1, $handler->callsFor($job));
    }

    public function testFlushExceptionStopsTheRunGracefullyInsteadOfCrashing(): void
    {
        $job     = $this->job('import_contacts_from_job', 'A');
        $handler = new RecordingJobHandler('import_contacts_from_job', true, completeAfterCallsPerJob: 999);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('findRunnable')->willReturn([$job]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);
        $em->method('flush')->willThrowException(new \RuntimeException('The EntityManager is closed.'));

        $command = new ProcessBackgroundJobsCommand(
            new JobHandlerRegistry([$handler]),
            $repository,
            $em,
            $this->createMock(LoggerInterface::class),
        );

        $tester = new CommandTester($command);
        $tester->execute([]);

        $this->assertSame(Command::FAILURE, $tester->getStatusCode());
    }

    private function job(string $type, string $label): WittyBackgroundJob
    {
        return (new WittyBackgroundJob())->setType($type)->setLabel($label)->setStatus(WittyBackgroundJob::STATUS_QUEUED);
    }

    /**
     * @param WittyBackgroundJob[]   $jobs
     * @param JobHandlerInterface[]  $handlers
     */
    private function runCommand(array $jobs, array $handlers, ?float $maxRuntimeSeconds = null): void
    {
        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('findRunnable')->willReturn($jobs);

        // isOpen() sans stub renverrait null (falsy) : persistAndFlush()
        // traiterait alors chaque tick() comme un EntityManager deja ferme,
        // arretant tout le passage des le premier job — jamais le cas dans
        // ces tests, ou l'on verifie la boucle multi-passages elle-meme.
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('isOpen')->willReturn(true);

        $command = new ProcessBackgroundJobsCommand(
            new JobHandlerRegistry($handlers),
            $repository,
            $em,
            $this->createMock(LoggerInterface::class),
            $maxRuntimeSeconds,
        );

        (new CommandTester($command))->execute([]);
    }
}

/**
 * Handler de test qui suit ses appels PAR JOB (spl_object_id), jamais un
 * compteur scalaire unique : indispensable des qu'un test met en jeu
 * plusieurs jobs du meme type traites par la meme instance de handler.
 */
class RecordingJobHandler implements JobHandlerInterface
{
    /** @var array<int, int> spl_object_id(job) -> nombre d'appels */
    private array $callsByJob = [];

    public function __construct(
        private string $type,
        private bool $multiPass,
        private int $completeAfterCallsPerJob,
        private float $sleepSecondsPerCall = 0.0,
    ) {
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        return $this->multiPass;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        if ($this->sleepSecondsPerCall > 0.0) {
            usleep((int) ($this->sleepSecondsPerCall * 1_000_000));
        }

        $id                     = spl_object_id($job);
        $this->callsByJob[$id]  = ($this->callsByJob[$id] ?? 0) + 1;

        if ($this->callsByJob[$id] >= $this->completeAfterCallsPerJob) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    public function callsFor(WittyBackgroundJob $job): int
    {
        return $this->callsByJob[spl_object_id($job)] ?? 0;
    }
}
