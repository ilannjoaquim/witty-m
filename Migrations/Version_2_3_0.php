<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree la table des skills (witty_skills) : playbooks/strategies texte libre
 * que l'agent peut charger a la demande (cf. Service/Tool/Tools/ReadSkillTool.php).
 * Le SQL reproduit ce que genere SchemaTool pour Entity/WittySkill.php.
 */
class Version_2_3_0 extends AbstractMigration
{
    private const TABLE = 'witty_skills';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $skills = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$skills} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                name VARCHAR(191) NOT NULL,
                description VARCHAR(191) NOT NULL,
                content LONGTEXT NOT NULL,
                date_added DATETIME NOT NULL,
                date_modified DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE, ['created_by'])},
                INDEX {$this->concatPrefix('witty_skill_name')} (name),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['created_by'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));
    }
}
