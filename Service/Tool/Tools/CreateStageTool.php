<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\StageBundle\Entity\Stage;
use Mautic\StageBundle\Model\StageModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateStageTool extends AbstractTool
{
    public function __construct(
        private StageModel $stageModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_stage';
    }

    public function getDescription(): string
    {
        return 'Cree une etape du cycle de vie (stage). weight determine l ordre relatif entre etapes.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'stage:stages:create';
    }

    public function getObjectType(): ?string
    {
        return 'stage';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'         => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'weight'       => ['type' => 'integer', 'description' => 'Ordre relatif. Defaut 0.'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['name']);
    }

    public function execute(array $arguments): array
    {
        $name = trim((string) ($arguments['name'] ?? ''));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(['type' => 'stage', 'name' => $name]);
        }

        $stage = new Stage();
        $stage->setName($name);
        $stage->setDescription((string) ($arguments['description'] ?? ''));
        $stage->setWeight((int) ($arguments['weight'] ?? 0));
        $stage->setIsPublished((bool) ($arguments['is_published'] ?? false));

        $this->stageModel->saveEntity($stage);

        return $this->ok([
            'id'   => $stage->getId(),
            'name' => $stage->getName(),
            'url'  => '/s/stages/edit/'.$stage->getId(),
        ]);
    }
}
