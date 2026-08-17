<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Field;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Entity\Lead;
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
 *
 * Un troisieme, constate en production (job d'import bloque en boucle sur le
 * meme element a chaque passage de cron, cf. resume_bulk_job) : une valeur
 * plus longue que la colonne MySQL reelle (ex. un intitule de poste
 * QuickEnrich/Apollo de plus de 191 caracteres dans `position`, `varchar(191)`)
 * fait echouer la requete en SQLSTATE[22001] "Data too long for column" —
 * MySQL en mode strict refuse plutot que de tronquer silencieusement.
 * `LeadField::$charLengthLimit` (visible dans lead_fields) n'est PAS fiable
 * pour verifier ca : constate empiriquement une valeur de 64 pour `position`
 * alors que la vraie colonne fait 191 (ce champ semble purement indicatif
 * cote formulaire pour les champs par defaut, jamais synchronise avec la
 * colonne reelle). La largeur reelle est donc lue directement depuis
 * INFORMATION_SCHEMA.COLUMNS (meme principe que la lecture SQL native de
 * `linkedin` dans QuickenrichBulkEnrichPeopleJobHandler : la source de
 * verite est la structure de la table, jamais une metadonnee Mautic qui
 * peut diverger) et la valeur est tronquee plutot que de faire echouer tout
 * l'ecrit — un intitule de poste raccourci reste largement exploitable, une
 * ecriture qui plante en boucle ne l'est pas.
 */
class FieldWriteGuard
{
    /** @var array<string, array<string, array<string, mixed>>> object -> alias -> definition, memoise pour la duree de la requete */
    private array $definitionsByObject = [];

    /** @var array<string, array<string, int>> object -> alias -> longueur max reelle de la colonne, memoise pour la duree de la requete */
    private array $maxLengthsByObject = [];

    private const ENTITY_CLASSES = [
        'lead'    => Lead::class,
        'company' => Company::class,
    ];

    public function __construct(
        private FieldModel $fieldModel,
        private EntityManagerInterface $entityManager,
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
        $byAlias    = $this->definitions($object);
        $unknown    = array_values(array_diff(array_keys($fields), array_keys($byAlias)));
        $maxLengths = $this->maxLengths($object);

        foreach ($fields as $alias => $value) {
            if (!isset($byAlias[$alias])) {
                continue;
            }

            if ('country' === $byAlias[$alias]['type'] && is_string($value)) {
                $value = $this->normalizeCountryValue($value);
            }

            if (is_string($value) && isset($maxLengths[$alias]) && mb_strlen($value) > $maxLengths[$alias]) {
                $value = mb_substr($value, 0, $maxLengths[$alias]);
            }

            $fields[$alias] = $value;
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

    /**
     * Largeur reelle des colonnes texte de la table `leads`/`companies`, lue
     * directement en base (cf. docblock de classe pour pourquoi
     * LeadField::$charLengthLimit n'est pas fiable ici). Absente du resultat
     * pour un type sans limite de caracteres (TEXT/LONGTEXT : la valeur y
     * est bien presente mais tres large, ex. 65535 pour TEXT — jamais
     * atteinte en pratique, aucune troncature ne s y declenche donc jamais).
     *
     * @return array<string, int> alias -> longueur maximale de la colonne
     */
    private function maxLengths(string $object): array
    {
        if (isset($this->maxLengthsByObject[$object])) {
            return $this->maxLengthsByObject[$object];
        }

        $entityClass = self::ENTITY_CLASSES[$object] ?? null;

        if (null === $entityClass) {
            return $this->maxLengthsByObject[$object] = [];
        }

        $table = $this->entityManager->getClassMetadata($entityClass)->getTableName();
        $rows  = $this->entityManager->getConnection()->executeQuery(
            'SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS '
            .'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CHARACTER_MAXIMUM_LENGTH IS NOT NULL',
            [$table],
        )->fetchAllAssociative();

        $byAlias = [];
        foreach ($rows as $row) {
            $byAlias[(string) $row['COLUMN_NAME']] = (int) $row['CHARACTER_MAXIMUM_LENGTH'];
        }

        return $this->maxLengthsByObject[$object] = $byAlias;
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
