<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyConversation>
 */
class WittyConversationRepository extends CommonRepository
{
    /**
     * Les conversations d'un utilisateur, la plus recemment modifiee en tete.
     *
     * @return WittyConversation[]
     */
    public function findForUser(int $userId, int $limit = 30): array
    {
        return $this->createQueryBuilder('c')
            ->where('IDENTITY(c.user) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('c.dateModified', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * Chargee avec ses messages : evite N+1 requetes a la reprise d'un fil.
     */
    public function findOneForUser(int $id, int $userId): ?WittyConversation
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.messages', 'm')
            ->addSelect('m')
            ->where('c.id = :id')
            ->andWhere('IDENTITY(c.user) = :userId')
            ->setParameter('id', $id)
            ->setParameter('userId', $userId)
            ->orderBy('m.sequence', 'ASC')
            ->getQuery()
            ->getOneOrNullResult();
    }
}
