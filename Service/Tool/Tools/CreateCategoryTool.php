<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CategoryBundle\Entity\Category;
use Mautic\CategoryBundle\Model\CategoryModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Categorie Mautic (taxonomie transverse : email, page, segment, campagne,
 * formulaire, asset, stage, point, contenu dynamique, message). Une fois
 * creee, s'assigne a un objet existant via update_entity(category_id=...).
 *
 * Une categorie appartient a un seul type (bundle cote Mautic) : impossible
 * d'en creer une utilisable partout, c'est une contrainte du modele de
 * donnees Mautic lui-meme (Category::$bundle, colonne NOT NULL).
 *
 * Permission particuliere : pas de getRequiredPermission() statique, la
 * permission reelle depend du bundle cible (ex. email:categories:create),
 * verifiee a la main via EntityCatalog::isCategoryCreateAllowed() — meme
 * raison que le couple update_entity/delete_entity avec EntityCatalog::isAllowed().
 */
class CreateCategoryTool extends AbstractTool
{
    public function __construct(
        private CategoryModel $categoryModel,
        private EntityCatalog $catalog,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_category';
    }

    public function getDescription(): string
    {
        return 'Cree une categorie Mautic, rattachee a un type d objet precis (bundle) : '
            .implode(', ', $this->catalog->getCategoryTypes()).'. '
            .'Utilise ensuite update_entity(type, id, category_id) pour l assigner a un objet existant de ce type. '
            .'Toujours verifier via list_entities(entity=category) qu une categorie equivalente n existe pas deja.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'category';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'title' => ['type' => 'string', 'description' => 'Nom de la categorie.'],
            'bundle' => [
                'type'        => 'string',
                'enum'        => $this->catalog->getCategoryTypes(),
                'description' => "Type d objet auquel cette categorie s applique (ex. 'email' pour categoriser des emails).",
            ],
            'description' => ['type' => 'string'],
            'color'        => ['type' => 'string', 'description' => 'Couleur hexadecimale, ex. #4e5e9e. Facultatif.'],
        ], ['title', 'bundle']);
    }

    public function execute(array $arguments): array
    {
        $title  = trim((string) ($arguments['title'] ?? ''));
        $bundle = (string) ($arguments['bundle'] ?? '');

        if ('' === $title) {
            return ['status' => 'error', 'error' => 'title est obligatoire.'];
        }

        $mauticBundle = $this->catalog->getCategoryBundle($bundle);

        if (null === $mauticBundle) {
            return [
                'status' => 'error',
                'error'  => sprintf('bundle invalide : %s. Valeurs acceptees : %s', $bundle, implode(', ', $this->catalog->getCategoryTypes())),
            ];
        }

        if (!$this->catalog->isCategoryCreateAllowed($bundle)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de creation de categorie refusee pour %s.', $bundle)];
        }

        $color = trim((string) ($arguments['color'] ?? ''));

        if ('' !== $color && 1 !== preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return ['status' => 'error', 'error' => 'color doit etre un hexadecimal du type #RRGGBB.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'   => 'category',
                'title'  => $title,
                'bundle' => $bundle,
                'color'  => '' !== $color ? $color : null,
            ]);
        }

        $category = new Category();
        $category->setTitle($title);
        $category->setBundle($mauticBundle);
        $category->setDescription((string) ($arguments['description'] ?? ''));

        if ('' !== $color) {
            $category->setColor($color);
        }

        $this->categoryModel->saveEntity($category);

        return $this->ok([
            'id'     => $category->getId(),
            'title'  => $category->getTitle(),
            'bundle' => $bundle,
            'url'    => $this->catalog->getUrl('category', (int) $category->getId()),
        ]);
    }
}
