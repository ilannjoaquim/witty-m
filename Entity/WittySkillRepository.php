<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittySkill>
 */
class WittySkillRepository extends CommonRepository
{
    /**
     * @return WittySkill[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('s')
            ->orderBy('s.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Insensible a la casse : l'agent (ou l'utilisateur) ne reproduit pas
     * forcement la casse exacte du nom en langage naturel.
     */
    public function findOneByNameCaseInsensitive(string $name): ?WittySkill
    {
        return $this->createQueryBuilder('s')
            ->where('LOWER(s.name) = LOWER(:name)')
            ->setParameter('name', $name)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
