<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree witty_apollo_waterfall_requests : suivi des demandes d'enrichissement
 * Apollo "waterfall" (email et/ou telephone), asynchrones par nature — cf.
 * Entity/WittyApolloWaterfallRequest.php pour le detail du cycle de vie
 * (pending a la requete initiale, completed/failed a la reception du webhook
 * Controller/ApolloWaterfallWebhookController.php).
 *
 * Le SQL reproduit ce que genere SchemaTool pour Entity/WittyApolloWaterfallRequest.php.
 */
class Version_2_9_0 extends AbstractMigration
{
    private const TABLE = 'witty_apollo_waterfall_requests';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                request_id VARCHAR(191) NOT NULL,
                mode VARCHAR(191) NOT NULL,
                status VARCHAR(191) NOT NULL,
                label VARCHAR(191) NOT NULL,
                result JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                date_added DATETIME NOT NULL,
                date_completed DATETIME DEFAULT NULL,
                {$this->generateIndexStatement(self::TABLE, ['lead_id'])},
                {$this->generateIndexStatement(self::TABLE, ['created_by'])},
                UNIQUE INDEX {$this->concatPrefix('witty_apollo_waterfall_request_id')} (request_id),
                INDEX {$this->concatPrefix('witty_apollo_waterfall_date_added')} (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['lead_id'],
            'leads',
            ['id'],
            'ON DELETE SET NULL'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['created_by'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));
    }
}
