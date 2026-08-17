<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Lead;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Field\FieldsWithUniqueIdentifier;

/**
 * Detecte les groupes de contacts en double, en reutilisant EXACTEMENT la
 * meme definition qu'Mautic lui-meme : les champs coches "identifiant
 * unique" dans les reglages de champs (object=lead uniquement -- meme filtre
 * que LeadModel::checkForDuplicateContact(), qui s'en sert pour detecter un
 * doublon un par un a l'import/en formulaire). Ici, la meme notion sert a
 * les ENUMERER en masse (une requete GROUP BY par champ plutot qu'un
 * aller-retour par contact), indispensable a l'echelle de 55 000 contacts.
 *
 * IMPORTANT, verifie en session sur la base locale : un champ peut etre
 * marque identifiant unique pour l'objet 'company' (ex. companyname, present
 * par defaut sur cette instance) sans rien dire sur deux CONTACTS -- deux
 * personnes qui travaillent pour la meme entreprise ne sont pas la meme
 * personne. Ne considerer QUE object='lead' evite de fusionner des gens
 * differents sous ce seul pretexte.
 */
class DuplicateContactGroupFinder
{
    public function __construct(
        private EntityManagerInterface $em,
        private FieldsWithUniqueIdentifier $fieldsWithUniqueIdentifier,
    ) {
    }

    /**
     * @return array<int, array{field: string, ids: array<int, int>}> Un groupe par
     *         cluster de doublons detecte. ids[0] = le contact le plus ancien
     *         du groupe (le survivant naturel), le reste = les doublons a
     *         fusionner dedans.
     */
    public function find(): array
    {
        $fields  = $this->fieldsWithUniqueIdentifier->getFieldsWithUniqueIdentifier(['object' => 'lead']);
        $columns = $this->realColumns();

        $groups = [];

        foreach (array_keys($fields) as $alias) {
            if (!isset($columns[$alias])) {
                // Champ marque identifiant unique mais sans colonne reelle
                // correspondante sur `leads` (config incoherente cote Mautic) :
                // ignore plutot que de planter.
                continue;
            }

            $groups = [...$groups, ...$this->groupsForColumn($alias)];
        }

        return $this->mergeOverlappingGroups($groups);
    }

    /**
     * Colonnes reelles de `leads`, verifiees via INFORMATION_SCHEMA plutot que
     * supposees a partir de l'alias -- meme principe que FieldWriteGuard : ne
     * jamais faire confiance a une metadonnee Mautic sans verifier la
     * structure reelle de la table.
     *
     * @return array<string, string> alias => alias, uniquement ceux qui existent
     *         vraiment comme colonne
     */
    private function realColumns(): array
    {
        $table = $this->em->getClassMetadata(Lead::class)->getTableName();

        $names = $this->em->getConnection()->executeQuery(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        )->fetchFirstColumn();

        return array_combine($names, $names);
    }

    /**
     * @return array<int, array{field: string, ids: array<int, int>}>
     */
    private function groupsForColumn(string $column): array
    {
        // $column vient de realColumns(), verifie contre INFORMATION_SCHEMA
        // juste avant : jamais une valeur fournie par l'agent/l'utilisateur,
        // interpolation sans risque d'injection.
        $table = $this->em->getClassMetadata(Lead::class)->getTableName();
        $sql   = sprintf(
            "SELECT GROUP_CONCAT(id ORDER BY id ASC) AS ids FROM %s WHERE %s IS NOT NULL AND %s <> '' GROUP BY %s HAVING COUNT(*) > 1",
            $table,
            $column,
            $column,
            $column,
        );

        $rows   = $this->em->getConnection()->executeQuery($sql)->fetchFirstColumn();
        $groups = [];

        foreach ($rows as $csv) {
            $groups[] = ['field' => $column, 'ids' => array_map('intval', explode(',', (string) $csv))];
        }

        return $groups;
    }

    /**
     * Un contact peut apparaitre dans deux groupes distincts si plusieurs
     * champs sont marques identifiant unique (ex. email ET telephone) :
     * fusionne les groupes qui partagent au moins un id en un seul, par
     * composantes connexes (union-find), pour ne jamais risquer de fusionner
     * deux fois le meme contact dans le meme job (le perdant du premier
     * passage n'existerait plus au second).
     *
     * @param array<int, array{field: string, ids: array<int, int>}> $groups
     *
     * @return array<int, array{field: string, ids: array<int, int>}>
     */
    private function mergeOverlappingGroups(array $groups): array
    {
        $parent = [];

        $find = function (int $id) use (&$parent, &$find): int {
            if (!isset($parent[$id])) {
                $parent[$id] = $id;
            }

            return $parent[$id] === $id ? $id : ($parent[$id] = $find($parent[$id]));
        };

        foreach ($groups as $group) {
            $root = $find($group['ids'][0]);

            foreach ($group['ids'] as $id) {
                $idRoot = $find($id);

                if ($idRoot !== $root) {
                    $parent[max($idRoot, $root)] = min($idRoot, $root);
                    $root                        = $find($root);
                }
            }
        }

        $clusters = [];

        foreach ($groups as $group) {
            foreach ($group['ids'] as $id) {
                $root                                 = $find($id);
                $clusters[$root]['ids'][$id]           = $id;
                $clusters[$root]['fields'][$group['field']] = $group['field'];
            }
        }

        $result = [];

        foreach ($clusters as $cluster) {
            $ids = array_values($cluster['ids']);
            sort($ids);
            $result[] = ['field' => implode('+', array_values($cluster['fields'])), 'ids' => $ids];
        }

        return $result;
    }
}
