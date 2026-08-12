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
     * @return WittyBackgroundJobItem[]
     */
    public function findForJob(int $jobId, ?string $status, int $limit, int $offset): array
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

        return $qb->getQuery()->getResult();
    }

    public function countForJob(int $jobId, ?string $status = null): int
    {
        $qb = $this->createQueryBuilder('i')
            ->select('COUNT(i.id)')
            ->where('IDENTITY(i.job) = :jobId')
            ->setParameter('jobId', $jobId);

        if (null !== $status) {
            $qb->andWhere('i.status = :status')->setParameter('status', $status);
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }
}
