<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\PointBundle\Entity\Group;
use Mautic\PointBundle\Model\PointGroupModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un groupe de points : permet de suivre plusieurs scores independants
 * par contact (ex. "interet produit A" vs "interet produit B") plutot qu un
 * score global unique.
 */
class CreatePointGroupTool extends AbstractTool
{
    public function __construct(
        private PointGroupModel $pointGroupModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_point_group';
    }

    public function getDescription(): string
    {
        return 'Cree un groupe de points, pour suivre un score independant du score global du contact.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'point:groups:create';
    }

    public function getObjectType(): ?string
    {
        return 'point_group';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
        ], ['name']);
    }

    public function execute(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(['type' => 'point_group', 'name' => $name]);
        }

        $group = new Group();
        $group->setName($name);
        $group->setDescription((string) ($arguments['description'] ?? ''));

        $this->pointGroupModel->saveEntity($group);

        return $this->ok([
            'id'   => $group->getId(),
            'name' => $group->getName(),
            'url'  => '/s/points/groups/edit/'.$group->getId(),
        ]);
    }
}
