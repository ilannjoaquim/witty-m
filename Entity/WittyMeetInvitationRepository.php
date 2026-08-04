<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyMeetInvitation>
 */
class WittyMeetInvitationRepository extends CommonRepository
{
    public function findOneByToken(string $token): ?WittyMeetInvitation
    {
        return $this->findOneBy(['token' => $token]);
    }

    /**
     * Salles pour lesquelles au moins une invitation attend encore d'etre
     * confrontee a la liste des presents.
     *
     * @return array<int, string>
     */
    public function findPendingRoomIds(): array
    {
        $rows = $this->createQueryBuilder('i')
            ->select('DISTINCT i.roomId AS roomId')
            ->where('i.attended = :attended')
            ->setParameter('attended', false)
            ->getQuery()
            ->getResult();

        return array_values(array_map(static fn (array $row): string => (string) $row['roomId'], $rows));
    }

    /**
     * @return WittyMeetInvitation[]
     */
    public function findPendingForRoom(string $roomId): array
    {
        return $this->createQueryBuilder('i')
            ->where('i.roomId = :roomId')
            ->andWhere('i.attended = :attended')
            ->setParameter('roomId', $roomId)
            ->setParameter('attended', false)
            ->getQuery()
            ->getResult();
    }
}
