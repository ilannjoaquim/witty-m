<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Mautic\IntegrationsBundle\Migration\AbstractMigration;
use MauticPlugin\WittyBundle\Service\Template\BuiltInTemplateLoader;

/**
 * Cree la table des templates (witty_templates) geres depuis la section
 * Witty > Templates (cf. Entity/WittyTemplate.php, Controller/TemplateController.php) :
 * remplace l'ancienne bibliotheque livree en fichiers (Templates/Email/,
 * Templates/Page/), desormais lue une seule fois ici pour peupler la table.
 * Le SQL de creation reproduit ce que genere SchemaTool pour Entity/WittyTemplate.php.
 *
 * `isApplicable()` ne verifie que l'existence de la table : le seed qui suit
 * ne s'execute donc qu'a la creation initiale, jamais sur une instance qui a
 * deja la table (une mise a jour ulterieure du plugin ne doit pas ecraser des
 * templates modifies/supprimes depuis l'UI).
 */
class Version_2_8_0 extends AbstractMigration
{
    private const TABLE = 'witty_templates';

    protected function isApplicable(Schema $schema): bool
    {
        return !$schema->hasTable($this->concatPrefix(self::TABLE));
    }

    protected function up(): void
    {
        $table = $this->concatPrefix(self::TABLE);

        $this->addSql("
            CREATE TABLE {$table} (
                id INT UNSIGNED AUTO_INCREMENT NOT NULL,
                created_by INT UNSIGNED DEFAULT NULL,
                type VARCHAR(191) NOT NULL,
                template_key VARCHAR(191) NOT NULL,
                name VARCHAR(191) NOT NULL,
                description VARCHAR(191) NOT NULL,
                goal LONGTEXT NOT NULL,
                rules JSON NOT NULL,
                placeholders JSON NOT NULL,
                html LONGTEXT NOT NULL,
                date_added DATETIME NOT NULL,
                date_modified DATETIME NOT NULL,
                {$this->generateIndexStatement(self::TABLE, ['created_by'])},
                INDEX {$this->concatPrefix('witty_template_type')} (type),
                UNIQUE INDEX {$this->concatPrefix('witty_template_type_key')} (type, template_key),
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

        $this->seedBuiltInTemplates($table);
    }

    /**
     * Reprend les 4 templates jusqu'ici livres en fichiers, pour qu'ils
     * restent disponibles a l'agent (list_email_templates / list_page_templates)
     * et deviennent immediatement modifiables/supprimables depuis l'UI, comme
     * n'importe quel template cree apres coup.
     */
    private function seedBuiltInTemplates(string $table): void
    {
        $connection = $this->entityManager->getConnection();
        $now        = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

        foreach (BuiltInTemplateLoader::all() as $template) {
            $columns = [
                'type'          => $template->getType(),
                'template_key'  => $template->getKey(),
                'name'          => $template->getName(),
                'description'   => $template->getDescription(),
                'goal'          => $template->getGoal(),
                'rules'         => json_encode($template->getRules(), JSON_THROW_ON_ERROR),
                'placeholders'  => json_encode($template->getPlaceholders(), JSON_THROW_ON_ERROR),
                'html'          => $template->getHtml(),
                'date_added'    => $now,
                'date_modified' => $now,
            ];

            $values = implode(', ', array_map(
                static fn (string $value): string => $connection->quote($value),
                $columns,
            ));

            $this->addSql("INSERT INTO {$table} (".implode(', ', array_keys($columns)).") VALUES ({$values})");
        }
    }
}
