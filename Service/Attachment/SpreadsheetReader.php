<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Attachment;

use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use PhpOffice\PhpSpreadsheet\IOFactory;

/**
 * Lit un CSV/XLS/XLSX joint au chat. Wrapper mince autour de PhpSpreadsheet
 * (deja une dependance Mautic, cf. exports de rapports) : ni parseur maison,
 * ni nouvelle dependance.
 *
 * PhpSpreadsheet charge le fichier entier en memoire (pas de lecture en
 * flux ici) : acceptable pour des listes de leads de quelques centaines de
 * lignes, pas pour un tableur volumineux — d'ou le plafond de lignes impose
 * par l'appelant (voir ImportLeadsFromFileTool).
 */
final class SpreadsheetReader
{
    /**
     * Premiere ligne = en-tetes. Toutes les valeurs sont converties en chaine.
     *
     * @return array{headers: string[], rows: array<int, array<int, string>>}
     */
    public function readAll(string $path): array
    {
        try {
            $spreadsheet = IOFactory::load($path);
        } catch (\Throwable $e) {
            throw new AttachmentInvalidException(sprintf('Fichier illisible comme tableur (%s).', $e->getMessage()), 0, $e);
        }

        $sheet = $spreadsheet->getActiveSheet();
        $grid  = $sheet->toArray(null, true, true, false);

        if ([] === $grid) {
            return ['headers' => [], 'rows' => []];
        }

        $headers = array_map(
            static fn (mixed $cell): string => trim((string) $cell),
            array_shift($grid),
        );

        $rows = array_map(
            static fn (array $row): array => array_map(static fn (mixed $cell): string => trim((string) $cell), $row),
            $grid,
        );

        // Une ligne entierement vide (queue de fichier CSV avec des sauts de
        // ligne en trop) ne doit pas compter comme une ligne de donnees.
        $rows = array_values(array_filter(
            $rows,
            static fn (array $row): bool => [] !== array_filter($row, static fn (string $cell): bool => '' !== $cell),
        ));

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * @return array{headers: string[], rows: array<int, array<int, string>>, totalRows: int}
     */
    public function preview(string $path, int $maxRows = 20): array
    {
        $data = $this->readAll($path);

        return [
            'headers'   => $data['headers'],
            'rows'      => array_slice($data['rows'], 0, $maxRows),
            'totalRows' => count($data['rows']),
        ];
    }
}
