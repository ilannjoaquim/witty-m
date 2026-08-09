<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\CategoryBundle\Entity\Category;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modification d'un objet existant : renommage, description, publication,
 * categorie.
 *
 * Volontairement limite a ces champs. Modifier le contenu d'un email ou les
 * filtres d'un segment demande de raisonner sur l'existant : ce sont des outils
 * dedies, pas un setter generique.
 */
class UpdateEntityTool extends AbstractTool
{
    public function __construct(
        private EntityCatalog $catalog,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_entity';
    }

    public function getDescription(): string
    {
        // meet_room existe cote create_category/EntityCatalog::CATEGORY_BUNDLE
        // (une salle plugNmeet porte bien une categorie) mais n'est pas un type
        // update_entity a part entiere (pas de Model Mautic standard derriere) :
        // sa categorie/projets/tags se modifient via update_meet_room, pas ici.
        $categoryTypes = array_values(array_diff($this->catalog->getCategoryTypes(), ['meet_room']));

        return 'Modifie un objet existant : nom, description, publication, categorie. '
            .'Types acceptes : '.implode(', ', $this->catalog->getTypes()).'. '
            .'category_id ne s applique qu aux types suivants : '.implode(', ', $categoryTypes).'. '
            .'Pour une salle plugNmeet (meet_room), utiliser update_meet_room. '
            .'Utiliser list_entities au prealable pour recuperer l identifiant.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'entity';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type'         => ['type' => 'string', 'enum' => $this->catalog->getTypes()],
            'id'           => ['type' => 'integer', 'description' => 'Identifiant de l objet.'],
            'name'         => ['type' => 'string', 'description' => 'Nouveau nom.'],
            'description'  => ['type' => 'string', 'description' => 'Nouvelle description.'],
            'is_published' => ['type' => 'boolean', 'description' => 'Publier ou depublier.'],
            'category_id'  => ['type' => 'integer', 'description' => 'Categorie a assigner (voir list_entities avec entity=category). 0 pour retirer la categorie actuelle.'],
        ], ['type', 'id']);
    }

    public function execute(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $id   = (int) ($arguments['id'] ?? 0);

        if (!$this->catalog->supports($type)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s. Types acceptes : %s', $type, implode(', ', $this->catalog->getTypes()))];
        }

        $model  = $this->catalog->getModel($type);
        $entity = $model?->getEntity($id);

        if (null === $entity) {
            return ['status' => 'error', 'error' => sprintf('%s #%d introuvable.', $type, $id)];
        }

        if (!$this->catalog->isAllowed($type, 'edit', $entity)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de modification refusee sur %s #%d.', $type, $id)];
        }

        $changes = [];

        if (array_key_exists('name', $arguments) && '' !== trim((string) $arguments['name'])) {
            $changes['name'] = ['de' => $this->catalog->describe($entity), 'vers' => trim((string) $arguments['name'])];
        }

        if (array_key_exists('description', $arguments) && method_exists($entity, 'setDescription')) {
            $changes['description'] = ['vers' => (string) $arguments['description']];
        }

        if (array_key_exists('is_published', $arguments)) {
            $changes['is_published'] = ['vers' => (bool) $arguments['is_published']];
        }

        if (array_key_exists('category_id', $arguments) && method_exists($entity, 'setCategory')) {
            $expectedBundle = $this->catalog->getCategoryBundle($type);

            if (null === $expectedBundle) {
                return ['status' => 'error', 'error' => sprintf("%s ne prend pas de categorie.", $type)];
            }

            $categoryId = (int) $arguments['category_id'];

            if (0 === $categoryId) {
                $changes['category'] = ['vers' => 'aucune'];
            } else {
                $category = $this->catalog->getModel('category')?->getEntity($categoryId);

                if (!$category instanceof Category) {
                    return ['status' => 'error', 'error' => sprintf('Categorie #%d introuvable.', $categoryId)];
                }

                if ($category->getBundle() !== $expectedBundle) {
                    return [
                        'status' => 'error',
                        'error'  => sprintf(
                            "La categorie #%d (%s) n'est pas du type %s.",
                            $categoryId,
                            (string) $category->getBundle(),
                            $type,
                        ),
                    ];
                }

                $changes['category'] = ['vers' => $category->getTitle()];
            }
        }

        if ([] === $changes) {
            return ['status' => 'error', 'error' => 'Aucune modification demandee : fournis name, description ou is_published.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => $type,
                'id'      => $id,
                'objet'   => $this->catalog->describe($entity),
                'changes' => $changes,
            ]);
        }

        if (isset($changes['name'])) {
            if (method_exists($entity, 'setName')) {
                $entity->setName($changes['name']['vers']);
            } elseif (method_exists($entity, 'setTitle')) {
                // Asset n a pas de setName, seulement setTitle.
                $entity->setTitle($changes['name']['vers']);
            }
        }

        if (isset($changes['description']) && method_exists($entity, 'setDescription')) {
            $entity->setDescription($changes['description']['vers']);
        }

        if (isset($changes['is_published']) && method_exists($entity, 'setIsPublished')) {
            $entity->setIsPublished($changes['is_published']['vers']);
        }

        if (isset($changes['category']) && method_exists($entity, 'setCategory')) {
            $categoryId = (int) ($arguments['category_id'] ?? 0);
            $entity->setCategory(0 === $categoryId ? null : $this->catalog->getModel('category')?->getEntity($categoryId));
        }

        $model->saveEntity($entity);

        return $this->ok([
            'id'      => $id,
            'name'    => $this->catalog->describe($entity),
            'type'    => $type,
            'changes' => array_keys($changes),
            'url'     => $this->catalog->getUrl($type, $id),
        ]);
    }
}
