<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Import en masse de contacts depuis un tableur joint au chat (CSV/XLS/XLSX).
 *
 * Synchrone et plafonne (MAX_ROWS) plutot que branche sur le moteur d'import
 * asynchrone natif de Mautic (LeadBundle\Model\ImportModel, pense pour un
 * assistant pas-a-pas + tache de fond cron) : suffisant pour une liste de
 * quelques centaines de leads, largement le cas d'usage vise depuis le chat.
 *
 * Contrairement a create_contact, un doublon d'email est mis a jour plutot
 * que refuse : c'est le comportement attendu d'un import de masse (une
 * deuxieme passe sur le meme fichier ne doit pas echouer ligne par ligne).
 */
class ImportLeadsFromFileTool extends AbstractTool
{
    private const MAX_ROWS = 500;

    public function __construct(
        private AttachmentManager $attachments,
        private LeadModel $leadModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'import_leads_from_file';
    }

    public function getDescription(): string
    {
        return 'Importe des contacts depuis un tableur joint (CSV/XLS/XLSX), plafonne a '.self::MAX_ROWS.' lignes. '
            .'Appelle d abord read_attachment pour voir les en-tetes du fichier, puis fournis column_mapping '
            .'(en-tete du fichier -> alias de champ contact Mautic, ex. {"Email":"email","Prenom":"firstname"}). '
            .'Une valeur du mapping doit etre "email". Un contact existant (meme email) est mis a jour, pas duplique.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:create';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'attachment_id'  => ['type' => 'integer', 'description' => 'Identifiant du tableur joint (voir read_attachment).'],
            'column_mapping' => [
                'type'        => 'object',
                'description' => 'En-tete de colonne du fichier -> alias de champ contact Mautic. Une valeur doit etre "email".',
            ],
        ], ['attachment_id', 'column_mapping']);
    }

    public function execute(array $arguments): array
    {
        $mapping = array_map('strval', (array) ($arguments['column_mapping'] ?? []));

        if ([] === $mapping) {
            return ['status' => 'error', 'error' => 'column_mapping est obligatoire.'];
        }

        if (!in_array('email', $mapping, true)) {
            return ['status' => 'error', 'error' => 'column_mapping doit mapper une colonne sur "email".'];
        }

        try {
            $attachment = $this->attachments->resolve((int) ($arguments['attachment_id'] ?? 0));
            $data       = $this->attachments->readSpreadsheetAll($attachment);
        } catch (AttachmentNotFoundException|AttachmentInvalidException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        if (count($data['rows']) > self::MAX_ROWS) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'Fichier trop volumineux pour un import direct (%d lignes, maximum %d). Scinde-le en plusieurs fichiers.',
                    count($data['rows']),
                    self::MAX_ROWS,
                ),
            ];
        }

        $headerIndex    = array_flip($data['headers']);
        $unknownColumns = array_values(array_diff(array_keys($mapping), $data['headers']));
        $mapped         = $this->mapRows($data['rows'], $mapping, $headerIndex);

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'            => 'bulk_contacts',
                'attachment'      => ['id' => $attachment->getId(), 'filename' => $attachment->getOriginalFilename()],
                'valid_count'     => count($mapped['valid']),
                'skipped_rows'    => $mapped['skipped'],
                'sample'          => array_slice($mapped['valid'], 0, 5),
                'unknown_columns' => $unknownColumns,
            ]);
        }

        [$created, $updated] = $this->importRows($mapped['valid']);

        return $this->ok([
            'created'       => $created,
            'updated'       => $updated,
            'skipped_count' => count($mapped['skipped']),
        ]);
    }

    /**
     * @param array<int, array<int, string>> $rows
     * @param array<string, string>          $mapping
     * @param array<string, int>             $headerIndex
     *
     * @return array{valid: array<int, array<string, string>>, skipped: array<int, int>}
     */
    private function mapRows(array $rows, array $mapping, array $headerIndex): array
    {
        $valid   = [];
        $skipped = [];

        foreach (array_values($rows) as $offset => $row) {
            $fields = [];

            foreach ($mapping as $fileColumn => $targetAlias) {
                $index                = $headerIndex[$fileColumn] ?? null;
                $fields[$targetAlias] = null !== $index ? ($row[$index] ?? '') : '';
            }

            $email = trim((string) ($fields['email'] ?? ''));

            if ('' === $email || !str_contains($email, '@')) {
                // +1 pour la ligne d'en-tete, +1 pour un index base 1 : le
                // numero de ligne tel qu'il apparait dans le fichier d'origine.
                $skipped[] = $offset + 2;
                continue;
            }

            $fields['email'] = $email;
            $valid[]         = $fields;
        }

        return ['valid' => $valid, 'skipped' => $skipped];
    }

    /**
     * @param array<int, array<string, string>> $rows
     *
     * @return array{0: int, 1: int} [created, updated]
     */
    private function importRows(array $rows): array
    {
        $created = 0;
        $updated = 0;

        foreach ($rows as $fields) {
            $existing = $this->leadModel->getRepository()->findBy(['email' => $fields['email']], null, 1);
            $lead     = $existing[0] ?? new Lead();

            $this->leadModel->setFieldValues($lead, $fields, false, false);
            $this->leadModel->saveEntity($lead);

            [] === $existing ? ++$created : ++$updated;
        }

        return [$created, $updated];
    }
}
