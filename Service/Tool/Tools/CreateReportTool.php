<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\ReportBundle\Entity\Report;
use Mautic\ReportBundle\Model\ReportModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un rapport tabulaire : source de donnees + colonnes selectionnees.
 *
 * Les sources et colonnes disponibles dependent des bundles installes (chaque
 * bundle enregistre les siennes) : on les decouvre via list_sources /
 * list_columns plutot que de les coder en dur. Filtres, agregats et graphiques
 * ne sont pas geres ici ; le rapport cree reste modifiable depuis l interface.
 */
class CreateReportTool extends AbstractTool
{
    public function __construct(
        private ReportModel $reportModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_report';
    }

    public function getDescription(): string
    {
        return 'Cree un rapport tabulaire. Appeler d abord avec list_sources=true pour connaitre les sources '
            .'de donnees disponibles (contacts, entreprises, emails...), puis avec list_columns=<source> '
            .'pour connaitre les colonnes disponibles pour cette source, avant de creer avec source et columns.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'report:reports:create';
    }

    public function getObjectType(): ?string
    {
        return 'report';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'list_sources' => ['type' => 'boolean', 'description' => 'true pour lister les sources de donnees disponibles.'],
            'list_columns' => ['type' => 'string', 'description' => 'Nom d une source : liste ses colonnes disponibles, sans rien creer.'],
            'name'         => ['type' => 'string'],
            'description'  => ['type' => 'string'],
            'source'       => ['type' => 'string', 'description' => 'Source de donnees, obtenue via list_sources.'],
            'columns'      => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Colonnes a inclure, obtenues via list_columns.',
            ],
        ], []);
    }

    public function execute(array $arguments): array
    {
        if (true === ($arguments['list_sources'] ?? false)) {
            return $this->ok(['sources' => $this->availableSources()]);
        }

        $columnsSource = trim((string) ($arguments['list_columns'] ?? ''));

        if ('' !== $columnsSource) {
            return $this->columnsForSource($columnsSource);
        }

        $name    = trim((string) ($arguments['name'] ?? ''));
        $source  = trim((string) ($arguments['source'] ?? ''));
        $columns = array_values(array_unique(array_map('strval', (array) ($arguments['columns'] ?? []))));

        if ('' === $name || '' === $source || [] === $columns) {
            return ['status' => 'error', 'error' => 'name, source et columns sont obligatoires.'];
        }

        if (!array_key_exists($source, $this->availableSources())) {
            return ['status' => 'error', 'error' => sprintf('Source inconnue : %s. Utilise list_sources pour la liste.', $source)];
        }

        $validColumns = array_keys((array) $this->columnsForSource($source)['columns']);
        $unknown      = array_diff($columns, $validColumns);

        if ([] !== $unknown) {
            return ['status' => 'error', 'error' => sprintf('Colonnes inconnues pour %s : %s.', $source, implode(', ', $unknown))];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'report',
                'name'    => $name,
                'source'  => $source,
                'columns' => $columns,
            ]);
        }

        $report = new Report();
        $report->setName($name);
        $report->setDescription((string) ($arguments['description'] ?? ''));
        $report->setSource($source);
        $report->setColumns($columns);

        $this->reportModel->saveEntity($report);

        return $this->ok([
            'id'     => $report->getId(),
            'name'   => $report->getName(),
            'source' => $source,
            'url'    => '/s/reports/edit/'.$report->getId(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function availableSources(): array
    {
        $tables  = (array) $this->reportModel->buildAvailableReports('all')['tables'];
        $sources = [];

        foreach ($tables as $context => $data) {
            $sources[$context] = (string) ($data['group'] ?? $context);
        }

        return $sources;
    }

    /**
     * @return array<string, mixed>
     */
    private function columnsForSource(string $source): array
    {
        if (!array_key_exists($source, $this->availableSources())) {
            return ['status' => 'error', 'error' => sprintf('Source inconnue : %s. Utilise list_sources pour la liste.', $source)];
        }

        $list = $this->reportModel->getColumnList($source);

        return $this->ok(['source' => $source, 'columns' => (array) $list->choices]);
    }
}
