<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyAuditLog>
 */
class WittyAuditLogRepository extends CommonRepository
{
    /**
     * @return WittyAuditLog[]
     */
    public function findRecent(int $limit = 100, ?int $userId = null, ?string $tool = null): array
    {
        $qb = $this->createQueryBuilder('a')
            ->orderBy('a.dateAdded', 'DESC')
            ->setMaxResults(max(1, min(500, $limit)));

        if (null !== $userId) {
            $qb->andWhere('IDENTITY(a.user) = :userId')->setParameter('userId', $userId);
        }

        if (null !== $tool && '' !== $tool) {
            $qb->andWhere('a.tool = :tool')->setParameter('tool', $tool);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Nombre d'ecritures reellement effectuees par outil, pour la page d'audit.
     *
     * @return array<int, array{tool: string, total: int}>
     */
    public function countWritesByTool(\DateTimeInterface $since): array
    {
        return $this->createQueryBuilder('a')
            ->select('a.tool AS tool, COUNT(a.id) AS total')
            ->where('a.writeOperation = :write')
            ->andWhere('a.status = :status')
            ->andWhere('a.dateAdded >= :since')
            ->setParameter('write', true)
            ->setParameter('status', WittyAuditLog::STATUS_OK)
            ->setParameter('since', $since)
            ->groupBy('a.tool')
            ->orderBy('total', 'DESC')
            ->getQuery()
            ->getResult();
    }
}
