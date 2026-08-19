<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job;

/**
 * Applique un column_mapping (en-tete de fichier -> alias de champ contact) a
 * UNE ligne de tableur deja lue par SpreadsheetReader. Fonction pure, sans
 * dependance injectee : reutilisee a l'identique par
 * StartContactsImportFromFileTool (calcul de l'apercu, doit refleter EXACTEMENT
 * ce que le job appliquera) et Service/Job/Handlers/ImportContactsFromFileJobHandler.php
 * (traitement reel, lot par lot) — extraite plutot que dupliquee pour ne
 * jamais risquer un apercu qui divergerait du resultat reel.
 *
 * Ne duplique PAS ImportLeadsFromFileTool::mapRows() (code deja teste/stable,
 * cf. sa propre docblock : reserve au chemin synchronique plafonne a 500
 * lignes) — celui-ci est le pendant pour le chemin asynchrone/volumineux.
 */
final class SpreadsheetRowMapper
{
    /**
     * @param array<int, string>    $row         une ligne de donnees (index numerique = colonne)
     * @param array<string, int>    $headerIndex en-tete de fichier -> index de colonne
     * @param array<string, string> $mapping     en-tete de fichier -> alias de champ contact
     *
     * @return array<string, string>|null null si la ligne n'a pas d'email exploitable (ligne ecartee)
     */
    public static function mapRow(array $row, array $headerIndex, array $mapping): ?array
    {
        $fields = [];

        foreach ($mapping as $fileColumn => $targetAlias) {
            $index                = $headerIndex[$fileColumn] ?? null;
            $fields[$targetAlias] = null !== $index ? ($row[$index] ?? '') : '';
        }

        $email = trim((string) ($fields['email'] ?? ''));

        if ('' === $email || !str_contains($email, '@')) {
            return null;
        }

        $fields['email'] = $email;

        return $fields;
    }
}
