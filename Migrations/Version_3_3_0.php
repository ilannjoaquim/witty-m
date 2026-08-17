<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Elargit les six champs "social" par defaut de Mautic (linkedin, facebook,
 * foursquare, instagram, skype, twitter) de varchar(191) a varchar(500).
 *
 * Question posee en session, en reaction directe au fix precedent
 * (FieldWriteGuard tronque une valeur trop longue plutot que de faire
 * planter l'ecrit, cf. Version_3_2_0/son propre changelog) : tronquer un
 * intitule de poste degrade proprement (`position` reste lisible, raccourci),
 * mais tronquer une URL LinkedIn la CASSE (lien mort). Le bon fix pour ces
 * six champs precis, ce n'est pas de mieux tolerer la troncature, c'est de
 * ne quasiment jamais en avoir besoin : 500 caracteres couvre tres largement
 * une URL de profil social meme chargee de parametres de tracking, la ou
 * 191 pouvait deja etre juste pour un lien LinkedIn "propre".
 *
 * FieldWriteGuard n'a besoin d'AUCUN changement de code pour en beneficier :
 * il lit la largeur reelle de la colonne en direct (INFORMATION_SCHEMA),
 * jamais une valeur figee — une fois cette migration appliquee, il verra de
 * lui-meme 500 au lieu de 191 pour ces six alias.
 *
 * Nommee 3.3.0, pas 3.30.0 : meme raison que les migrations precedentes de
 * ce plugin (Migration\Engine::getMigrationFileNames() du coeur Mautic
 * trie par ordre alphabetique, jamais numerique).
 */
class Version_3_3_0 extends AbstractMigration
{
    private const TABLE = 'leads';

    private const COLUMNS = ['linkedin', 'facebook', 'foursquare', 'instagram', 'skype', 'twitter'];

    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix(self::TABLE);

        if (!$schema->hasTable($table)) {
            return false;
        }

        $columns = $schema->getTable($table);

        foreach (self::COLUMNS as $column) {
            // Applicable des qu'au moins UNE des six colonnes existe encore
            // et n'a pas deja ete elargie (idempotent : ne relance jamais un
            // ALTER TABLE deja effectue).
            if ($columns->hasColumn($column) && $columns->getColumn($column)->getLength() < 500) {
                return true;
            }
        }

        return false;
    }

    protected function up(): void
    {
        $table   = $this->concatPrefix(self::TABLE);
        $columns = $this->entityManager->getConnection()->createSchemaManager()->introspectTable($table);

        foreach (self::COLUMNS as $column) {
            // isApplicable() ne garantit que "au moins une" des six colonnes
            // a besoin d'elargissement, jamais "toutes" : ne toucher ici que
            // celles reellement presentes, pour ne jamais planter sur une
            // colonne absente d'une installation non standard.
            if ($columns->hasColumn($column)) {
                $this->addSql("ALTER TABLE {$table} MODIFY {$column} VARCHAR(500) DEFAULT NULL");
            }
        }
    }
}
