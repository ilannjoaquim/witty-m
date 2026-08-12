<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Fait avancer d'un lot chaque job de fond en attente (WittyBackgroundJob) :
 * un enrichissement/une recherche a volume (des milliers de contacts) ne peut
 * pas tenir dans un seul tour de chat (cf. AgentRunner::run(), tout dans une
 * seule requete HTTP), donc se decoupe en petits lots successifs traites ici,
 * au fil des passages de cron.
 *
 * A executer frequemment via le cron systeme (comme witty:meet:reconcile-attendance,
 * Mautic n'a pas de planificateur interne) :
 *   * * * * * php bin/console witty:jobs:process
 *
 * Volontairement borne en temps (MAX_RUNTIME_SECONDS) plutot qu'en nombre de
 * jobs : meme avec beaucoup de jobs en attente, une execution reste courte et
 * previsible, jamais de quoi chevaucher le passage de cron suivant. Aucun
 * verrou explicite (contrairement a certains systemes de jobs) : un
 * chevauchement re-traiterait au pire un lot deja fait (idempotent cote
 * handler par construction, cf. JobHandlerInterface), jamais de corruption —
 * le meme choix que le reste du plugin (cf. les autres Command/, aucune
 * n'utilise de verrou).
 */
#[AsCommand(name: 'witty:jobs:process', description: 'Fait avancer les jobs de fond (enrichissement/recherche a volume) en attente.')]
class ProcessBackgroundJobsCommand extends Command
{
    private const MAX_RUNTIME_SECONDS = 50.0;

    private const MAX_JOBS_PER_RUN = 200;

    public function __construct(
        private JobHandlerRegistry $handlers,
        private WittyBackgroundJobRepository $repository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = microtime(true) + self::MAX_RUNTIME_SECONDS;
        $jobs     = $this->repository->findRunnable(self::MAX_JOBS_PER_RUN);

        if ([] === $jobs) {
            $output->writeln('Aucun job de fond en attente.');

            return Command::SUCCESS;
        }

        $ticked = 0;

        foreach ($jobs as $job) {
            if (microtime(true) >= $deadline) {
                $output->writeln(sprintf('Budget de temps ecoule, %d job(s) traite(s) sur %d en attente.', $ticked, count($jobs)));
                break;
            }

            $this->tick($job, $output);
            ++$ticked;
        }

        return Command::SUCCESS;
    }

    private function tick(WittyBackgroundJob $job, OutputInterface $output): void
    {
        $handler = $this->handlers->get($job->getType());

        if (null === $handler) {
            // Ne devrait pas arriver (le type est fige a la creation par un
            // outil qui connait la liste des handlers disponibles), mais un
            // plugin desinstalle/une regression ne doit pas boucler indefiniment
            // sur un job qu'aucun handler ne sait plus traiter.
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)
                ->setErrorMessage(sprintf('Type de job inconnu : %s', $job->getType()))
                ->setLastTickAt(new \DateTimeImmutable());
            $this->em->persist($job);
            $this->em->flush();

            $output->writeln(sprintf('Job #%d (%s) : type de handler introuvable.', $job->getId(), $job->getType()));

            return;
        }

        if (WittyBackgroundJob::STATUS_QUEUED === $job->getStatus()) {
            $job->setStatus(WittyBackgroundJob::STATUS_RUNNING)->setDateStarted(new \DateTimeImmutable());
        }

        try {
            $handler->processChunk($job);
        } catch (\Throwable $e) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());
            $this->logger->error('Witty : job de fond en echec.', [
                'job_id'    => $job->getId(),
                'type'      => $job->getType(),
                'exception' => $e,
            ]);
        }

        $job->setLastTickAt(new \DateTimeImmutable());

        if (WittyBackgroundJob::STATUS_COMPLETED === $job->getStatus() && null === $job->getDateCompleted()) {
            $job->setDateCompleted(new \DateTimeImmutable());
        }

        $this->em->persist($job);
        $this->em->flush();

        $output->writeln(sprintf(
            'Job #%d (%s) : %s — %d/%s traites (%d ok, %d echecs).',
            $job->getId(),
            $job->getType(),
            $job->getStatus(),
            $job->getProcessedItems(),
            null !== $job->getTotalItems() ? (string) $job->getTotalItems() : '?',
            $job->getSucceededItems(),
            $job->getFailedItems(),
        ));
    }
}
