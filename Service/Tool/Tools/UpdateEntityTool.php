<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modification d'un objet existant : renommage, description, publication.
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
        return 'Modifie un objet existant : nom, description, publication. '
            .'Types acceptes : '.implode(', ', $this->catalog->getTypes()).'. '
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
