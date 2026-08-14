<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;

/**
 * Ajoute witty_background_jobs.resume_count : compte le nombre de fois ou un
 * job FAILED a ete relance via resume_bulk_job (Service/Tool/Tools/ResumeBulkJobTool.php),
 * pour plafonner les tentatives (ResumeBulkJobTool::MAX_RESUME_ATTEMPTS) plutot
 * que de laisser l'agent boucler indefiniment contre un fournisseur en panne
 * prolongee.
 *
 * Nommee 3.1.0, pas 3.10.0 : meme raison que Version_3_0_0 (scandir() sans tri
 * numerique dans Migration\Engine du coeur Mautic, cf. sa docblock).
 */
class Version_3_1_0 extends AbstractMigration
{
    private const JOBS_TABLE = 'witty_background_jobs';

    protected function isApplicable(Schema $schema): bool
    {
        $table = $this->concatPrefix(self::JOBS_TABLE);

        return $schema->hasTable($table) && !$schema->getTable($table)->hasColumn('resume_count');
    }

    protected function up(): void
    {
        $jobs = $this->concatPrefix(self::JOBS_TABLE);

        $this->addSql("ALTER TABLE {$jobs} ADD resume_count INT NOT NULL DEFAULT 0");
    }
}
