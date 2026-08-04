<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyRoom>
 */
class WittyRoomRepository extends CommonRepository
{
    public function findOneByRoomId(string $roomId): ?WittyRoom
    {
        return $this->createQueryBuilder('r')
            ->where('r.roomId = :roomId')
            ->setParameter('roomId', $roomId)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * @param string[] $roomIds
     *
     * @return array<string, WittyRoom> Indexe par roomId plugNmeet.
     */
    public function findByRoomIds(array $roomIds): array
    {
        if ([] === $roomIds) {
            return [];
        }

        $rooms = $this->createQueryBuilder('r')
            ->where('r.roomId IN (:roomIds)')
            ->setParameter('roomIds', $roomIds)
            ->getQuery()
            ->getResult();

        $indexed = [];
        foreach ($rooms as $room) {
            $indexed[$room->getRoomId()] = $room;
        }

        return $indexed;
    }
}
