<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportContactsFromFileJobHandler;
use MauticPlugin\WittyBundle\Service\Job\SpreadsheetRowMapper;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Pendant asynchrone d'import_leads_from_file, sans plafond de lignes ET avec
 * rattachement a un segment (ni l un ni l autre disponibles cote synchrone) —
 * repond au manque signale en session : un export Apollo de ~10 000 lignes
 * refuse net par import_leads_from_file (plafonne a 500), sans aucune facon
 * de contourner la limite ni de rattacher les contacts a un segment une fois
 * importes en masse.
 *
 * Cf. Service/Job/Handlers/ImportContactsFromFileJobHandler.php pour le
 * traitement reel (lot par lot, en arriere-plan). Cet outil ne fait que
 * calculer l apercu (lecture complete du fichier, sans plafond — mesure en
 * session : ~1.5-1.8s meme pour ~10 000 lignes, largement dans le temps d un
 * tour de chat) et creer le job.
 */
class StartContactsImportFromFileTool extends AbstractTool
{
    public function __construct(
        private AttachmentManager $attachments,
        private ListModel $listModel,
        private EntityManagerInterface $em,
        private UserHelper $userHelper,
        private FieldWriteGuard $fieldWriteGuard,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'start_contacts_import_from_file';
    }

    public function getDescription(): string
    {
        return 'Importe en arriere-plan des contacts depuis un tableur joint (CSV/XLS/XLSX), SANS plafond de '
            .'lignes (contrairement a import_leads_from_file, limite a 500 et sans rattachement possible a un '
            .'segment) — a utiliser pour un fichier volumineux (ex. export Apollo de plusieurs milliers de '
            .'lignes) ou des que l utilisateur veut rattacher les contacts importes a un segment. Appelle d abord '
            .'read_attachment pour voir les en-tetes exactes du fichier, puis fournis column_mapping (en-tete du '
            .'fichier -> alias de champ contact Mautic, ex. {"Email":"email","First Name":"firstname"}). Une '
            .'valeur du mapping doit etre "email". segment_id (optionnel) rattache chaque contact cree/mis a '
            .'jour au segment. Un contact existant (meme email) est mis a jour, pas duplique. Ne renvoie jamais '
            .'de resultat directement : un job_id a suivre via check_bulk_job, puis list_bulk_job_items pour le '
            .'detail (ex. quelles lignes ont ete ecartees faute d email).';
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
            'segment_id' => ['type' => 'integer', 'description' => 'Segment existant auquel rattacher les contacts crees/mis a jour.'],
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

        $unknownAliases = $this->fieldWriteGuard->unknownAliases(array_values($mapping), 'lead');

        if ([] !== $unknownAliases) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu dans column_mapping : %s. Verifie l orthographe avec l outil list_fields (object: 'contact') avant de reessayer.",
                    implode(', ', $unknownAliases),
                ),
            ];
        }

        try {
            $attachment = $this->attachments->resolve((int) ($arguments['attachment_id'] ?? 0));
            $data       = $this->attachments->readSpreadsheetAll($attachment);
        } catch (AttachmentNotFoundException|AttachmentInvalidException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        if ([] === $data['rows']) {
            return ['status' => 'error', 'error' => 'Ce fichier ne contient aucune ligne de donnees exploitable.'];
        }

        $segmentId = (int) ($arguments['segment_id'] ?? 0);
        $segment   = null;

        if ($segmentId > 0) {
            $segment = $this->listModel->getEntity($segmentId);

            if (!$segment instanceof LeadList) {
                return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', $segmentId)];
            }
        }

        $unknownColumns = array_values(array_diff(array_keys($mapping), $data['headers']));
        $headerIndex    = array_flip($data['headers']);

        $sample      = [];
        $skippedRows = [];

        foreach ($data['rows'] as $offset => $row) {
            $fields = SpreadsheetRowMapper::mapRow($row, $headerIndex, $mapping);

            if (null === $fields) {
                $skippedRows[] = $offset + 2; // +1 en-tete, +1 index base 1.
                continue;
            }

            if (count($sample) < 5) {
                $sample[] = $this->fieldWriteGuard->prepare($fields, 'lead')['fields'];
            }
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'type'            => 'contacts_import_from_file',
                'attachment'      => ['id' => $attachment->getId(), 'filename' => $attachment->getOriginalFilename()],
                'total_rows'      => count($data['rows']),
                'valid_count'     => count($data['rows']) - count($skippedRows),
                'skipped_rows'    => [] !== $skippedRows ? $skippedRows : null,
                'sample'          => $sample,
                'unknown_columns' => [] !== $unknownColumns ? $unknownColumns : null,
                'segment'         => $segment?->getName(),
            ], static fn ($value): bool => null !== $value));
        }

        $job = (new WittyBackgroundJob())
            ->setType(ImportContactsFromFileJobHandler::TYPE)
            ->setCreatedBy($this->userHelper->getUser())
            ->setLabel(sprintf(
                'Import fichier %s (%d lignes)%s',
                $attachment->getOriginalFilename(),
                count($data['rows']),
                $segment instanceof LeadList ? ' — segment '.$segment->getName() : '',
            ))
            ->setParams(array_filter([
                'attachment_id'  => $attachment->getId(),
                'column_mapping' => $mapping,
                'segment_id'     => $segmentId > 0 ? $segmentId : null,
            ], static fn ($value): bool => null !== $value))
            ->setTotalItems(count($data['rows']));

        $this->em->persist($job);
        $this->em->flush();

        return $this->ok([
            'job_id'  => $job->getId(),
            'message' => sprintf(
                'Job #%d lance en arriere-plan (%d lignes, ~50 par lot). Utilise check_bulk_job(job_id=%d) pour '
                .'suivre la progression, puis list_bulk_job_items pour le detail (ex. lignes ecartees faute '
                .'d email).',
                $job->getId(),
                count($data['rows']),
                $job->getId(),
            ),
        ]);
    }
}
