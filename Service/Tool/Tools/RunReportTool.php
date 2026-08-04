<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\ReportBundle\Model\ReportModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Execute un rapport existant et renvoie ses donnees.
 *
 * Plafonne a 50 lignes : un rapport peut porter sur des dizaines de milliers
 * de contacts, renvoyer ca tel quel exploserait le contexte du modele.
 */
class RunReportTool extends AbstractTool
{
    private const MAX_ROWS = 50;

    public function __construct(private ReportModel $reportModel)
    {
    }

    public function getName(): string
    {
        return 'run_report';
    }

    public function getDescription(): string
    {
        return sprintf(
            'Execute un rapport existant et renvoie ses donnees (limite a %d lignes ; utiliser le rapport dans '
                .'l interface Mautic pour l export complet).',
            self::MAX_ROWS,
        );
    }

    public function getRequiredPermission(): ?string
    {
        return 'report:reports:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'report_id' => ['type' => 'integer', 'description' => 'Identifiant du rapport a executer.'],
        ], ['report_id']);
    }

    public function execute(array $arguments): array
    {
        $id     = (int) ($arguments['report_id'] ?? 0);
        $report = 0 !== $id ? $this->reportModel->getEntity($id) : null;

        if (null === $report) {
            return ['status' => 'error', 'error' => sprintf('Rapport #%d introuvable.', $id)];
        }

        $result = $this->reportModel->getReportData($report);
        $rows   = array_slice((array) $result['data'], 0, self::MAX_ROWS);

        return $this->ok([
            'id'            => $report->getId(),
            'name'          => $report->getName(),
            'total_results' => (int) $result['totalResults'],
            'returned_rows' => count($rows),
            'rows'          => $rows,
        ]);
    }
}
