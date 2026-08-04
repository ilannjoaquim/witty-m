<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\DynamicContentBundle\Entity\DynamicContent;
use Mautic\DynamicContentBundle\Model\DynamicContentModel;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un bloc de contenu dynamique (contenu qui change selon le visiteur).
 *
 * Sans filtres, le contenu s affiche pour tout le monde : c est la variante
 * "par defaut" utile comme repli sur un emplacement qui aura d autres
 * variantes ciblees par la suite.
 */
class CreateDynamicContentTool extends AbstractTool
{
    public function __construct(
        private DynamicContentModel $dynamicContentModel,
        private FieldModel $fieldModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_dynamic_content';
    }

    public function getDescription(): string
    {
        return 'Cree un contenu dynamique (contenu conditionnel affiche selon le profil du visiteur). '
            .'Sans filtres, s affiche pour tout le monde (variante par defaut d un emplacement).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'dynamiccontent:dynamiccontents:create';
    }

    public function getObjectType(): ?string
    {
        return 'dynamic_content';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'name'        => ['type' => 'string', 'description' => 'Nom interne.'],
            'slot_name'   => ['type' => 'string', 'description' => 'Nom de l emplacement dans l email/la page, ex. hero-banner.'],
            'content'     => ['type' => 'string', 'description' => 'Contenu HTML affiche.'],
            'description' => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
            'filters'     => [
                'type'        => 'array',
                'description' => 'Conditions d affichage (meme syntaxe que create_segment). Vide = affiche pour tous.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'field'    => ['type' => 'string', 'description' => 'Alias du champ contact, ex. email, country, tags.'],
                        'operator' => ['type' => 'string', 'description' => 'Operateur Mautic : =, !=, like, contains, gt, lt, empty, !empty, in, !in...'],
                        'value'    => ['type' => 'string'],
                        'glue'     => ['type' => 'string', 'enum' => ['and', 'or'], 'description' => 'Defaut and.'],
                    ],
                    'required' => ['field', 'operator'],
                ],
            ],
        ], ['name', 'slot_name', 'content']);
    }

    public function execute(array $arguments): array
    {
        $name     = trim((string) ($arguments['name'] ?? ''));
        $slotName = trim((string) ($arguments['slot_name'] ?? ''));
        $content  = (string) ($arguments['content'] ?? '');
        $filters  = $this->buildFilters((array) ($arguments['filters'] ?? []));

        if ('' === $name || '' === $slotName || '' === $content) {
            return ['status' => 'error', 'error' => 'name, slot_name et content sont obligatoires.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'      => 'dynamic_content',
                'name'      => $name,
                'slot_name' => $slotName,
                'filters'   => $filters,
            ]);
        }

        $entity = new DynamicContent();
        $entity->setName($name);
        // isCampaignBased vaut true par defaut sur l entite ; un callback de
        // cycle de vie vide alors slotName au moment de la sauvegarde. Ce
        // contenu vise un emplacement email/page statique, pas un noeud de
        // campagne : on le desactive explicitement.
        $entity->setIsCampaignBased(false);
        $entity->setSlotName($slotName);
        $entity->setContent($content);
        $entity->setDescription((string) ($arguments['description'] ?? ''));
        $entity->setIsPublished((bool) ($arguments['is_published'] ?? false));

        if ([] !== $filters) {
            $entity->setFilters($filters);
        }

        $this->dynamicContentModel->saveEntity($entity);

        return $this->ok([
            'id'        => $entity->getId(),
            'name'      => $entity->getName(),
            'slot_name' => $entity->getSlotName(),
            'url'       => '/s/dwc/edit/'.$entity->getId(),
        ]);
    }

    /**
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
}
