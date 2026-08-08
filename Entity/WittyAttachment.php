<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

/**
 * Un fichier joint a un tour de conversation (image, tableur, document...).
 *
 * L'upload se fait avant l'envoi du message (le fichier doit etre pret quand
 * l'utilisateur clique Envoyer) : conversation et message sont donc nullables,
 * renseignes seulement une fois le tour reellement soumis (voir
 * AttachmentManager::attachToConversation(), appele depuis AgentRunner::run()).
 * Entre upload et envoi, seul `user` rattache l'attachment a quelqu'un — c'est
 * ce qui permet de savoir, cote controleur, qu'un identifiant transmis
 * appartient bien a l'utilisateur connecte avant de l'exploiter.
 */
class WittyAttachment
{
    public const KIND_IMAGE      = 'image';
    public const KIND_SPREADSHEET = 'spreadsheet';
    public const KIND_TEXT       = 'text';
    public const KIND_DOCUMENT   = 'document';

    private ?int $id = null;

    private ?User $user = null;

    private ?WittyConversation $conversation = null;

    private ?WittyMessage $message = null;

    private string $originalFilename = '';

    private string $storedFilename = '';

    private string $mimeType = '';

    private string $extension = '';

    private string $kind = self::KIND_DOCUMENT;

    private int $size = 0;

    private ?int $assetId = null;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_attachments')
            ->setCustomRepositoryClass(WittyAttachmentRepository::class)
            ->addIndex(['date_added'], 'witty_attachment_date_added');

        $builder->addId();

        // SET NULL (pas CASCADE) : un compte supprime ne doit pas faire
        // echouer sa propre suppression a cause d'un fichier joint oublie,
        // meme motif que WittyAuditLog::$user.
        $builder->createManyToOne('user', User::class)
            ->addJoinColumn('user_id', 'id', true, false, 'SET NULL')
            ->build();

        // CASCADE : un fichier joint n'a aucune valeur une fois sa conversation
        // supprimee (contrairement au journal d'audit, qui doit lui survivre).
        $builder->createManyToOne('conversation', WittyConversation::class)
            ->addJoinColumn('conversation_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->createManyToOne('message', WittyMessage::class)
            ->addJoinColumn('message_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->addNamedField('originalFilename', 'string', 'original_filename');
        $builder->addNamedField('storedFilename', 'string', 'stored_filename');
        $builder->addNamedField('mimeType', 'string', 'mime_type');
        $builder->addField('extension', 'string');
        $builder->addField('kind', 'string');
        $builder->addField('size', 'integer');
        $builder->addNamedField('assetId', 'integer', 'asset_id', true);
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getConversation(): ?WittyConversation
    {
        return $this->conversation;
    }

    public function setConversation(?WittyConversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getMessage(): ?WittyMessage
    {
        return $this->message;
    }

    public function setMessage(?WittyMessage $message): self
    {
        $this->message = $message;

        return $this;
    }

    public function getOriginalFilename(): string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(string $originalFilename): self
    {
        $this->originalFilename = $originalFilename;

        return $this;
    }

    public function getStoredFilename(): string
    {
        return $this->storedFilename;
    }

    public function setStoredFilename(string $storedFilename): self
    {
        $this->storedFilename = $storedFilename;

        return $this;
    }

    public function getMimeType(): string
    {
        return $this->mimeType;
    }

    public function setMimeType(string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getExtension(): string
    {
        return $this->extension;
    }

    public function setExtension(string $extension): self
    {
        $this->extension = $extension;

        return $this;
    }

    public function getKind(): string
    {
        return $this->kind;
    }

    public function setKind(string $kind): self
    {
        $this->kind = $kind;

        return $this;
    }

    public function getSize(): int
    {
        return $this->size;
    }

    public function setSize(int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getAssetId(): ?int
    {
        return $this->assetId;
    }

    public function setAssetId(?int $assetId): self
    {
        $this->assetId = $assetId;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }
}
