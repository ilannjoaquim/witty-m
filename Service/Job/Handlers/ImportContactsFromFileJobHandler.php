<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Contact\ContactImporter;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Job\SpreadsheetRowMapper;

/**
 * Importe un tableur joint (CSV/XLS/XLSX) de n'importe quel volume, par lots
 * en arriere-plan — pendant du synchrone ImportLeadsFromFileTool (plafonne a
 * 500 lignes, pense pour une liste rapide depuis le chat) pour le cas ou le
 * fichier depasse ce que le tour de chat peut absorber (ex. 9 949 lignes,
 * export Apollo), avec en plus le rattachement a un segment que le chemin
 * synchrone ne propose pas.
 *
 * IMPORTANT, verifie en session : contrairement a la plupart des services du
 * plugin, AttachmentManager::resolve()/readSpreadsheetAll() dependent
 * indirectement de l'utilisateur HTTP courant (UserHelper::getUser(), via
 * requireUser()) — verifie ici que ce mecanisme renvoie un User "invite"
 * (id=null) en contexte CLI (witty:jobs:process, sans session), ce qui ferait
 * echouer resolve() a CHAQUE tick. Ce handler recupere donc l'attachment
 * directement via l'EntityManager (jamais via resolve()), et ne s'appuie sur
 * AttachmentManager que pour readSpreadsheetAll(WittyAttachment $attachment)
 * — cette methode-la, verifiee, ne touche a aucun moment a l'utilisateur
 * courant (uniquement attachment->getKind()/getStoredFilename()).
 *
 * Pas de pre-ingestion dans WittyBackgroundJobItem (contrairement au design
 * "un job de recherche produit des items, un job d'import les consomme" des
 * autres handlers) : le fichier est re-lu et re-tranche par offset a CHAQUE
 * appel de processChunk() plutot que parse une seule fois. Mesure en session
 * sur un vrai fichier de 9 949 lignes (CSV et XLSX) : ~1.5-1.8s par lecture
 * complete, negligeable face au budget de 50s d'un passage de cron meme
 * relu plusieurs fois — largement plus simple qu'une etape d'ingestion
 * separee, sans le cout de stockage d'un doublon de 9 949 lignes en JSON.
 */
class ImportContactsFromFileJobHandler implements JobHandlerInterface
{
    public const TYPE = 'import_contacts_from_file';

    private const BATCH_SIZE = 50;

    public function __construct(
        private AttachmentManager $attachments,
        private ContactImporter $importer,
        private ListModel $listModel,
        private EntityManagerInterface $em,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function getType(): string
    {
        return self::TYPE;
    }

    public function allowsMultiplePassesPerTick(): bool
    {
        // Aucun appel API externe : uniquement une lecture de fichier local
        // et des ecritures Mautic internes (meme justification que
        // ImportContactsFromJobHandler).
        return true;
    }

    public function processChunk(WittyBackgroundJob $job): void
    {
        $params       = $job->getParams();
        $attachmentId = (int) ($params['attachment_id'] ?? 0);
        $mapping      = (array) ($params['column_mapping'] ?? []);
        $segmentId    = (int) ($params['segment_id'] ?? 0);

        $attachment = $this->em->find(WittyAttachment::class, $attachmentId);

        if (!$attachment instanceof WittyAttachment || $attachment->getUser()?->getId() !== $job->getCreatedBy()?->getId()) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage(sprintf('Piece jointe #%d introuvable (supprimee depuis la bibliotheque Fichiers ?).', $attachmentId));

            return;
        }

        try {
            $data = $this->attachments->readSpreadsheetAll($attachment);
        } catch (AttachmentInvalidException $e) {
            $job->setStatus(WittyBackgroundJob::STATUS_FAILED)->setErrorMessage($e->getMessage());

            return;
        }

        $cursor = $job->getResumeCursor() ?? ['offset' => 0];
        $offset = (int) ($cursor['offset'] ?? 0);
        $batch  = array_slice($data['rows'], $offset, self::BATCH_SIZE);

        if ([] === $batch) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

            return;
        }

        $headerIndex = array_flip($data['headers']);
        $segment     = $segmentId > 0 ? $this->listModel->getEntity($segmentId) : null;
        $segment     = $segment instanceof LeadList ? $segment : null;

        foreach ($batch as $index => $row) {
            $rowNumber = $offset + $index + 2; // +1 en-tete, +1 index base 1 : numero tel qu'il apparait dans le fichier.
            $fields    = SpreadsheetRowMapper::mapRow($row, $headerIndex, $mapping);

            if (null === $fields) {
                $this->recordItem($job, (string) $rowNumber, WittyBackgroundJobItem::STATUS_SKIPPED, null, 'Email manquant ou invalide sur cette ligne.');
                continue;
            }

            $fields = $this->fieldWriteGuard->prepare($fields, 'lead')['fields'];
            $result = $this->importer->importOne($fields, $segment);

            $this->recordItem($job, (string) $rowNumber, WittyBackgroundJobItem::STATUS_SUCCEEDED, [
                'contact_id' => $result['lead']->getId(),
                'email'      => $fields['email'],
                'created'    => $result['created'],
            ]);
        }

        $job->setResumeCursor(['offset' => $offset + count($batch)]);
        $job->setProcessedItems($job->getProcessedItems() + count($batch));

        if (count($batch) < self::BATCH_SIZE) {
            $job->setStatus(WittyBackgroundJob::STATUS_COMPLETED);
        }
    }

    private function recordItem(WittyBackgroundJob $job, string $externalRef, string $status, ?array $data, ?string $error = null): void
    {
        $item = (new WittyBackgroundJobItem())
            ->setJob($job)
            ->setExternalRef($externalRef)
            ->setStatus($status)
            ->setData($data)
            ->setErrorMessage($error);

        $this->em->persist($item);

        if (WittyBackgroundJobItem::STATUS_SUCCEEDED === $status) {
            $job->setSucceededItems($job->getSucceededItems() + 1);
        } else {
            $job->setFailedItems($job->getFailedItems() + 1);
        }
    }
}
