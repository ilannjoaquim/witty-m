<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\UserBundle\Entity\User;

/**
 * Suivi d'une demande d'enrichissement Apollo "waterfall" (email et/ou
 * telephone, cf. Service/Tool/Tools/EnrichPersonWaterfallTool.php).
 *
 * Contrairement a enrich_person/bulk_enrich_people (synchrones, reponse
 * immediate), le waterfall est asynchrone : Apollo accuse reception tout de
 * suite (`status=pending` ici) puis POSTe le resultat, parfois plusieurs
 * minutes plus tard, sur Controller/ApolloWaterfallWebhookController.php.
 * Cette table est ce qui permet a l'agent de retrouver ce resultat sur un
 * tour de conversation ulterieur (cf. CheckWaterfallEnrichmentTool), le
 * `requestId` d'Apollo n'ayant aucune chance de rester en memoire du modele
 * d'un tour a l'autre sans etre repete par l'utilisateur.
 *
 * `label` fige un affichage humain (nom ou email demande) au moment de la
 * requete : `lead` peut devenir null (contact supprime entre-temps) sans
 * que l'historique perde tout son sens pour l'utilisateur.
 */
class WittyApolloWaterfallRequest
{
    public const MODE_EMAIL = 'email';
    public const MODE_PHONE = 'phone';
    public const MODE_BOTH  = 'both';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED    = 'failed';

    private ?int $id = null;

    private string $requestId = '';

    private ?Lead $lead = null;

    private ?User $createdBy = null;

    private string $mode = self::MODE_EMAIL;

    private string $status = self::STATUS_PENDING;

    private string $label = '';

    /** @var array<string, mixed>|null */
    private ?array $result = null;

    private \DateTimeInterface $dateAdded;

    private ?\DateTimeInterface $dateCompleted = null;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_apollo_waterfall_requests')
            ->setCustomRepositoryClass(WittyApolloWaterfallRequestRepository::class)
            ->addUniqueConstraint(['request_id'], 'witty_apollo_waterfall_request_id')
            ->addIndex(['date_added'], 'witty_apollo_waterfall_date_added');

        $builder->addId();

        $builder->addNamedField('requestId', 'string', 'request_id');

        // SET NULL : un contact supprime ne doit pas faire echouer sa propre
        // suppression a cause d'un historique d'enrichissement oublie, meme
        // motif que WittyMeetBooking::$lead.
        $builder->createManyToOne('lead', Lead::class)
            ->addJoinColumn('lead_id', 'id', true, false, 'SET NULL')
            ->build();

        $builder->createManyToOne('createdBy', User::class)
            ->addJoinColumn('created_by', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addField('mode', 'string');
        $builder->addField('status', 'string');
        $builder->addField('label', 'string');
        $builder->addNullableField('result', 'json');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateCompleted', 'datetime', 'date_completed', true);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestId(): string
    {
        return $this->requestId;
    }

    public function setRequestId(string $requestId): self
    {
        $this->requestId = $requestId;

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

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): self
    {
        $this->mode = $mode;

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
     * @return array<string, mixed>|null
     */
    public function getResult(): ?array
    {
        return $this->result;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    public function setResult(?array $result): self
    {
        $this->result = $result;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
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
}
