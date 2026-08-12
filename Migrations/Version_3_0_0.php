<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree witty_background_jobs et witty_background_job_items : traitement en
 * masse (enrichissement/recherche a volume) par petits lots successifs via
 * Command/ProcessBackgroundJobsCommand.php, cf. Entity/WittyBackgroundJob.php
 * pour le detail du cycle de vie.
 *
 * Le SQL reproduit ce que genere SchemaTool pour ces deux entites.
 *
 * Nommee 3.0.0 (et non 2.10.0) volontairement : Migration\Engine::up()
 * (core Mautic) liste les fichiers via scandir() SANS tri numerique — l'ordre
 * d'execution est donc purement alphabetique. "Version_2_10_0.php" se serait
 * classe AVANT "Version_2_2_0.php" (comparaison caractere par caractere,
 * '1' < '2'), executant potentiellement une migration avant une autre dont
 * elle pourrait dependre. Rester sur un premier segment a un chiffre evite le
 * probleme sans avoir a corriger le comportement du coeur Mautic.
 */
class Version_3_0_0 extends AbstractMigration
{
    private const JOBS_TABLE  = 'witty_background_jobs';
    private const ITEMS_TABLE = 'witty_background_job_items';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::JOBS_TABLE));
    }

    protected function up(): void
    {
        $jobs  = $this->concatPrefix(self::JOBS_TABLE);
        $items = $this->concatPrefix(self::ITEMS_TABLE);

        $this->addSql("
            CREATE TABLE {$jobs} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                type VARCHAR(191) NOT NULL,
                status VARCHAR(191) NOT NULL,
                label VARCHAR(191) NOT NULL,
                params JSON NOT NULL COMMENT '(DC2Type:json)',
                resume_cursor JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                total_items INT DEFAULT NULL,
                processed_items INT NOT NULL,
                succeeded_items INT NOT NULL,
                failed_items INT NOT NULL,
                error_message LONGTEXT DEFAULT NULL,
                date_added DATETIME NOT NULL,
                date_started DATETIME DEFAULT NULL,
                date_completed DATETIME DEFAULT NULL,
                last_tick_at DATETIME DEFAULT NULL,
                {$this->generateIndexStatement(self::JOBS_TABLE, ['created_by'])},
                INDEX {$this->concatPrefix('witty_background_job_status')} (status),
                INDEX {$this->concatPrefix('witty_background_job_date_added')} (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::JOBS_TABLE,
            ['created_by'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));

        $this->addSql("
            CREATE TABLE {$items} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                job_id INT UNSIGNED NOT NULL,
                external_ref VARCHAR(191) NOT NULL,
                status VARCHAR(191) NOT NULL,
                data JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                error_message LONGTEXT DEFAULT NULL,
                date_added DATETIME NOT NULL,
                INDEX {$this->concatPrefix('witty_background_job_item_job_status')} (job_id, status),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::ITEMS_TABLE,
            ['job_id'],
            self::JOBS_TABLE,
            ['id'],
            'ON DELETE CASCADE'
        ));
    }
}
