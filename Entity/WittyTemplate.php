<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Mautic\CoreBundle\Doctrine\Mapping\ClassMetadataBuilder;
use Mautic\UserBundle\Entity\User;

/**
 * Un template d'email (MJML compile en HTML) ou de landing page (HTML brut),
 * gere depuis la section Witty > Templates. Remplace l'ancienne bibliotheque
 * livree en fichiers (Templates/Email/, Templates/Page/, cf.
 * Service/Template/BuiltInTemplateLoader.php pour la migration des 4
 * templates d'origine) : la base est desormais la seule source de verite,
 * ce qui permet d'en ajouter/modifier/supprimer autant que voulu depuis
 * l'UI, sans toucher au code du plugin.
 *
 * Le "code" est toujours du HTML final, jamais compile par le plugin :
 * PHP ne sait pas compiler du MJML (le compilateur officiel est en Node,
 * cf. dev/build-templates.sh, et le builder MJML de Mautic le compile
 * cote navigateur avec grapesjs-mjml) ni le proposer sans nouvelle
 * dependance serveur. Un template email s'ecrit donc directement en HTML,
 * exactement comme un template de landing page.
 *
 * `rules` et `placeholders` reprennent exactement la structure de
 * manifest.json (voir Service/Tool/Tools/List*TemplatesTool.php) : c'est ce
 * qui permet a l'agent de remplir chaque emplacement selon une consigne
 * precise plutot que de devoir reecrire le HTML lui-meme (cf.
 * Service/Template/PlaceholderRenderer.php).
 *
 * Partage entre tous les utilisateurs de l'instance, comme WittySkill.
 */
class WittyTemplate
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_PAGE  = 'page';

    private ?int $id = null;

    private string $type = self::TYPE_EMAIL;

    private string $key = '';

    private string $name = '';

    private string $description = '';

    private string $goal = '';

    /** @var array<int, string> */
    private array $rules = [];

    /** @var array<int, array<string, mixed>> */
    private array $placeholders = [];

    private string $html = '';

    private ?User $createdBy = null;

    private \DateTimeInterface $dateAdded;

    private \DateTimeInterface $dateModified;

    public function __construct()
    {
        $this->dateAdded    = new \DateTimeImmutable();
        $this->dateModified = new \DateTimeImmutable();
    }

    public static function loadMetadata(ORM\ClassMetadata $metadata): void
    {
        $builder = new ClassMetadataBuilder($metadata);

        $builder->setTable('witty_templates')
            ->setCustomRepositoryClass(WittyTemplateRepository::class)
            ->addIndex(['type'], 'witty_template_type')
            ->addUniqueConstraint(['type', 'template_key'], 'witty_template_type_key');

        $builder->addId();

        $builder->createManyToOne('createdBy', User::class)
            ->addJoinColumn('created_by', 'id', true, false, 'SET NULL')
            ->build();

        $builder->addField('type', 'string');
        // 'key' est un mot reserve dans plusieurs SGBD : colonne 'template_key'.
        $builder->createField('key', 'string')->columnName('template_key')->build();
        $builder->addField('name', 'string');
        $builder->addField('description', 'string');
        $builder->addField('goal', 'text');
        // Stockes en JSON (tableau de strings / tableau d'objets) : memes
        // formes que manifest.json, pas de table de jointure pour quelque
        // chose qui n'est jamais interroge independamment du template.
        $builder->createField('rules', 'json')->build();
        $builder->createField('placeholders', 'json')->build();
        $builder->addField('html', 'text');
        $builder->addNamedField('dateAdded', 'datetime', 'date_added');
        $builder->addNamedField('dateModified', 'datetime', 'date_modified');
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
        $this->type = self::TYPE_PAGE === $type ? self::TYPE_PAGE : self::TYPE_EMAIL;

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function setKey(string $key): self
    {
        $this->key = mb_substr(trim($key), 0, 190);

        return $this;
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

    public function getGoal(): string
    {
        return $this->goal;
    }

    public function setGoal(string $goal): self
    {
        $this->goal = $goal;

        return $this;
    }

    /**
     * @return array<int, string>
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @param array<int, string> $rules
     */
    public function setRules(array $rules): self
    {
        $this->rules = array_values(array_map('strval', $rules));

        return $this;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPlaceholders(): array
    {
        return $this->placeholders;
    }

    /**
     * @param array<int, array<string, mixed>> $placeholders
     */
    public function setPlaceholders(array $placeholders): self
    {
        $this->placeholders = array_values($placeholders);

        return $this;
    }

    public function getHtml(): string
    {
        return $this->html;
    }

    public function setHtml(string $html): self
    {
        $this->html = $html;

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
     * @return array<int, string>
     */
    public function getPlaceholderKeys(): array
    {
        return array_map(static fn (array $p): string => (string) $p['key'], $this->placeholders);
    }

    /**
     * Emplacements atterrissant dans une chaine JavaScript (a l'interieur
     * d'un <script>) plutot que dans du HTML visible : voir PlaceholderRenderer.
     *
     * @return array<int, string>
     */
    public function getJsContextKeys(): array
    {
        return $this->keysWithContext('js');
    }

    /**
     * Emplacements HTML ou un <br> litteral doit survivre l'echappement
     * (accroche/titre sur deux lignes), voir
     * PlaceholderRenderer::escapeHtmlAllowingBr().
     *
     * @return array<int, string>
     */
    public function getHtmlBrContextKeys(): array
    {
        return $this->keysWithContext('html_br');
    }

    /**
     * @return array<int, string>
     */
    private function keysWithContext(string $context): array
    {
        return array_values(array_map(
            static fn (array $p): string => (string) $p['key'],
            array_filter($this->placeholders, static fn (array $p): bool => $context === ($p['context'] ?? 'html')),
        ));
    }

    /**
     * Emplacements sans valeur par defaut : obligatoires pour que le
     * template ait un sens une fois substitue.
     *
     * @return array<int, string>
     */
    public function getRequiredKeys(): array
    {
        $required = [];

        foreach ($this->placeholders as $placeholder) {
            if (!array_key_exists('default', $placeholder)) {
                $required[] = (string) $placeholder['key'];
            }
        }

        return $required;
    }

    /**
     * @return array<string, string>
     */
    public function getDefaults(): array
    {
        $defaults = [];

        foreach ($this->placeholders as $placeholder) {
            if (array_key_exists('default', $placeholder)) {
                $defaults[(string) $placeholder['key']] = (string) $placeholder['default'];
            }
        }

        return $defaults;
    }

    /**
     * Definition exposee au modele : le libelle et la consigne comptent
     * autant que la cle.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describePlaceholders(): array
    {
        return array_map(static fn (array $p): array => array_filter([
            'key'      => $p['key'] ?? '',
            'label'    => $p['label'] ?? '',
            'guidance' => $p['guidance'] ?? '',
            'example'  => $p['example'] ?? null,
            'default'  => $p['default'] ?? null,
            'required' => !array_key_exists('default', $p),
        ], static fn ($value): bool => null !== $value), $this->placeholders);
    }
}
