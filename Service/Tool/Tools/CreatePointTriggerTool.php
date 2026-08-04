<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\PointBundle\Entity\Trigger;
use Mautic\PointBundle\Entity\TriggerEvent;
use Mautic\PointBundle\Model\PointGroupModel;
use Mautic\PointBundle\Model\TriggerModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un declencheur de points : execute des actions (tag, points, ...) quand
 * un contact atteint un seuil de points.
 *
 * Comme pour create_point_action, les types d evenements disponibles viennent
 * des bundles installes : appeler avec list_types=true avant de construire les
 * actions, plutot que de deviner leurs proprietes attendues.
 */
class CreatePointTriggerTool extends AbstractTool
{
    public function __construct(
        private TriggerModel $triggerModel,
        private PointGroupModel $pointGroupModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_point_trigger';
    }

    public function getDescription(): string
    {
        return 'Cree un declencheur de points : execute une ou plusieurs actions quand un contact atteint '
            .'un seuil de points (ou d un groupe de points si group_id est fourni). '
            .'Appeler d abord avec list_types=true pour connaitre les types d actions disponibles.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'point:triggers:create';
    }

    public function getObjectType(): ?string
    {
        return 'point_trigger';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'list_types' => [
                'type'        => 'boolean',
                'description' => 'true pour obtenir la liste des types d actions disponibles, sans rien creer.',
            ],
            'name'        => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'points'      => ['type' => 'integer', 'description' => 'Seuil de points declenchant les actions.'],
            'group_id'    => ['type' => 'integer', 'description' => 'Groupe de points concerne. Score global si absent.'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
            'actions' => [
                'type'        => 'array',
                'description' => 'Actions executees au declenchement.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'type'       => ['type' => 'string', 'description' => 'Type d action, obtenu via list_types.'],
                        'name'       => ['type' => 'string', 'description' => 'Libelle. Deduit du type si absent.'],
                        'properties' => ['type' => 'object', 'description' => 'Proprietes de l action, dependantes du type.'],
                    ],
                    'required' => ['type'],
                ],
            ],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $available = $this->availableTypes();

        if (true === ($arguments['list_types'] ?? false)) {
            return $this->ok(['types' => $available]);
        }

        $name   = trim((string) ($arguments['name'] ?? ''));
        $points = (int) ($arguments['points'] ?? 0);
        $actions = array_values((array) ($arguments['actions'] ?? []));

        if ('' === $name || 0 === $points || [] === $actions) {
            return ['status' => 'error', 'error' => 'name, points et actions sont obligatoires.'];
        }

        foreach ($actions as $action) {
            $type = (string) ($action['type'] ?? '');

            if (!isset($available[$type])) {
                return [
                    'status' => 'error',
                    'error'  => sprintf('Type d action inconnu : %s. Types disponibles : %s', $type, implode(', ', array_keys($available))),
                ];
            }
        }

        $group = null;

        if (!empty($arguments['group_id'])) {
            $group = $this->pointGroupModel->getEntity((int) $arguments['group_id']);

            if (null === $group) {
                return ['status' => 'error', 'error' => sprintf('Groupe de points #%d introuvable.', (int) $arguments['group_id'])];
            }
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'point_trigger',
                'name'    => $name,
                'points'  => $points,
                'actions' => array_map(static fn (array $a): string => (string) ($a['type'] ?? ''), $actions),
            ]);
        }

        $trigger = new Trigger();
        $trigger->setName($name);
        $trigger->setDescription((string) ($arguments['description'] ?? ''));
        $trigger->setPoints($points);
        $trigger->setIsPublished((bool) ($arguments['is_published'] ?? false));

        if (null !== $group) {
            $trigger->setGroup($group);
        }

        foreach ($actions as $index => $action) {
            $type = (string) $action['type'];

            $event = new TriggerEvent();
            $event->setTrigger($trigger);
            $event->setType($type);
            $event->setName((string) ($action['name'] ?? $available[$type]['label'] ?? $type));
            $event->setProperties((array) ($action['properties'] ?? []));
            $event->setOrder($index + 1);

            $trigger->addTriggerEvent('new'.$index, $event);
        }

        $this->triggerModel->saveEntity($trigger);

        return $this->ok([
            'id'      => $trigger->getId(),
            'name'    => $trigger->getName(),
            'points'  => $points,
            'actions' => count($actions),
            'url'     => '/s/points/triggers/edit/'.$trigger->getId(),
        ]);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function availableTypes(): array
    {
        return (array) $this->triggerModel->getEvents();
    }
}
