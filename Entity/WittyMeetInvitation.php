<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Lead;

/**
 * Trace d'une invitation a une salle plugNmeet generee pour un contact.
 *
 * webinaire_invitation_link / meeting_invitation_link (champs contact) ne
 * gardent que le dernier lien genere : cette table est la source de verite
 * pour "qui a ete invite a quelle salle", necessaire pour comparer aux
 * presents (artefact MEETING_ANALYTICS) une fois la salle terminee, y compris
 * quand un contact a ete invite a plusieurs webinaires/rendez-vous au fil du
 * temps.
 */
class WittyMeetInvitation
{
    private ?int $id = null;

    private ?Lead $lead = null;

    private string $roomId = '';

    private ?string $roomTitle = null;

    private string $token = '';

    private \DateTimeInterface $dateAdded;

    private ?\DateTimeInterface $clickedAt = null;

    private bool $attended = false;

    private ?\DateTimeInterface $attendedAt = null;

    private ?\DateTimeInterface $reconciledAt = null;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_meet_invitations')
            ->setCustomRepositoryClass(WittyMeetInvitationRepository::class)
            ->addIndex(['room_id'], 'witty_meet_invitation_room')
            ->addIndex(['date_added'], 'witty_meet_invitation_date_added');

        $builder->addId();

        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->addNamedField('roomId', 'string', 'room_id');
        $builder->addNullableField('roomTitle', 'string', 'room_title');
        // 'text', pas 'string' : ClassMetadataBuilder plafonne tout champ
        // 'string' a 191 caracteres (limite d'index utf8mb4) ; le token signe
        // (charge utile base64url + signature HMAC hex) peut le depasser.
        $builder->addField('token', 'text');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNullableField('clickedAt', 'datetime', 'clicked_at');
        $builder->addField('attended', 'boolean');
        $builder->addNullableField('attendedAt', 'datetime', 'attended_at');
        $builder->addNullableField('reconciledAt', 'datetime', 'reconciled_at');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLead(): ?Lead
    {
        return $this->lead;
    }

    public function setLead(Lead $lead): self
    {
        $this->lead = $lead;

        return $this;
    }

    public function getRoomId(): string
    {
        return $this->roomId;
    }

    public function setRoomId(string $roomId): self
    {
        $this->roomId = $roomId;

        return $this;
    }

    public function getRoomTitle(): ?string
    {
        return $this->roomTitle;
    }

    public function setRoomTitle(?string $roomTitle): self
    {
        $this->roomTitle = $roomTitle;

        return $this;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): self
    {
        $this->token = $token;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getClickedAt(): ?\DateTimeInterface
    {
        return $this->clickedAt;
    }

    public function markClicked(): self
    {
        // On garde la premiere date de clic : les clics suivants (nouvel
        // onglet, refresh) ne doivent pas ecraser le premier signal utile.
        if (null === $this->clickedAt) {
            $this->clickedAt = new \DateTimeImmutable();
        }

        return $this;
    }

    public function isAttended(): bool
    {
        return $this->attended;
    }

    public function getAttendedAt(): ?\DateTimeInterface
    {
        return $this->attendedAt;
    }

    public function markAttended(\DateTimeInterface $when): self
    {
        $this->attended   = true;
        $this->attendedAt = $when;

        return $this;
    }

    public function getReconciledAt(): ?\DateTimeInterface
    {
        return $this->reconciledAt;
    }

    public function markReconciled(): self
    {
        $this->reconciledAt = new \DateTimeImmutable();

        return $this;
    }
}
