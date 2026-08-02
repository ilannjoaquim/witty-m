<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyMessage>
 */
class WittyMessageRepository extends CommonRepository
{
    /**
     * Tokens consommes par un utilisateur depuis une date : base du quota.
     * Le comptage se fait sur les messages et non sur les conversations, pour
     * qu'une conversation entamee hier ne remette pas le compteur a zero.
     */
    public function getTokensUsedSince(int $userId, \DateTimeInterface $since): int
    {
        $total = $this->createQueryBuilder('m')
            ->select('SUM(m.promptTokens + m.completionTokens)')
            ->join('m.conversation', 'c')
            ->where('IDENTITY(c.user) = :userId')
            ->andWhere('m.dateAdded >= :since')
            ->setParameter('userId', $userId)
            ->setParameter('since', $since)
            ->getQuery()
            ->getSingleScalarResult();

        return (int) $total;
    }
}
