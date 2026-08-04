<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Ajoute categorie/projets aux skills et cree la table des salles (witty_rooms) :
 * plugNmeet n'a aucune notion d'utilisateur Mautic, de categorie ou de projet,
 * cette table stocke les metadonnees cote Mautic pour chaque salle plugNmeet
 * (cf. Entity/WittyRoom.php, Service/Videoconference/RoomManager.php).
 */
class Version_2_4_0 extends AbstractMigration
{
    private const ROOMS_TABLE = 'witty_rooms';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::ROOMS_TABLE));
    }

    protected function up(): void
    {
        $skills = $this->concatPrefix('witty_skills');

        $this->addSql("ALTER TABLE {$skills} ADD category_id INT UNSIGNED DEFAULT NULL");
        $this->addSql("{$this->generateAlterTableForeignKeyStatement(
            'witty_skills',
            ['category_id'],
            'categories',
            ['id'],
            'ON DELETE SET NULL'
        )}");

        $skillProjectsXref = $this->concatPrefix('witty_skill_projects_xref');
        $this->addSql("
            CREATE TABLE {$skillProjectsXref} (
                skill_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                {$this->generateIndexStatement('witty_skill_projects_xref', ['project_id'])},
                PRIMARY KEY(skill_id, project_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            'witty_skill_projects_xref',
            ['skill_id'],
            'witty_skills',
            ['id'],
            'ON DELETE CASCADE'
        ));
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            'witty_skill_projects_xref',
            ['project_id'],
            'projects',
            ['id'],
            'ON DELETE CASCADE'
        ));

        $rooms = $this->concatPrefix(self::ROOMS_TABLE);
        $this->addSql("
            CREATE TABLE {$rooms} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                category_id INT UNSIGNED DEFAULT NULL,
                room_id VARCHAR(191) NOT NULL,
                title VARCHAR(191) NOT NULL,
                date_added DATETIME NOT NULL,
                date_modified DATETIME NOT NULL,
                {$this->generateIndexStatement(self::ROOMS_TABLE, ['created_by'])},
                {$this->generateIndexStatement(self::ROOMS_TABLE, ['category_id'])},
                UNIQUE INDEX {$this->concatPrefix('witty_room_room_id')} (room_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::ROOMS_TABLE,
            ['created_by'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::ROOMS_TABLE,
            ['category_id'],
            'categories',
            ['id'],
            'ON DELETE SET NULL'
        ));

        $roomProjectsXref = $this->concatPrefix('witty_room_projects_xref');
        $this->addSql("
            CREATE TABLE {$roomProjectsXref} (
                witty_room_id INT UNSIGNED NOT NULL,
                project_id INT UNSIGNED NOT NULL,
                {$this->generateIndexStatement('witty_room_projects_xref', ['project_id'])},
                PRIMARY KEY(witty_room_id, project_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            'witty_room_projects_xref',
            ['witty_room_id'],
            self::ROOMS_TABLE,
            ['id'],
            'ON DELETE CASCADE'
        ));
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            'witty_room_projects_xref',
            ['project_id'],
            'projects',
            ['id'],
            'ON DELETE CASCADE'
        ));
    }
}
