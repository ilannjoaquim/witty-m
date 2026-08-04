<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\ProjectBundle\Entity\ProjectTrait;
use Mautic\UserBundle\Entity\User;

/**
 * Metadonnees cote Mautic pour une salle plugNmeet (categorie, projets,
 * proprietaire) : plugNmeet lui-meme ne connait que room_id/title, il n'a
 * aucune notion d'utilisateur Mautic, de categorie ou de projet. Cette
 * entite est creee en meme temps que la salle plugNmeet (cf.
 * VideoconferenceController::roomsCreateAction) et retrouvee par roomId
 * pour enrichir l'affichage (cf. Service/Videoconference/RoomManager.php).
 */
class WittyRoom
{
    use ProjectTrait;

    private ?int $id = null;

    private string $roomId = '';

    private string $title = '';

    private ?Category $category = null;

    private ?User $createdBy = null;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded    = new \DateTimeImmutable();
        $this->dateModified = new \DateTimeImmutable();
        $this->initializeProjects();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_rooms')
            ->setCustomRepositoryClass(WittyRoomRepository::class)
            ->addUniqueConstraint(['room_id'], 'witty_room_room_id');

        $builder->addId();

        $builder->createManyToOne('createdBy', User::class)
            ->addJoinColumn('created_by', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addCategory();
        self::addProjectsField($builder, 'witty_room_projects_xref', 'witty_room_id');

        $builder->addNamedField('roomId', 'string', 'room_id');
        $builder->addField('title', 'string');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateModified', 'datetime', 'date_modified');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRoomId(): string
    {
        return $this->roomId;
    }

    public function setRoomId(string $roomId): self
    {
        $this->roomId = mb_substr(trim($roomId), 0, 190);

        return $this;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        $this->title = mb_substr(trim($title), 0, 190);

        return $this;
    }

    public function getCategory(): ?Category
    {
        return $this->category;
    }

    public function setCategory(?Category $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $user): self
    {
        $this->createdBy = $user;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getDateModified(): \DateTimeInterface
    {
        return $this->dateModified;
    }

    public function touch(): self
    {
        $this->dateModified = new \DateTimeImmutable();

        return $this;
    }
}
