<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\PointBundle\Entity\Point;
use Mautic\PointBundle\Model\PointModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree une action de points (scoring).
 *
 * Les types disponibles ne sont pas figes : ils viennent des bundles installes
 * (PointBuilderEvent). On les lit au moment de l'appel plutot que de coder une
 * liste qui vieillirait mal.
 */
class CreatePointActionTool extends AbstractTool
{
    public function __construct(
        private PointModel $pointModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_point_action';
    }

    public function getDescription(): string
    {
        return 'Cree une action de points : attribue un nombre de points a un contact lorsqu il declenche '
            .'un evenement (ouverture d email, visite de page, soumission de formulaire...). '
            .'Appeler d abord avec list_types=true pour connaitre les types disponibles sur cette instance.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'point:points:create';
    }

    public function getObjectType(): ?string
    {
        return 'point';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'list_types' => [
                'type'        => 'boolean',
                'description' => 'true pour obtenir la liste des types d actions disponibles, sans rien creer.',
            ],
            'name'  => ['type' => 'string', 'description' => 'Nom de l action.'],
            'type'  => ['type' => 'string', 'description' => 'Type d action, ex. email.open, page.hit, form.submit.'],
            'delta' => ['type' => 'integer', 'description' => 'Points attribues. Negatif pour retirer des points.'],
            'description'  => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $available = $this->availableTypes();

        if (true === ($arguments['list_types'] ?? false)) {
            return $this->ok(['types' => $available]);
        }

        $name  = trim((string) ($arguments['name'] ?? ''));
        $type  = (string) ($arguments['type'] ?? '');
        $delta = (int) ($arguments['delta'] ?? 0);

        if ('' === $name || '' === $type) {
            return ['status' => 'error', 'error' => 'name et type sont obligatoires.'];
        }

        if (!in_array($type, $available, true)) {
            return [
                'status' => 'error',
                'error'  => sprintf('Type inconnu : %s. Types disponibles : %s', $type, implode(', ', $available)),
            ];
        }

        if (0 === $delta) {
            return ['status' => 'error', 'error' => 'delta doit etre different de zero.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'   => 'point_action',
                'name'   => $name,
                'action' => $type,
                'delta'  => $delta,
            ]);
        }

        $point = new Point();
        $point->setName($name);
        $point->setType($type);
        $point->setDelta($delta);
        $point->setDescription((string) ($arguments['description'] ?? ''));
        $point->setProperties([]);
        $point->setIsPublished((bool) ($arguments['is_published'] ?? false));

        $this->pointModel->saveEntity($point);

        return $this->ok([
            'id'    => $point->getId(),
            'name'  => $point->getName(),
            'point' => ['type' => $type, 'delta' => $delta],
            'url'   => '/s/points/edit/'.$point->getId(),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function availableTypes(): array
    {
        $actions = $this->pointModel->getPointActions();

        return array_values(array_map('strval', array_keys((array) ($actions['actions'] ?? []))));
    }
}
