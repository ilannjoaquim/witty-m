<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Field;

use Mautic\LeadBundle\Model\FieldModel;
use Symfony\Component\Intl\Countries;

/**
 * Garde-fou appele juste avant tout LeadModel::setFieldValues() /
 * CompanyModel::setFieldValues() de ce plugin.
 *
 * Deux problemes constates en production sur des contacts issus de
 * QuickEnrich, tous les deux silencieux (aucune erreur, aucun log) :
 *
 *  - l'agent a ecrit dans l'alias `linkedin_url`, qui n'existe pas ; Mautic
 *    ignore purement et simplement toute cle inconnue passee a
 *    setFieldValues(), la valeur disparait sans laisser de trace. -> on
 *    detecte les alias inconnus AVANT d'ecrire, ici, une bonne fois pour
 *    toutes, plutot que de compter sur le prompt pour deviner juste a
 *    chaque fois.
 *  - l'agent a ecrit le code ISO renvoye par QuickEnrich (ex. "FR") dans le
 *    champ `country`, qui est un <select> dont les choix sont les noms
 *    complets anglais de CoreBundle/Assets/json/countries.json ("France").
 *    La valeur s'enregistre sans erreur (colonne varchar libre, aucune
 *    validation de choix hors formulaire web) mais n'apparait plus dans la
 *    fiche contact, puisque le select ne peut pas presillectionner une
 *    valeur qu'il ne reconnait pas parmi ses choix. -> on normalise un code
 *    ISO 2 lettres vers le nom complet anglais via Symfony Intl (deja
 *    vendorise par Mautic, verifie : FR -> France, US -> United States,
 *    correspond exactement a la liste Mautic).
 */
class FieldWriteGuard
{
    /** @var array<string, array<string, array<string, mixed>>> object -> alias -> definition, memoise pour la duree de la requete */
    private array $definitionsByObject = [];

    public function __construct(
        private FieldModel $fieldModel,
    ) {
    }

    /**
     * @param array<string, mixed> $fields alias Mautic -> valeur
     *
     * @return array{fields: array<string, mixed>, unknown: string[]}
     */
    public function prepare(array $fields, string $object = 'lead'): array
    {
        return $this->applyGuard($fields, $object);
    }

    /**
     * Meme chose que prepare(), pour plusieurs lignes d'un coup (ex.
     * bulk_create_contacts) : les definitions de champ sont recuperees une
     * seule fois pour toutes les lignes plutot qu'une fois par ligne.
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array{rows: array<int, array<string, mixed>>, unknown: string[]}
     */
    public function prepareMany(array $rows, string $object = 'lead'): array
    {
        $unknown = [];

        foreach ($rows as $index => $fields) {
            $result        = $this->applyGuard($fields, $object);
            $rows[$index]  = $result['fields'];
            $unknown       = array_merge($unknown, $result['unknown']);
        }

        return ['rows' => $rows, 'unknown' => array_values(array_unique($unknown))];
    }

    /**
     * @param string[] $aliases
     *
     * @return string[] les alias qui ne correspondent a aucun champ publie de cet objet
     */
    public function unknownAliases(array $aliases, string $object = 'lead'): array
    {
        return array_values(array_diff(array_unique($aliases), array_keys($this->definitions($object))));
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array{fields: array<string, mixed>, unknown: string[]}
     */
    private function applyGuard(array $fields, string $object): array
    {
        $byAlias = $this->definitions($object);
        $unknown = array_values(array_diff(array_keys($fields), array_keys($byAlias)));

        foreach ($fields as $alias => $value) {
            if (!isset($byAlias[$alias]) || 'country' !== $byAlias[$alias]['type'] || !is_string($value)) {
                continue;
            }

            $fields[$alias] = $this->normalizeCountryValue($value);
        }

        return ['fields' => $fields, 'unknown' => $unknown];
    }

    /**
     * @return array<string, array<string, mixed>> alias -> definition
     */
    private function definitions(string $object): array
    {
        if (!isset($this->definitionsByObject[$object])) {
            $byAlias = [];
            foreach ($this->fieldModel->getPublishedFieldArrays($object) as $definition) {
                $byAlias[(string) $definition['alias']] = $definition;
            }
            $this->definitionsByObject[$object] = $byAlias;
        }

        return $this->definitionsByObject[$object];
    }

    private function normalizeCountryValue(string $value): string
    {
        $value = trim($value);

        if (2 === strlen($value) && ctype_alpha($value) && Countries::exists(strtoupper($value))) {
            return Countries::getName(strtoupper($value), 'en');
        }

        return $value;
    }
}
