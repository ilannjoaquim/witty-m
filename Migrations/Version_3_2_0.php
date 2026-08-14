<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Ajoute witty_background_job_items.consumed_at : marque un element deja
 * transmis a Mautic par un import (ImportContactsFromJobHandler/
 * ImportCompaniesFromJobHandler), pour qu'un import ULTERIEUR du meme job
 * source (courant apres un resume_bulk_job qui l'a fait grossir) ne le
 * retraite jamais, evitant un vrai risque de doublon quand le rapprochement
 * se fait par email sans email disponible (cf. docblock de
 * WittyBackgroundJobItem::$consumedAt).
 *
 * Nommee 3.2.0, pas 3.20.0 : meme raison que Version_3_0_0/Version_3_1_0.
 */
class Version_3_2_0 extends AbstractMigration
{
    private const ITEMS_TABLE = 'witty_background_job_items';

    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix(self::ITEMS_TABLE);

        return $schema->hasTable($table) && !$schema->getTable($table)->hasColumn('consumed_at');
    }

    protected function up(): void
    {
        $items = $this->concatPrefix(self::ITEMS_TABLE);

        $this->addSql("ALTER TABLE {$items} ADD consumed_at DATETIME DEFAULT NULL");
    }
}
