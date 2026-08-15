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
 *
 * Un job normalement ne recoit qu'UN SEUL lot par passage de cron (un appel
 * processChunk()). Exception : un handler dont allowsMultiplePassesPerTick()
 * renvoie true (aucun appel a une API externe a debit limite — aujourd'hui
 * uniquement les deux handlers d'import, purs ecrits Mautic) peut enchainer
 * plusieurs lots sur le MEME job tant qu'il reste du budget de temps sur ce
 * passage, au lieu d'un seul. Question posee en session : "50 imports/minute
 * c'est trop peu, pourquoi ?" — reponse : ce n'est pas une limite arbitraire,
 * chaque lot passe par LeadModel::saveEntity() (campagnes/segments/points/
 * recherche, cout hors de notre controle), donc un lot plus gros risquerait
 * de depasser MAX_RUNTIME_SECONDS sans aucun moyen de l'interrompre en cours
 * de route. Mautic lui-meme (LeadBundle\Model\ImportModel::process(), import
 * CSV) n'a AUCUNE coupure de temps — il tourne jusqu'a la fin du fichier en
 * un seul appel CLI, ce qui marche parce que mautic:import est dedie a UN
 * import a la fois, jamais partage avec d'autres types de taches. Le
 * multi-passage reprend ce meme principe (avancer tant qu'il y a du travail)
 * mais borne au budget commun du cron partage par tous les types de job.
 *
 * Consequence du multi-passage sur le risque de chevauchement (constate en
 * production : "EntityManagerClosed") : un passage peut desormais rester en
 * vie jusqu'a MAX_RUNTIME_SECONDS au lieu de quasi instantane, ce qui rend
 * un chevauchement de deux passages de cron (sans "prevent overlapping" cote
 * ordonnanceur) bien plus probable qu'avant — et un deadlock MySQL entre les
 * deux (deux processus qui touchent la meme ligne WittyBackgroundJob) ferme
 * l'EntityManager cote Doctrine, rendant tout persist()/flush() ulterieur
 * dans CE processus impossible. persistAndFlush() detecte ce cas
 * explicitement (isOpen() + try/catch) et arrete proprement ce passage
 * plutot que de laisser une exception non rattrapee planter toute la
 * commande (et perdre le suivi des jobs restants avec) — mais ne supprime
 * pas le risque de depart : `flock -n /tmp/witty-jobs-process.lock php
 * bin/console witty:jobs:process` dans la ligne de cron (ou l'option
 * equivalente de l'ordonnanceur) reste la vraie protection.
 */
#[AsCommand(name: 'witty:jobs:process', description: 'Fait avancer les jobs de fond (enrichissement/recherche a volume) en attente.')]
class ProcessBackgroundJobsCommand extends Command
{
    private const MAX_RUNTIME_SECONDS = 50.0;

    private const MAX_JOBS_PER_RUN = 200;

    /** Filet de securite : borne deja par le budget de temps, mais evite une boucle degenree qui ne progresserait pas. */
    private const MAX_PASSES_PER_JOB = 500;

    public function __construct(
        private JobHandlerRegistry $handlers,
        private WittyBackgroundJobRepository $repository,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
        // Overridable UNIQUEMENT pour les tests (verifier l'effet d'un budget
        // de temps epuise en cours de run sans attendre 50 secondes reelles) :
        // jamais fourni en production, ou la constante fait foi.
        private ?float $maxRuntimeSeconds = null,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $deadline = microtime(true) + ($this->maxRuntimeSeconds ?? self::MAX_RUNTIME_SECONDS);
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

            $multiPass = true === $this->handlers->get($job->getType())?->allowsMultiplePassesPerTick();
            $passes    = 0;

            do {
                $ok = $this->tick($job, $output);
                ++$ticked;
                ++$passes;

                if (!$ok) {
                    // L'EntityManager est ferme (Doctrine se ferme lui-meme
                    // suite a une erreur irrecuperable — deadlock, contrainte
                    // violee, connexion perdue... constate en production avec
                    // "EntityManagerClosed") : plus AUCUNE operation Doctrine
                    // ne peut aboutir dans ce processus, continuer ne ferait
                    // que repeter la meme erreur sur chaque job restant.
                    // On s'arrete net ; le prochain passage de cron reprend
                    // dans un processus neuf (EntityManager frais) depuis le
                    // dernier etat connu en base de chaque job.
                    $output->writeln('EntityManager ferme suite a une erreur Doctrine irrecuperable, arret de ce passage de cron.');

                    return Command::FAILURE;
                }
            } while (
                $multiPass
                && $passes < self::MAX_PASSES_PER_JOB
                && microtime(true) < $deadline
                && $this->isStillActive($job)
            );
        }

        return Command::SUCCESS;
    }

    private function isStillActive(WittyBackgroundJob $job): bool
    {
        return in_array($job->getStatus(), [WittyBackgroundJob::STATUS_QUEUED, WittyBackgroundJob::STATUS_RUNNING], true);
    }

    /**
     * @return bool false si l'EntityManager vient de se fermer (Doctrine se
     *              ferme lui-meme suite a une erreur irrecuperable) — dans ce
     *              cas, l'appelant doit arreter tout le passage de cron plutot
     *              que d'enchainer d'autres tick() voues au meme echec.
     */
    private function tick(WittyBackgroundJob $job, OutputInterface $output): bool
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

            if (!$this->persistAndFlush($job)) {
                return false;
            }

            $output->writeln(sprintf('Job #%d (%s) : type de handler introuvable.', $job->getId(), $job->getType()));

            return true;
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

        if (!$this->persistAndFlush($job)) {
            return false;
        }

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

        return true;
    }

    /**
     * Constate en production ("EntityManagerClosed") : ce persist()/flush()
     * final n'etait jusque-la protege par AUCUN try/catch, ni celui autour de
     * processChunk() (deja termine a ce stade), ni un autre dans execute()
     * (qui appelait tick() sans le proteger non plus) — une erreur Doctrine
     * ici (deadlock entre deux passages de cron qui se chevauchent, connexion
     * perdue...) remontait donc telle quelle et faisait planter TOUTE la
     * commande avec une trace brute, perdant le suivi des jobs restants.
     * isOpen() est verifie explicitement en plus du try/catch : Doctrine peut
     * fermer l'EntityManager sans que l'exception d'origine soit celle qui
     * remonte jusqu'ici (ex. fermee par une operation anterieure dans
     * processChunk() qui a elle-meme ete avalee par son propre try/catch).
     */
    private function persistAndFlush(WittyBackgroundJob $job): bool
    {
        if (!$this->em->isOpen()) {
            $this->logger->critical('Witty : EntityManager deja ferme avant de sauvegarder l etat du job.', ['job_id' => $job->getId()]);

            return false;
        }

        try {
            $this->em->persist($job);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->critical('Witty : echec de sauvegarde de l etat du job (EntityManager probablement ferme).', [
                'job_id'    => $job->getId(),
                'exception' => $e,
            ]);

            return false;
        }

        return true;
    }
}
