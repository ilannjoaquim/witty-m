<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;

/**
 * Outil de lecture : permet a l'agent de decouvrir l'existant avant d'ecrire.
 *
 * S'appuie sur EntityCatalog plutot que sur un match code en dur : un type
 * ajoute au catalogue (pour update_entity/delete_entity) devient listable sans
 * toucher ce fichier.
 */
class ListEntitiesTool extends AbstractTool
{
    public function __construct(
        private EntityCatalog $catalog,
    ) {
    }

    public function getName(): string
    {
        return 'list_entities';
    }

    public function getDescription(): string
    {
        return 'Liste les objets Mautic existants. Types acceptes : '.implode(', ', $this->catalog->getTypes()).'. '
            .'A utiliser systematiquement avant de creer quelque chose, pour reutiliser un objet existant '
            .'et pour recuperer les identifiants numeriques necessaires aux autres outils.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'entity' => [
                'type'        => 'string',
                'enum'        => $this->catalog->getTypes(),
                'description' => "Type d'objet a lister.",
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Filtre texte optionnel sur le nom.',
            ],
            'limit' => [
                'type'        => 'integer',
                'description' => 'Nombre maximum de resultats (defaut 25, max 100).',
            ],
        ], ['entity']);
    }

    public function execute(array $arguments): array
    {
        $entity = (string) ($arguments['entity'] ?? '');
        $limit  = max(1, min(100, (int) ($arguments['limit'] ?? 25)));

        if (!$this->catalog->supports($entity)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s. Types acceptes : %s', $entity, implode(', ', $this->catalog->getTypes()))];
        }

        $model = $this->catalog->getModel($entity);

        if (null === $model) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s', $entity)];
        }

        $args = [
            'start'  => 0,
            'limit'  => $limit,
            'filter' => ['string' => (string) ($arguments['search'] ?? '')],
        ];

        $items = [];

        foreach ($model->getEntities($args) as $item) {
            if (!is_object($item)) {
                continue;
            }

            $items[] = array_filter([
                'id'          => method_exists($item, 'getId') ? $item->getId() : null,
                'name'        => $this->catalog->describe($item),
                'isPublished' => method_exists($item, 'isPublished') ? $item->isPublished() : null,
                // Sert a distinguer une categorie "email" d'une categorie
                // "segment" du meme nom : sans ca, deux entrees list_entities
                // identiques en apparence porteraient un sens tres different.
                'bundle'      => 'category' === $entity && method_exists($item, 'getBundle') ? $item->getBundle() : null,
            ], static fn ($value): bool => null !== $value);
        }

        return $this->ok(['entity' => $entity, 'count' => count($items), 'items' => $items]);
    }
}
