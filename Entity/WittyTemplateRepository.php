<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyTemplate>
 */
class WittyTemplateRepository extends CommonRepository
{
    /**
     * @return WittyTemplate[]
     */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('t')
            ->orderBy('t.type', 'ASC')
            ->addOrderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return WittyTemplate[]
     */
    public function findByTypeOrdered(string $type): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.type = :type')
            ->setParameter('type', $type)
            ->orderBy('t.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    public function findOneByTypeAndKey(string $type, string $key): ?WittyTemplate
    {
        return $this->createQueryBuilder('t')
            ->where('t.type = :type')
            ->andWhere('t.key = :key')
            ->setParameter('type', $type)
            ->setParameter('key', $key)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function keyExists(string $type, string $key): bool
    {
        return null !== $this->findOneByTypeAndKey($type, $key);
    }
}
