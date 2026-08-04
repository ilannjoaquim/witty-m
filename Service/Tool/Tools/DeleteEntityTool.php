<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;

/**
 * Suppression d'un objet existant.
 *
 * Seule action reellement destructrice de l'agent : la confirmation est exigee
 * meme si le mode confirmation global est desactive, et l'apercu affiche ce qui
 * va disparaitre.
 */
class DeleteEntityTool extends AbstractTool
{
    public function __construct(
        private EntityCatalog $catalog,
    ) {
    }

    public function getName(): string
    {
        return 'delete_entity';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement un objet. Types acceptes : '.implode(', ', $this->catalog->getTypes()).'. '
            .'Irreversible. Demande toujours l accord explicite de l utilisateur avant d appeler cet outil avec confirmed=true.';
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
            'type' => ['type' => 'string', 'enum' => $this->catalog->getTypes()],
            'id'   => ['type' => 'integer', 'description' => 'Identifiant de l objet a supprimer.'],
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

        if (!$this->catalog->isAllowed($type, 'delete', $entity)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de suppression refusee sur %s #%d.', $type, $id)];
        }

        $label = $this->catalog->describe($entity);

        // Pas de raccourci possible ici, meme si la confirmation globale est
        // desactivee : une suppression ne se rattrape pas.
        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => $type,
                'id'            => $id,
                'objet'         => $label,
                'irreversible'  => true,
                'avertissement' => 'Cette suppression est definitive.',
            ]);
        }

        $model->deleteEntity($entity);

        return $this->ok([
            'id'      => $id,
            'type'    => $type,
            'name'    => $label,
            'deleted' => true,
        ]);
    }
}
