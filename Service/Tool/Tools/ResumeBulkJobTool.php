<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Relance un job de fond FAILED ou CANCELLED exactement la ou il s'est
 * arrete, sans repartir de zero.
 *
 * Pourquoi c'est possible sans le moindre changement aux handlers : chaque
 * handler (Apollo/QuickEnrich/MCP) n'avance resumeCursor qu'APRES un appel
 * fournisseur reussi (cf. leurs docblocks respectifs) — une erreur 500/timeout
 * en cours de route fait juste passer le job en STATUS_FAILED SANS toucher au
 * curseur, qui reste donc a la derniere position confirmee. Un job CANCELLED
 * (cf. CancelBulkJobTool) est exactement dans le meme etat recuperable : rien
 * ne touche resumeCursor a l'annulation non plus. Le seul obstacle a la
 * reprise est que ProcessBackgroundJobsCommand::findRunnable() n'interroge
 * que QUEUED/RUNNING : un job FAILED/CANCELLED n'est plus jamais repris par le
 * cron, meme si son curseur est parfaitement exploitable. Cet outil ne fait
 * donc qu'une chose : repasser le job en QUEUED (jamais toucher
 * resumeCursor/succeeded_items/les items deja enregistres) pour que le
 * prochain passage de cron le reprenne de lui-meme via son propre
 * resumeCursor, comme n'importe quel tick normal.
 *
 * Plafonne (MAX_RESUME_ATTEMPTS) : un fournisseur en panne prolongee ne doit
 * pas transformer ce mecanisme en boucle infinie de tentatives — passe le
 * plafond, l'outil refuse et renvoie le dernier message d'erreur pour que
 * l'agent/l'utilisateur investigue plutot que de reessayer aveuglement. Un
 * job CANCELLED n'a pas d'error_message a proprement parler (rien n'a
 * echoue), le plafond s'applique quand meme par coherence — un job
 * annule/relance en boucle merite la meme prudence.
 */
class ResumeBulkJobTool extends AbstractTool
{
    public const MAX_RESUME_ATTEMPTS = 5;

    private const RESUMABLE_STATUSES = [WittyBackgroundJob::STATUS_FAILED, WittyBackgroundJob::STATUS_CANCELLED];

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'resume_bulk_job';
    }

    public function getDescription(): string
    {
        return 'Relance un job de fond status=failed OU status=cancelled exactement la ou il s en est arrete '
            .'(curseur de reprise interne intact, rien n est reperdu ni refait) plutot que de relancer toute la '
            .'recherche/l enrichissement depuis le debut. Deux cas d usage : un check_bulk_job qui montre '
            .'status=failed avec succeeded_items > 0 et un error_message qui ressemble a un incident ponctuel du '
            .'fournisseur (erreur 500, timeout) plutot qu a un probleme de configuration durable ; ou un job '
            .'status=cancelled (cf. cancel_bulk_job) que l utilisateur souhaite finalement reprendre. Plafonne a '
            .self::MAX_RESUME_ATTEMPTS.' tentatives par job : au-dela, ne reessaie pas aveuglement, previens '
            .'l utilisateur.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'job_id' => ['type' => 'integer', 'description' => 'Job status=failed ou status=cancelled a relancer.'],
        ], ['job_id']);
    }

    public function execute(array $arguments): array
    {
        $jobId = (int) ($arguments['job_id'] ?? 0);
        $user  = $this->userHelper->getUser();
        $job   = $this->entityManager->getRepository(WittyBackgroundJob::class)->find($jobId);

        if (!$job instanceof WittyBackgroundJob || $job->getCreatedBy()?->getId() !== $user->getId()) {
            return ['status' => 'error', 'error' => sprintf('Job #%d introuvable.', $jobId)];
        }

        if (!in_array($job->getStatus(), self::RESUMABLE_STATUSES, true)) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'Job #%d n est ni en echec ni annule (status=%s) : seul un job failed ou cancelled peut etre relance, un job queued/running avance deja tout seul.',
                    $jobId,
                    $job->getStatus(),
                ),
            ];
        }

        if ($job->getResumeCount() >= self::MAX_RESUME_ATTEMPTS) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'Job #%d a deja ete relance %d fois sans succes (plafond atteint). Dernier message d erreur : %s',
                    $jobId,
                    $job->getResumeCount(),
                    $job->getErrorMessage() ?? 'non precise',
                ),
            ];
        }

        $lastError = $job->getErrorMessage();

        $job->setStatus(WittyBackgroundJob::STATUS_QUEUED)
            ->setErrorMessage(null)
            ->setResumeCount($job->getResumeCount() + 1);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->ok([
            'job_id'       => $job->getId(),
            'resume_count' => $job->getResumeCount(),
            'last_error'   => $lastError,
            'message'      => sprintf(
                'Job #%d repasse en file d attente, reprendra au prochain passage de cron exactement ou il s est '
                .'arrete (%d resultat(s) deja acquis conserves). Utilise check_bulk_job(job_id=%d) pour suivre.',
                $job->getId(),
                $job->getSucceededItems(),
                $job->getId(),
            ),
        ]);
    }
}
