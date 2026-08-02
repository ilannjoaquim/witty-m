<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

/**
 * Journal des executions d'outils : qui a demande quoi, quel outil a tourne,
 * quel objet Mautic en est sorti.
 *
 * Le nom d'utilisateur est duplique en clair : un compte supprime ne doit pas
 * effacer la trace de ce qu'il a fait.
 */
class WittyAuditLog
{
    public const STATUS_OK           = 'ok';
    public const STATUS_ERROR        = 'error';
    public const STATUS_CONFIRMATION = 'confirmation_required';
    public const STATUS_DENIED       = 'denied';

    private ?int $id = null;

    private ?WittyConversation $conversation = null;

    private ?User $user = null;

    private string $userName = '';

    private string $tool = '';

    private bool $writeOperation = false;

    /** @var array<string, mixed> */
    private array $arguments = [];

    private string $status = self::STATUS_OK;

    private ?string $objectType = null;

    private ?int $objectId = null;

    private ?string $message = null;

    private int $durationMs = 0;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_audit_log')
            ->setCustomRepositoryClass(WittyAuditLogRepository::class)
            ->addIndex(['date_added'], 'witty_audit_date_added')
            ->addIndex(['tool'], 'witty_audit_tool')
            ->addIndex(['object_type', 'object_id'], 'witty_audit_object');

        $builder->addId();

        $builder->createManyToOne('conversation', WittyConversation::class)
            ->addJoinColumn('conversation_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->createManyToOne('user', User::class)
            ->addJoinColumn('user_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addNamedField('userName', 'string', 'user_name');
        $builder->addField('tool', 'string');
        $builder->addNamedField('writeOperation', 'boolean', 'write_operation');
        $builder->addField('arguments', 'json');
        $builder->addField('status', 'string');
        $builder->addNamedField('objectType', 'string', 'object_type', true);
        $builder->addNamedField('objectId', 'integer', 'object_id', true);
        $builder->addNullableField('message', 'text');
        $builder->addNamedField('durationMs', 'integer', 'duration_ms');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setConversation(?WittyConversation $conversation): self
    {
        $this->conversation = $conversation;

        return $this;
    }

    public function getConversation(): ?WittyConversation
    {
        return $this->conversation;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getUserName(): string
    {
        return $this->userName;
    }

    public function setUserName(string $userName): self
    {
        $this->userName = mb_substr($userName, 0, 190);

        return $this;
    }

    public function getTool(): string
    {
        return $this->tool;
    }

    public function setTool(string $tool): self
    {
        $this->tool = $tool;

        return $this;
    }

    public function isWriteOperation(): bool
    {
        return $this->writeOperation;
    }

    public function setWriteOperation(bool $writeOperation): self
    {
        $this->writeOperation = $writeOperation;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getArguments(): array
    {
        return $this->arguments;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public function setArguments(array $arguments): self
    {
        $this->arguments = $arguments;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function getObjectType(): ?string
    {
        return $this->objectType;
    }

    public function getObjectId(): ?int
    {
        return $this->objectId;
    }

    public function setObject(?string $type, ?int $id): self
    {
        $this->objectType = $type;
        $this->objectId   = $id;

        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): self
    {
        return $this->setTruncatedMessage($message);
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function setDurationMs(int $durationMs): self
    {
        $this->durationMs = $durationMs;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    private function setTruncatedMessage(?string $message): self
    {
        $this->message = null === $message ? null : mb_substr($message, 0, 2000);

        return $this;
    }
}
