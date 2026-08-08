<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Cree witty_attachments : pieces jointes du chat (image, tableur, document...).
 *
 * conversation_id et message_id sont nullables : l'upload se fait avant l'envoi
 * du message (le fichier doit etre pret quand l'utilisateur clique Envoyer),
 * donc avant que la conversation existe forcement en base. Voir
 * Entity/WittyAttachment.php pour le detail du cycle de vie.
 *
 * Le SQL reproduit exactement ce que genere SchemaTool pour Entity/WittyAttachment.php.
 */
class Version_2_6_0 extends AbstractMigration
{
    private const TABLE = 'witty_attachments';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $attachments = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$attachments} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                user_id INT UNSIGNED DEFAULT NULL,
                conversation_id INT UNSIGNED DEFAULT NULL,
                message_id INT UNSIGNED DEFAULT NULL,
                original_filename VARCHAR(191) NOT NULL,
                stored_filename VARCHAR(191) NOT NULL,
                mime_type VARCHAR(191) NOT NULL,
                extension VARCHAR(191) NOT NULL,
                kind VARCHAR(191) NOT NULL,
                size INT NOT NULL,
                asset_id INT DEFAULT NULL,
                date_added DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE, ['user_id'])},
                {$this->generateIndexStatement(self::TABLE, ['conversation_id'])},
                {$this->generateIndexStatement(self::TABLE, ['message_id'])},
                INDEX {$this->concatPrefix('witty_attachment_date_added')} (date_added),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB ROW_FORMAT = DYNAMIC
        ");

        // SET NULL : un compte supprime ne doit pas faire echouer sa propre
        // suppression a cause d'un fichier joint oublie (meme motif que
        // witty_audit_log.user_id).
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['user_id'],
            'users',
            ['id'],
            'ON DELETE SET NULL'
        ));

        // CASCADE : un fichier joint n'a aucune valeur une fois sa conversation
        // (ou son message) supprime.
        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['conversation_id'],
            'witty_conversations',
            ['id'],
            'ON DELETE CASCADE'
        ));

        $this->addSql($this->generateAlterTableForeignKeyStatement(
            self::TABLE,
            ['message_id'],
            'witty_messages',
            ['id'],
            'ON DELETE CASCADE'
        ));
    }
}
