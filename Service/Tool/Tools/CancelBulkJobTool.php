<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Annule un job de fond en attente ou en cours (queued/running) — question
 * posee en session : l'agent n'avait aucun moyen d'arreter un job lance par
 * erreur ou dont l'utilisateur se retracte en cours de route, un vrai trou
 * malgre l'existence de WittyBackgroundJob::STATUS_CANCELLED depuis la
 * conception initiale du systeme (jamais atteignable faute d'outil).
 *
 * Ne fait qu'une chose, deliberement minimale, meme esprit que
 * ResumeBulkJobTool : repasser le job en STATUS_CANCELLED. Suffisant pour
 * l'arreter (WittyBackgroundJobRepository::findRunnable() n'interroge que
 * queued/running, un job cancelled n'est donc plus jamais repris par le
 * cron) sans rien detruire — resumeCursor et les WittyBackgroundJobItem deja
 * enregistres restent intacts :
 * - les resultats deja obtenus restent exploitables via
 *   start_contacts_import_from_job/start_companies_import_from_job (qui
 *   acceptent desormais aussi un job source cancelled, meme raisonnement que
 *   pour un job failed : succeeded_items > 0 = exploitable, quel que soit ce
 *   qui a arrete le job).
 * - si l'utilisateur se retracte une seconde fois ("en fait non, continue"),
 *   resume_bulk_job accepte desormais aussi un job cancelled (meme etat
 *   recuperable qu'un job failed, rien a reperdre).
 */
class CancelBulkJobTool extends AbstractTool
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'cancel_bulk_job';
    }

    public function getDescription(): string
    {
        return 'Annule un job de fond en attente ou en cours (status=queued ou running) — a utiliser si '
            .'l utilisateur se retracte ou si le job a ete lance par erreur. N efface rien : les resultats deja '
            .'obtenus restent exploitables via start_contacts_import_from_job/start_companies_import_from_job, et '
            .'resume_bulk_job peut reprendre le job plus tard si l utilisateur change a nouveau d avis.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'job_id' => ['type' => 'integer', 'description' => 'Job status=queued ou running a annuler.'],
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

        if (!in_array($job->getStatus(), [WittyBackgroundJob::STATUS_QUEUED, WittyBackgroundJob::STATUS_RUNNING], true)) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'Job #%d n est pas actif (status=%s) : seul un job queued ou running peut etre annule.',
                    $jobId,
                    $job->getStatus(),
                ),
            ];
        }

        $job->setStatus(WittyBackgroundJob::STATUS_CANCELLED);

        $this->entityManager->persist($job);
        $this->entityManager->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d annule (%d resultat(s) deja acquis conserves, jamais perdus). '
                .'Exploitable via start_contacts_import_from_job/start_companies_import_from_job, ou '
                .'resume_bulk_job(job_id=%d) pour reprendre plus tard.',
                $job->getId(),
                $job->getSucceededItems(),
                $job->getId(),
            ),
        ]);
    }
}
