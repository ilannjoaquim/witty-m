<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateSegmentTool extends AbstractTool
{
    public function __construct(
        private ListModel $listModel,
        private FieldModel $fieldModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_segment';
    }

    public function getDescription(): string
    {
        return 'Cree un segment de contacts avec ses filtres. Les filtres sont combines dans l ordre, '
            .'chaque filtre precisant sa liaison logique (and/or) avec le precedent.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:lists:editother';
    }

    public function getObjectType(): ?string
    {
        return 'segment';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string', 'description' => 'Nom du segment.'],
            'alias'       => ['type' => 'string', 'description' => 'Alias technique. Genere depuis le nom si absent.'],
            'description' => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
            'filters'     => [
                'type'        => 'array',
                'description' => 'Liste des filtres du segment.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'field'    => ['type' => 'string', 'description' => 'Alias du champ contact, ex. email, country, tags.'],
                        'operator' => [
                            'type'        => 'string',
                            'description' => 'Operateur Mautic : =, !=, like, !like, startsWith, endsWith, contains, gt, gte, lt, lte, empty, !empty, in, !in.',
                        ],
                        'value' => ['type' => 'string', 'description' => 'Valeur comparee. Vide pour empty/!empty.'],
                        'glue'  => ['type' => 'string', 'enum' => ['and', 'or'], 'description' => 'Defaut and.'],
                    ],
                    'required' => ['field', 'operator'],
                ],
            ],
        ], ['name', 'filters']);
    }

    public function execute(array $arguments): array
    {
        $name    = trim((string) ($arguments['name'] ?? ''));
        $filters = $this->buildFilters((array) ($arguments['filters'] ?? []));

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'Le nom du segment est obligatoire.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'segment',
                'name'    => $name,
                'filters' => $filters,
            ]);
        }

        $segment = new LeadList();
        $segment->setName($name);
        $segment->setAlias($this->slugify((string) ($arguments['alias'] ?? $name)));
        $segment->setPublicName($name);
        $segment->setDescription((string) ($arguments['description'] ?? ''));
        $segment->setFilters($filters);
        $segment->setIsPublished((bool) ($arguments['is_published'] ?? false));

        $this->listModel->saveEntity($segment);

        return $this->ok([
            'id'      => $segment->getId(),
            'name'    => $segment->getName(),
            'alias'   => $segment->getAlias(),
            'url'     => '/s/segments/edit/'.$segment->getId(),
            'note'    => 'Segment cree non publie par defaut. Les contacts sont rattaches au prochain passage de la commande mautic:segments:update.',
        ]);
    }

    /**
     * Traduit une description simplifiee en structure de filtres Mautic.
     *
     * @param array<int, array<string, mixed>> $input
     *
     * @return array<int, array<string, mixed>>
     */
    private function buildFilters(array $input): array
    {
        $filters = [];

        foreach (array_values($input) as $index => $filter) {
            $alias = (string) ($filter['field'] ?? '');

            if ('' === $alias) {
                continue;
            }

            $filters[] = [
                'glue'       => 0 === $index ? 'and' : (string) ($filter['glue'] ?? 'and'),
                'field'      => $alias,
                'object'     => 'lead',
                'type'       => $this->resolveFieldType($alias),
                'operator'   => (string) ($filter['operator'] ?? '='),
                'properties' => ['filter' => $filter['value'] ?? null],
                'filter'     => $filter['value'] ?? null,
                'display'    => null,
            ];
        }

        return $filters;
    }

    private function resolveFieldType(string $alias): string
    {
        if ('tags' === $alias) {
            return 'tags';
        }

        $field = $this->fieldModel->getRepository()->findOneBy(['alias' => $alias]);

        return $field instanceof LeadField ? (string) $field->getType() : 'text';
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-') ?: 'segment-'.time();
    }
}
