<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyBackgroundJob>
 */
class WittyBackgroundJobRepository extends CommonRepository
{
    /**
     * Jobs a faire avancer par Command/ProcessBackgroundJobsCommand.php : en
     * attente ou deja en cours, jamais termines/en echec/annules. Tries par
     * dernier traitement (les jamais-touches, last_tick_at NULL, en premier)
     * pour qu'un job recent ne monopolise pas indefiniment les passages de
     * cron au detriment d'un job plus ancien.
     *
     * @return WittyBackgroundJob[]
     */
    public function findRunnable(int $limit): array
    {
        return $this->createQueryBuilder('j')
            ->where('j.status IN (:statuses)')
            ->setParameter('statuses', [WittyBackgroundJob::STATUS_QUEUED, WittyBackgroundJob::STATUS_RUNNING])
            ->orderBy('j.lastTickAt', 'ASC')
            ->addOrderBy('j.dateAdded', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Historique recent d'un utilisateur (le plus recent en premier), pour
     * check_bulk_job quand aucun job_id n'est fourni. Scope par utilisateur,
     * meme raisonnement que WittyApolloWaterfallRequestRepository::findRecentForUser().
     *
     * @return WittyBackgroundJob[]
     */
    public function findRecentForUser(int $userId, ?string $status, int $limit): array
    {
        $qb = $this->createQueryBuilder('j')
            ->where('IDENTITY(j.createdBy) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('j.dateAdded', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('j.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }
}
