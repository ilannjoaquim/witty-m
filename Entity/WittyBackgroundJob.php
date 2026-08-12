<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

/**
 * Job de traitement en masse (enrichissement/recherche a volume), execute par
 * petits lots successifs via Command/ProcessBackgroundJobsCommand.php (cron
 * systeme) plutot que d'un bloc dans un tour de chat : un enrichissement Apollo
 * sur un segment de 50 000 contacts represente des milliers d'appels API,
 * largement au-dela de ce qu'un seul tour d'agent peut absorber (cf.
 * AgentRunner::run(), borne par max_iterations, tout dans une seule requete
 * HTTP). Voir Service/Job/JobHandlerInterface.php pour le detail du cycle.
 *
 * `params` fige les arguments de depart (fournis a la creation, jamais
 * modifies ensuite) ; `resumeCursor` est l'etat de reprise opaque propre a
 * chaque handler (ex. dernier lead_id traite, numero de page) — ce que
 * processChunk() lit et avance a chaque appel. Separer les deux evite qu'un
 * handler confonde "ce qu'on m'a demande" et "ou j'en suis". Nomme
 * resumeCursor et pas simplement "cursor" : CURSOR est un mot reserve SQL
 * (utilisable dans les procedures stockees) — un nom de colonne litteral
 * "cursor" aurait exige de le quoter partout, y compris dans chaque requete
 * generee a la volee par Doctrine.
 *
 * Les resultats detailles (un par profil/entreprise/ligne traitee) vivent
 * dans WittyBackgroundJobItem, pas ici : a l'echelle de 50 000 elements, un
 * unique JSON geant sur cette ligne serait aussi bien inefficace a requeter
 * (impossible de paginer une revue "montre-moi les 50 premiers trouves")
 * qu'couteux a charger en memoire pour la moindre mise a jour de progression.
 */
class WittyBackgroundJob
{
    public const STATUS_QUEUED    = 'queued';
    public const STATUS_RUNNING   = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    private ?int $id = null;

    private string $type = '';

    private string $status = self::STATUS_QUEUED;

    private ?User $createdBy = null;

    private string $label = '';

    /** @var array<string, mixed> */
    private array $params = [];

    /** @var array<string, mixed>|null */
    private ?array $resumeCursor = null;

    private ?int $totalItems = null;

    private int $processedItems = 0;

    private int $succeededItems = 0;

    private int $failedItems = 0;

    private ?string $errorMessage = null;

    private \DateTimeInterface $dateAdded;

    private ?\DateTimeInterface $dateStarted = null;

    private ?\DateTimeInterface $dateCompleted = null;

    private ?\DateTimeInterface $lastTickAt = null;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_background_jobs')
            ->setCustomRepositoryClass(WittyBackgroundJobRepository::class)
            ->addIndex(['status'], 'witty_background_job_status')
            ->addIndex(['date_added'], 'witty_background_job_date_added');

        $builder->addId();

        $builder->addField('type', 'string');
        $builder->addField('status', 'string');

        $builder->createManyToOne('createdBy', User::class)
            ->addJoinColumn('created_by', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addField('label', 'string');
        $builder->addField('params', 'json');
        $builder->addNullableField('resumeCursor', 'json', 'resume_cursor');
        $builder->addNullableField('totalItems', 'integer', 'total_items');
        $builder->addNamedField('processedItems', 'integer', 'processed_items');
        $builder->addNamedField('succeededItems', 'integer', 'succeeded_items');
        $builder->addNamedField('failedItems', 'integer', 'failed_items');
        $builder->addNullableField('errorMessage', 'text', 'error_message');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateStarted', 'datetime', 'date_started', true);
        $builder->addNamedField('dateCompleted', 'datetime', 'date_completed', true);
        $builder->addNamedField('lastTickAt', 'datetime', 'last_tick_at', true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): self
    {
        $this->type = $type;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getLabel(): string
    {
        return $this->label;
    }

    public function setLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params;
    }

    /**
     * @param array<string, mixed> $params
     */
    public function setParams(array $params): self
    {
        $this->params = $params;

        return $this;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getResumeCursor(): ?array
    {
        return $this->resumeCursor;
    }

    /**
     * @param array<string, mixed>|null $cursor
     */
    public function setResumeCursor(?array $cursor): self
    {
        $this->resumeCursor = $cursor;

        return $this;
    }

    public function getTotalItems(): ?int
    {
        return $this->totalItems;
    }

    public function setTotalItems(?int $totalItems): self
    {
        $this->totalItems = $totalItems;

        return $this;
    }

    public function getProcessedItems(): int
    {
        return $this->processedItems;
    }

    public function setProcessedItems(int $processedItems): self
    {
        $this->processedItems = $processedItems;

        return $this;
    }

    public function getSucceededItems(): int
    {
        return $this->succeededItems;
    }

    public function setSucceededItems(int $succeededItems): self
    {
        $this->succeededItems = $succeededItems;

        return $this;
    }

    public function getFailedItems(): int
    {
        return $this->failedItems;
    }

    public function setFailedItems(int $failedItems): self
    {
        $this->failedItems = $failedItems;

        return $this;
    }

    public function getErrorMessage(): ?string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(?string $errorMessage): self
    {
        $this->errorMessage = $errorMessage;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }

    public function getDateStarted(): ?\DateTimeInterface
    {
        return $this->dateStarted;
    }

    public function setDateStarted(?\DateTimeInterface $dateStarted): self
    {
        $this->dateStarted = $dateStarted;

        return $this;
    }

    public function getDateCompleted(): ?\DateTimeInterface
    {
        return $this->dateCompleted;
    }

    public function setDateCompleted(?\DateTimeInterface $dateCompleted): self
    {
        $this->dateCompleted = $dateCompleted;

        return $this;
    }

    public function getLastTickAt(): ?\DateTimeInterface
    {
        return $this->lastTickAt;
    }

    public function setLastTickAt(?\DateTimeInterface $lastTickAt): self
    {
        $this->lastTickAt = $lastTickAt;

        return $this;
    }
}
