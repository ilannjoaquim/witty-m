<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyBackgroundJobItem>
 */
class WittyBackgroundJobItemRepository extends CommonRepository
{
    /**
     * Page de resultats d'un job, pour list_bulk_job_items : a l'echelle de
     * 50 000 elements, l'agent doit pouvoir en revoir un lot borne a la fois
     * plutot que de tout recevoir d'un coup (budget de contexte du modele).
     *
     * $onlyUnconsumed : utilise par ImportContactsFromJobHandler/
     * ImportCompaniesFromJobHandler (jamais par list_bulk_job_items, qui doit
     * pouvoir montrer TOUT l'historique) pour ne relire que ce qu'un import
     * precedent du meme job source n'a pas deja transmis a Mautic — cf.
     * WittyBackgroundJobItem::$consumedAt.
     *
     * @return WittyBackgroundJobItem[]
     */
    public function findForJob(int $jobId, ?string $status, int $limit, int $offset, bool $onlyUnconsumed = false): array
    {
        $qb = $this->createQueryBuilder('i')
            ->where('IDENTITY(i.job) = :jobId')
            ->setParameter('jobId', $jobId)
            ->orderBy('i.id', 'ASC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        if ($onlyUnconsumed) {
            $qb->andWhere('i.consumedAt IS NULL');
        }

        return $qb->getQuery()->getResult();
    }

    public function countForJob(int $jobId, ?string $status = null, bool $onlyUnconsumed = false): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('IDENTITY(i.job) = :jobId')
            ->setParameter('jobId', $jobId);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        if ($onlyUnconsumed) {
            $qb->andWhere('i.consumedAt IS NULL');
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
