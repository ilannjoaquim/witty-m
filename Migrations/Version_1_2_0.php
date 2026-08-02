<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree les tables de persistance (conversations, messages, journal d'audit).
 *
 * Une installation neuve obtient ces tables depuis les metadonnees Doctrine
 * (SchemaTool, a l'installation du plugin) ; cette migration couvre les
 * instances ou WittyBundle etait deja installe en 1.0.x, ou seul un changement
 * de version declenche `Engine::up()`.
 *
 * Le SQL reproduit exactement ce que genere SchemaTool pour Entity/.
 */
class Version_1_2_0 extends AbstractMigration
{
    private const TABLE_CONVERSATIONS = 'witty_conversations';
    private const TABLE_MESSAGES      = 'witty_messages';
    private const TABLE_AUDIT         = 'witty_audit_log';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE_CONVERSATIONS));
    }

    protected function up(): void
    {
        $conversations = $this->concatPrefix(self::TABLE_CONVERSATIONS);
        $messages      = $this->concatPrefix(self::TABLE_MESSAGES);
        $audit         = $this->concatPrefix(self::TABLE_AUDIT);
        $users         = $this->concatPrefix('users');

        $this->addSql("
            CREATE TABLE {$conversations} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                title VARCHAR(191) NOT NULL,
                provider VARCHAR(191) DEFAULT NULL,
                model VARCHAR(191) DEFAULT NULL,
                prompt_tokens INT NOT NULL,
                completion_tokens INT NOT NULL,
                date_added DATETIME NOT NULL,
                date_modified DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE_CONVERSATIONS, ['user_id'])},
                INDEX {$this->concatPrefix('witty_conversation_modified')} (date_modified),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql("
            CREATE TABLE {$messages} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                conversation_id INT UNSIGNED NOT NULL,
                sequence INT NOT NULL,
                role VARCHAR(191) NOT NULL,
                content LONGTEXT DEFAULT NULL,
                tool_calls JSON DEFAULT NULL COMMENT '(DC2Type:json)',
                tool_call_id VARCHAR(191) DEFAULT NULL,
                tool_name VARCHAR(191) DEFAULT NULL,
                prompt_tokens INT NOT NULL,
                completion_tokens INT NOT NULL,
                date_added DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE_MESSAGES, ['conversation_id'])},
                INDEX {$this->concatPrefix('witty_message_date_added')} (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql("
            CREATE TABLE {$audit} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                conversation_id INT UNSIGNED DEFAULT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                user_name VARCHAR(191) NOT NULL,
                tool VARCHAR(191) NOT NULL,
                write_operation TINYINT(1) NOT NULL,
                arguments JSON NOT NULL COMMENT '(DC2Type:json)',
                status VARCHAR(191) NOT NULL,
                object_type VARCHAR(191) DEFAULT NULL,
                object_id INT DEFAULT NULL,
                message LONGTEXT DEFAULT NULL,
                duration_ms INT NOT NULL,
                date_added DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE_AUDIT, ['conversation_id'])},
                {$this->generateIndexStatement(self::TABLE_AUDIT, ['user_id'])},
                INDEX {$this->concatPrefix('witty_audit_date_added')} (date_added),
                INDEX {$this->concatPrefix('witty_audit_tool')} (tool),
                INDEX {$this->concatPrefix('witty_audit_object')} (object_type, object_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE_MESSAGES,
            ['conversation_id'],
            self::TABLE_CONVERSATIONS,
            ['id'],
            'ON DELETE CASCADE'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE_CONVERSATIONS,
            ['user_id'],
            'users',
            ['id'],
            'ON DELETE CASCADE'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE_AUDIT,
            ['conversation_id'],
            self::TABLE_CONVERSATIONS,
            ['id'],
            'ON DELETE SET NULL'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE_AUDIT,
            ['user_id'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));
    }
}
