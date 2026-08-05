<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Ajoute les tags (meme pool que Contacts, table lead_tags) aux skills et
 * aux salles : comme categorie/projets, c'est un mecanisme de classification
 * Mautic natif reutilise tel quel plutot que reinvente (cf. Entity/WittySkill.php,
 * Entity/WittyRoom.php).
 */
class Version_2_5_0 extends AbstractMigration
{
    private const SKILL_TAGS_XREF = 'witty_skill_tags_xref';
    private const ROOM_TAGS_XREF  = 'witty_room_tags_xref';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::SKILL_TAGS_XREF));
    }

    protected function up(): void
    {
        $skillTagsXref = $this->concatPrefix(self::SKILL_TAGS_XREF);
        $this->addSql("
            CREATE TABLE {$skillTagsXref} (
                skill_id INT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                {$this->generateIndexStatement(self::SKILL_TAGS_XREF, ['tag_id'])},
                PRIMARY KEY(skill_id, tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::SKILL_TAGS_XREF,
            ['skill_id'],
            'witty_skills',
            ['id'],
            'ON DELETE CASCADE'
        ));
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::SKILL_TAGS_XREF,
            ['tag_id'],
            'lead_tags',
            ['id'],
            ''
        ));

        $roomTagsXref = $this->concatPrefix(self::ROOM_TAGS_XREF);
        $this->addSql("
            CREATE TABLE {$roomTagsXref} (
                witty_room_id INT UNSIGNED NOT NULL,
                tag_id INT UNSIGNED NOT NULL,
                {$this->generateIndexStatement(self::ROOM_TAGS_XREF, ['tag_id'])},
                PRIMARY KEY(witty_room_id, tag_id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::ROOM_TAGS_XREF,
            ['witty_room_id'],
            'witty_rooms',
            ['id'],
            'ON DELETE CASCADE'
        ));
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::ROOM_TAGS_XREF,
            ['tag_id'],
            'lead_tags',
            ['id'],
            ''
        ));
    }
}
