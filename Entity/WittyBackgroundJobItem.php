<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;

/**
 * Un element traite par un WittyBackgroundJob (un profil enrichi, une ligne
 * trouvee par une recherche...). `externalRef` identifie l'element dans le
 * systeme d'origine (id de contact Mautic, email, id distant...) — jamais
 * interprete par le job lui-meme, seulement restitue tel quel a l'agent pour
 * qu'il sache quoi faire du resultat (ex. quel contact_id passer a
 * update_contact).
 *
 * Aucune ecriture automatique sur un contact Mautic ne part d'ici : `data`
 * est un resultat en ATTENTE de revue par l'agent (cf. list_bulk_job_items),
 * qui decide lui-meme d'appliquer ou non via update_contact/bulk_create_contacts
 * — meme principe que le waterfall Apollo (WittyApolloWaterfallRequest), pour
 * la meme raison : rien ne doit s'ecrire sur un contact sans validation
 * explicite, meme quand le declencheur (un cron) n'est lui-meme jamais
 * confirme par personne.
 *
 * `consumedAt` : distinct de `status`, qui decrit le resultat cote job
 * SOURCE (recherche/enrichissement). `consumedAt` decrit si un job d'IMPORT
 * (ImportContactsFromJobHandler/ImportCompaniesFromJobHandler) a deja
 * transmis cet element a Mautic — necessaire car un meme job source peut
 * faire l'objet de plusieurs imports successifs (ex. resume_bulk_job fait
 * grossir le job source entre deux imports) : sans ce marquage, un import
 * ulterieur relirait tout depuis le debut, doublons potentiels pour un
 * rapprochement par email sans email disponible.
 */
class WittyBackgroundJobItem
{
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED    = 'failed';
    public const STATUS_SKIPPED   = 'skipped';

    private ?int $id = null;

    private ?WittyBackgroundJob $job = null;

    private string $externalRef = '';

    private string $status = self::STATUS_SUCCEEDED;

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    private ?string $errorMessage = null;

    private ?\DateTimeInterface $consumedAt = null;

    private \DateTimeInterface $dateAdded;

    public function __construct()
    {
        $this->dateAdded = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_background_job_items')
            ->setCustomRepositoryClass(WittyBackgroundJobItemRepository::class)
            ->addIndex(['job_id', 'status'], 'witty_background_job_item_job_status');

        $builder->addId();

        // CASCADE : le detail d'un job n'a aucune valeur une fois le job
        // lui-meme supprime.
        $builder->createManyToOne('job', WittyBackgroundJob::class)
            ->addJoinColumn('job_id', 'id', false, false, 'CASCADE')
            ->build();

        $builder->addNamedField('externalRef', 'string', 'external_ref');
        $builder->addField('status', 'string');
        $builder->addNullableField('data', 'json');
        $builder->addNullableField('errorMessage', 'text', 'error_message');
        $builder->addNullableField('consumedAt', 'datetime', 'consumed_at');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJob(): ?WittyBackgroundJob
    {
        return $this->job;
    }

    public function setJob(?WittyBackgroundJob $job): self
    {
        $this->job = $job;

        return $this;
    }

    public function getExternalRef(): string
    {
        return $this->externalRef;
    }

    public function setExternalRef(string $externalRef): self
    {
        $this->externalRef = $externalRef;

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

    /**
     * @return array<string, mixed>|null
     */
    public function getData(): ?array
    {
        return $this->data;
    }

    /**
     * @param array<string, mixed>|null $data
     */
    public function setData(?array $data): self
    {
        $this->data = $data;

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

    public function getConsumedAt(): ?\DateTimeInterface
    {
        return $this->consumedAt;
    }

    public function setConsumedAt(?\DateTimeInterface $consumedAt): self
    {
        $this->consumedAt = $consumedAt;

        return $this;
    }

    public function getDateAdded(): \DateTimeInterface
    {
        return $this->dateAdded;
    }
}
