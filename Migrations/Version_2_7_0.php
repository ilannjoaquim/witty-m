<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Ajoute witty_attachments.pinned : distingue un upload fait depuis le
 * trombone du chat (nettoye automatiquement s'il reste orphelin, cf.
 * PruneOrphanAttachmentsCommand) d'un upload fait depuis la bibliotheque
 * "Fichiers" (Controller/FileController.php), destine a rester disponible
 * indefiniment jusqu'a suppression manuelle. Voir Entity/WittyAttachment.php.
 *
 * Ecart volontaire avec SchemaTool : `DEFAULT 0` explicite sur la colonne,
 * que le mapping Doctrine ne declare pas (il s'appuie sur la valeur PHP par
 * defaut de l'entite, jamais lue en SQL). Necessaire ici pour que l'ALTER
 * TABLE reste valide sur une instance existante dont witty_attachments a deja
 * des lignes (MySQL/MariaDB en mode strict refuse un ADD COLUMN NOT NULL sans
 * defaut sur une table non vide). `doctrine:schema:update --dump-sql` propose
 * donc de retirer ce defaut apres coup : sans consequence (chaque
 * WittyAttachment le renseigne explicitement avant insertion), a ignorer.
 */
class Version_2_7_0 extends AbstractMigration
{
    private const TABLE = 'witty_attachments';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->getTable($this->concatPrefix(self::TABLE))->hasColumn('pinned');
    }

    protected function up(): void
    {
        $attachments = $this->concatPrefix(self::TABLE);

        $this->addSql("ALTER TABLE {$attachments} ADD pinned TINYINT(1) NOT NULL DEFAULT 0");
    }
}
