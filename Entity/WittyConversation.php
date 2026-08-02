<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

/**
 * Un fil de discussion. Le transcript vit ici et non plus dans le navigateur :
 * un rechargement de page ne perd plus rien, et l'historique reste consultable
 * pour l'audit.
 */
class WittyConversation
{
    private ?int $id = null;

    private ?User $user = null;

    private string $title = '';

    private ?string $provider = null;

    private ?string $model = null;

    private int $promptTokens = 0;

    private int $completionTokens = 0;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateModified;

    /** @var Collection<int, WittyMessage> */
    private Collection $messages;

    public function __construct()
    {
        $this->messages     = new ArrayCollection();
        $this->dateAdded    = new \DateTimeImmutable();
        $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_conversations')
            ->setCustomRepositoryClass(WittyConversationRepository::class)
            ->addIndex(['date_modified'], 'witty_conversation_modified');

        $builder->addId();

        $builder->createManyToOne('user', User::class)
            ->addJoinColumn('user_id', 'id', true, false, 'CASCADE')
            ->build();

        $builder->addField('title', 'string');
        $builder->addNullableField('provider', 'string');
        $builder->addNullableField('model', 'string');
        $builder->addNamedField('promptTokens', 'integer', 'prompt_tokens');
        $builder->addNamedField('completionTokens', 'integer', 'completion_tokens');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateModified', 'datetime', 'date_modified');

        $builder->createOneToMany('messages', WittyMessage::class)
            ->setOrderBy(['sequence' => 'ASC'])
            ->mappedBy('conversation')
            ->cascadeAll()
            ->fetchExtraLazy()
            ->build();
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

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): self
    {
        // Colonne en varchar(255) : on coupe sur les caracteres, pas sur les octets.
        $this->title = mb_substr(trim($title), 0, 190);

        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getModel(): ?string
    {
        return $this->model;
    }

    public function setModel(?string $model): self
    {
        $this->model = $model;

        return $this;
    }

    public function getPromptTokens(): int
    {
        return $this->promptTokens;
    }

    public function getCompletionTokens(): int
    {
        return $this->completionTokens;
    }

    public function getTotalTokens(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function addUsage(int $promptTokens, int $completionTokens): self
    {
        $this->promptTokens     += $promptTokens;
        $this->completionTokens += $completionTokens;

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

    /**
     * @return Collection<int, WittyMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(WittyMessage $message): self
    {
        $message->setConversation($this);
        $this->messages->add($message);

        return $this;
    }
}
