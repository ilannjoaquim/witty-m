<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Mautic\CategoryBundle\Entity\Category;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\ProjectBundle\Entity\ProjectTrait;
use Mautic\UserBundle\Entity\User;

/**
 * Un "skill" : une strategie/playbook propre a l'entreprise (texte libre,
 * ecrit dans l'UI ou importe depuis un .md) que l'agent peut charger a la
 * demande via l'outil read_skill (cf. Service/Tool/Tools/ReadSkillTool.php).
 *
 * Partages entre tous les utilisateurs de l'instance : le but est justement
 * qu'une entreprise enseigne un playbook commun a son agent, pas un carnet
 * de notes personnel. Seul name+description restent en permanence dans le
 * prompt systeme (cf. PromptBuilder) ; le contenu complet n'est charge que
 * si l'agent (ou l'utilisateur explicitement) le demande.
 */
class WittySkill
{
    use ProjectTrait;

    private ?int $id = null;

    private string $name = '';

    private string $description = '';

    private string $content = '';

    private ?Category $category = null;

    /** @var Collection<int, Tag> */
    private Collection $tags;

    private ?User $createdBy = null;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded    = new \DateTimeImmutable();
        $this->dateModified = new \DateTimeImmutable();
        $this->tags         = new ArrayCollection();
        $this->initializeProjects();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_skills')
            ->setCustomRepositoryClass(WittySkillRepository::class)
            ->addIndex(['name'], 'witty_skill_name');

        $builder->addId();

        $builder->createManyToOne('createdBy', User::class)
            ->addJoinColumn('created_by', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addCategory();
        self::addProjectsField($builder, 'witty_skill_projects_xref', 'skill_id');

        $builder->createManyToMany('tags', Tag::class)
            ->setJoinTable('witty_skill_tags_xref')
            ->addInverseJoinColumn('tag_id', 'id', false)
            ->addJoinColumn('skill_id', 'id', false, false, 'CASCADE')
            ->setOrderBy(['tag' => 'ASC'])
            ->setIndexBy('tag')
            ->fetchLazy()
            ->cascadeMerge()
            ->cascadePersist()
            ->cascadeDetach()
            ->build();

        $builder->addField('name', 'string');
        // 'string' plafonne a 191 caracteres (limite d'index utf8mb4) : la
        // description reste courte expres, elle est envoyee en PERMANENCE
        // dans le prompt systeme pour toutes les conversations (cf.
        // PromptBuilder), contrairement au contenu qui n'est charge qu'a la
        // demande.
        $builder->addField('description', 'string');
        $builder->addField('content', 'text');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateModified', 'datetime', 'date_modified');
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setName(string $name): self
    {
        $this->name = mb_substr(trim($name), 0, 190);

        return $this;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function setDescription(string $description): self
    {
        $this->description = mb_substr(trim($description), 0, 190);

        return $this;
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): self
    {
        $this->content = $content;

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

    /**
     * @return Collection<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    /**
     * @param Collection<int, Tag> $tags
     */
    public function setTags(Collection $tags): self
    {
        $this->tags = $tags;

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

    /**
     * Approximation ~4 caracteres/token (heuristique standard pour les
     * tokenizers type GPT/Claude) : suffisant pour donner un ordre de
     * grandeur du poids de ce skill dans le prompt systeme, sans dependre
     * d'un fournisseur LLM precis.
     */
    public function getEstimatedTokens(): int
    {
        return (int) ceil(mb_strlen($this->content) / 4);
    }
}
