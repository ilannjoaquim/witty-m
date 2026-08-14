<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Model\FieldModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Outil de lecture : liste les vrais alias de champ Mautic (contact ou
 * entreprise), avec leurs choix quand le champ est une liste fermee.
 *
 * A appeler avant tout ecrit (create_contact, update_contact,
 * bulk_create_contacts, create_company, update_company,
 * start_contacts_import_from_job, start_companies_import_from_job,
 * import_leads_from_file) plutot que de deviner un alias : un alias inconnu
 * est desormais rejete explicitement par ces outils (cf. FieldWriteGuard),
 * mais mieux vaut le bon alias du premier coup. Utile aussi pour les champs
 * a choix fixes (select/multiselect : ex. le secteur d'activite d'une
 * entreprise) ou une valeur hors liste serait tout aussi silencieusement
 * perdue a l'affichage.
 */
class ListFieldsTool extends AbstractTool
{
    private const OBJECT_MAP = [
        'contact' => 'lead',
        'company' => 'company',
    ];

    public function __construct(
        private FieldModel $fieldModel,
    ) {
    }

    public function getName(): string
    {
        return 'list_fields';
    }

    public function getDescription(): string
    {
        return "Liste les champs Mautic reellement disponibles pour un contact ou une entreprise : alias exact, "
            .'label, type, et les valeurs acceptees pour les champs a choix fixe (select/multiselect). '
            .'A utiliser avant d ecrire un champ dont tu n es pas sur de l alias ou des valeurs possibles '
            .'(ex. secteur d activite, statut...) plutot que de deviner : un alias qui n existe pas est ignore '
            .'sans erreur par Mautic, la donnee disparait silencieusement.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'object' => [
                'type'        => 'string',
                'enum'        => array_keys(self::OBJECT_MAP),
                'description' => 'contact ou company.',
            ],
            'search' => [
                'type'        => 'string',
                'description' => 'Filtre texte optionnel sur l alias ou le label.',
            ],
        ], ['object']);
    }

    public function execute(array $arguments): array
    {
        $object = (string) ($arguments['object'] ?? '');

        if (!isset(self::OBJECT_MAP[$object])) {
            return ['status' => 'error', 'error' => sprintf('object inconnu : %s. Valeurs acceptees : %s', $object, implode(', ', array_keys(self::OBJECT_MAP)))];
        }

        $search = trim((string) ($arguments['search'] ?? ''));
        $fields = [];

        foreach ($this->fieldModel->getPublishedFieldArrays(self::OBJECT_MAP[$object]) as $definition) {
            $alias = (string) $definition['alias'];
            $label = (string) $definition['label'];

            if ('' !== $search && !str_contains(strtolower($alias), strtolower($search)) && !str_contains(strtolower($label), strtolower($search))) {
                continue;
            }

            $fields[] = array_filter([
                'alias'   => $alias,
                'label'   => $label,
                'group'   => (string) $definition['group'],
                'type'    => (string) $definition['type'],
                'choices' => $this->choicesFor($definition),
                'note'    => 'country' === $definition['type']
                    ? 'Nom complet du pays en anglais attendu (ex. "France", "United States"), pas de code ISO.'
                    : null,
            ], static fn ($value): bool => null !== $value && [] !== $value);
        }

        return $this->ok(['object' => $object, 'count' => count($fields), 'fields' => $fields]);
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return string[]|null
     */
    private function choicesFor(array $definition): ?array
    {
        if (!in_array($definition['type'], ['select', 'multiselect'], true)) {
            return null;
        }

        $list = $definition['properties']['list'] ?? [];

        if (!is_array($list)) {
            return null;
        }

        $choices = [];
        foreach ($list as $option) {
            if (is_array($option) && isset($option['value'])) {
                $choices[] = (string) $option['value'];
            }
        }

        return [] === $choices ? null : $choices;
    }
}
