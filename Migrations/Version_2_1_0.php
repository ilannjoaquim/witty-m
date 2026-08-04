<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree la table de suivi des invitations aux salles plugNmeet
 * (witty_meet_invitations). Le SQL reproduit ce que genere SchemaTool pour
 * Entity/WittyMeetInvitation.php.
 */
class Version_2_1_0 extends AbstractMigration
{
    private const TABLE = 'witty_meet_invitations';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $invitations = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$invitations} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                lead_id BIGINT UNSIGNED NOT NULL,
                room_id VARCHAR(191) NOT NULL,
                room_title VARCHAR(191) DEFAULT NULL,
                token LONGTEXT NOT NULL,
                date_added DATETIME NOT NULL,
                clicked_at DATETIME DEFAULT NULL,
                attended TINYINT(1) NOT NULL,
                attended_at DATETIME DEFAULT NULL,
                reconciled_at DATETIME DEFAULT NULL,
                {$this->generateIndexStatement(self::TABLE, ['lead_id'])},
                INDEX {$this->concatPrefix('witty_meet_invitation_room')} (room_id),
                INDEX {$this->concatPrefix('witty_meet_invitation_date_added')} (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['lead_id'],
            'leads',
            ['id'],
            'ON DELETE CASCADE'
        ));
    }
}
