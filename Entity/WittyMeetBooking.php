<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\FormBundle\Entity\Field;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Un creneau reserve sur un champ "Creneau de rendez-vous"
 * (witty.meet_slot_picker, cf. EventListener/FormSubscriber.php).
 *
 * La contrainte unique (field_id, slot_start) est ce qui empeche le
 * double-booking : deux visiteurs ne peuvent pas reserver le meme creneau sur
 * le meme champ, chaque champ ayant son propre calendrier independant.
 */
class WittyMeetBooking
{
    private ?int $id = null;

    private ?Field $field = null;

    private ?Lead $lead = null;

    private \DateTimeInterface $slotStart;

    private ?string $roomId = null;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_meet_bookings')
            ->setCustomRepositoryClass(WittyMeetBookingRepository::class)
            ->addIndex(['slot_start'], 'witty_meet_booking_slot_start')
            ->addUniqueConstraint(['field_id', 'slot_start'], 'witty_meet_booking_unique_slot');

        $builder->addId();

        $builder->createManyToOne('field', Field::class)
            ->addJoinColumn('field_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addNamedField('slotStart', 'datetime', 'slot_start');
        $builder->addNullableField('roomId', 'string', 'room_id');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getField(): ?Field
    {
        return $this->field;
    }

    public function setField(Field $field): self
    {
        $this->field = $field;

        return $this;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(?Lead $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getSlotStart(): \DateTimeInterface
    {
        return $this->slotStart;
    }

    public function setSlotStart(\DateTimeInterface $slotStart): self
    {
        $this->slotStart = $slotStart;

        return $this;
    }

    public function getRoomId(): ?string
    {
        return $this->roomId;
    }

    public function setRoomId(?string $roomId): self
    {
        $this->roomId = $roomId;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }
}
