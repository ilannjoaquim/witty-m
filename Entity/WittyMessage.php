<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;

/**
 * Un tour de conversation, dans le meme format que Dto\Message pour pouvoir
 * reconstruire le transcript envoye au modele sans transformation.
 */
class WittyMessage
{
    private ?int $id = null;

    private ?WittyConversation $conversation = null;

    private int $sequence = 0;

    private string $role = Message::ROLE_USER;

    private ?string $content = null;

    /** @var array<int, array<string, mixed>> */
    private array $toolCalls = [];

    private ?string $toolCallId = null;

    private ?string $toolName = null;

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_messages')
            ->setCustomRepositoryClass(WittyMessageRepository::class)
            ->addIndex(['date_added'], 'witty_message_date_added');

        $builder->addId();

        $builder->createManyToOne('conversation', WittyConversation::class)
            ->addJoinColumn('conversation_id', 'id', false, false, 'CASCADE')
            ->inversedBy('messages')
            ->build();

        $builder->addField('sequence', 'integer');
        $builder->addField('role', 'string');
        $builder->addNullableField('content', 'text');
        $builder->addNullableField('toolCalls', 'json', 'tool_calls');
        $builder->addNamedField('toolCallId', 'string', 'tool_call_id', true);
        $builder->addNamedField('toolName', 'string', 'tool_name', true);
        $builder->addNamedField('promptTokens', 'integer', 'prompt_tokens');
        $builder->addNamedField('completionTokens', 'integer', 'completion_tokens');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    /**
     * Conversion depuis le DTO manipule par la boucle de l'agent.
     */
    public static function fromDto(Message $message, int $sequence): self
    {
        $entity = new self();
        $entity->setSequence($sequence);
        $entity->role       = $message->role;
        $entity->content    = $message->content;
        $entity->toolCalls  = array_map(static fn (ToolCall $c): array => $c->toArray(), $message->toolCalls);
        $entity->toolCallId = $message->toolCallId;
        $entity->toolName   = $message->toolName;

        return $entity;
    }

    public function toDto(): Message
    {
        return new Message(
            $this->role,
            $this->content,
            array_map(static fn (array $c): ToolCall => ToolCall::fromArray($c), $this->toolCalls),
            $this->toolCallId,
            $this->toolName,
        );
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getSequence(): int
    {
        return $this->sequence;
    }

    public function setSequence(int $sequence): self
    {
        $this->sequence = $sequence;

        return $this;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getContent(): ?string
    {
        return $this->content;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getToolCalls(): array
    {
        return $this->toolCalls;
    }

    public function getToolName(): ?string
    {
        return $this->toolName;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function setUsage(int $promptTokens, int $completionTokens): self
    {
        $this->promptTokens     = $promptTokens;
        $this->completionTokens = $completionTokens;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }
}
