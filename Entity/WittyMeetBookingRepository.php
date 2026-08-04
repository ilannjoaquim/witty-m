<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyMeetBooking>
 */
class WittyMeetBookingRepository extends CommonRepository
{
    /**
     * Creneaux deja reserves sur un champ, dans une fenetre de dates : c'est
     * ce qu'on retranche des creneaux theoriquement disponibles calcules
     * depuis la regle de recurrence du champ.
     *
     * @return array<int, \DateTimeInterface>
     */
    public function findBookedSlots(int $fieldId, \DateTimeInterface $from, \DateTimeInterface $to): array
    {
        $rows = $this->createQueryBuilder('b')
            ->select('b.slotStart AS slotStart')
            ->where('b.field = :fieldId')
            ->andWhere('b.slotStart >= :from')
            ->andWhere('b.slotStart < :to')
            ->setParameter('fieldId', $fieldId)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->getQuery()
            ->getResult();

        return array_map(static fn (array $row): \DateTimeInterface => $row['slotStart'], $rows);
    }
}
