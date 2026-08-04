<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree la table de reservation de creneaux (witty_meet_bookings), pour le
 * champ de formulaire "Creneau de rendez-vous". Le SQL reproduit ce que
 * genere SchemaTool pour Entity/WittyMeetBooking.php.
 */
class Version_2_2_0 extends AbstractMigration
{
    private const TABLE = 'witty_meet_bookings';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $bookings = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$bookings} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                field_id INT UNSIGNED NOT NULL,
                lead_id BIGINT UNSIGNED DEFAULT NULL,
                slot_start DATETIME NOT NULL,
                room_id VARCHAR(191) DEFAULT NULL,
                date_added DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE, ['field_id'])},
                {$this->generateIndexStatement(self::TABLE, ['lead_id'])},
                INDEX {$this->concatPrefix('witty_meet_booking_slot_start')} (slot_start),
                UNIQUE INDEX {$this->concatPrefix('witty_meet_booking_unique_slot')} (field_id, slot_start),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['field_id'],
            'form_fields',
            ['id'],
            'ON DELETE CASCADE'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['lead_id'],
            'leads',
            ['id'],
            'ON DELETE SET NULL'
        ));
    }
}
