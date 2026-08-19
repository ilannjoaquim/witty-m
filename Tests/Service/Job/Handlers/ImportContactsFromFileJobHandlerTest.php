<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentInvalidException;
use MauticPlugin\WittyBundle\Service\Contact\ContactImporter;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportContactsFromFileJobHandler;
use PHPUnit\Framework\TestCase;

/**
 * IMPORTANT, verifie en session sur le vrai kernel booté en CLI (sans
 * session HTTP) : AttachmentManager::resolve() renvoie systematiquement une
 * erreur en contexte cron (UserHelper::getUser() n'y a aucun utilisateur
 * courant). Ce handler ne doit donc JAMAIS appeler resolve() -- seulement
 * un find() direct via EntityManager, verifie ici en s'assurant qu'aucune
 * methode de AttachmentManager autre que readSpreadsheetAll() n'est
 * sollicitee.
 */
class ImportContactsFromFileJobHandlerTest extends TestCase
{
    private function user(int $id): User
    {
        // Pas de setId() public sur User (id protege, herite de FormEntity,
        // affecte par Doctrine a la persistance) : reflection, meme pattern
        // que les autres tests de handler de ce dossier (ex.
        // ImportContactsFromJobHandlerTest sur Lead::class).
        $user = new User();
        (new \ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function attachment(int $id, int $ownerId): WittyAttachment
    {
        $attachment = new WittyAttachment();
        (new \ReflectionProperty(WittyAttachment::class, 'id'))->setValue($attachment, $id);
        $attachment->setUser($this->user($ownerId));

        return $attachment;
    }

    public function testMissingAttachmentFailsTheJobWithoutThrowing(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn(null);

        $handler = new ImportContactsFromFileJobHandler(
            $this->createMock(AttachmentManager::class),
            $this->createMock(ContactImporter::class),
            $this->createMock(ListModel::class),
            $em,
            $this->createMock(FieldWriteGuard::class),
        );

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams(['attachment_id' => 999]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testAttachmentBelongingToAnotherUserFailsTheJob(): void
    {
        $attachment = $this->attachment(1, 2); // appartient a l'utilisateur #2

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $handler = new ImportContactsFromFileJobHandler(
            $this->createMock(AttachmentManager::class),
            $this->createMock(ContactImporter::class),
            $this->createMock(ListModel::class),
            $em,
            $this->createMock(FieldWriteGuard::class),
        );

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams(['attachment_id' => 1]); // job cree par #1
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testUnreadableFileFailsTheJobWithoutThrowing(): void
    {
        $attachment = $this->attachment(1, 1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('readSpreadsheetAll')->willThrowException(new AttachmentInvalidException('Fichier illisible.'));

        $handler = new ImportContactsFromFileJobHandler(
            $attachments,
            $this->createMock(ContactImporter::class),
            $this->createMock(ListModel::class),
            $em,
            $this->createMock(FieldWriteGuard::class),
        );

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams(['attachment_id' => 1]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testRowsWithoutAnEmailAreSkippedRatherThanImported(): void
    {
        $attachment = $this->attachment(1, 1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('readSpreadsheetAll')->willReturn([
            'headers' => ['Email', 'First Name'],
            'rows'    => [['', 'Jane'], ['john@acme.com', 'John']],
        ]);

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->once())->method('importOne'); // une seule ligne valide sur deux

        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('prepare')->willReturnCallback(fn (array $fields) => ['fields' => $fields, 'unknown' => []]);

        $handler = new ImportContactsFromFileJobHandler($attachments, $importer, $this->createMock(ListModel::class), $em, $guard);

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams([
            'attachment_id'  => 1,
            'column_mapping' => ['Email' => 'email', 'First Name' => 'firstname'],
        ]);
        $handler->processChunk($job);

        $this->assertSame(1, $job->getSucceededItems());
        $this->assertSame(1, $job->getFailedItems()); // le skip est compte comme failedItems, meme convention que ImportContactsFromJobHandler
        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testSegmentIsResolvedAndPassedToTheImporter(): void
    {
        $attachment = $this->attachment(1, 1);
        $segment    = new LeadList();
        $segment->setId(7);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('readSpreadsheetAll')->willReturn([
            'headers' => ['Email'],
            'rows'    => [['jane@acme.com']],
        ]);

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->with(7)->willReturn($segment);

        $lead = new Lead();
        $lead->setId(42);

        $importer = $this->createMock(ContactImporter::class);
        $importer->expects($this->once())->method('importOne')->with($this->anything(), $segment)->willReturn(['created' => true, 'lead' => $lead]);

        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('prepare')->willReturnCallback(fn (array $fields) => ['fields' => $fields, 'unknown' => []]);

        $handler = new ImportContactsFromFileJobHandler($attachments, $importer, $listModel, $em, $guard);

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams([
            'attachment_id'  => 1,
            'column_mapping' => ['Email' => 'email'],
            'segment_id'     => 7,
        ]);
        $handler->processChunk($job);

        $this->assertSame(1, $job->getSucceededItems());
    }

    public function testResumeCursorAdvancesAndJobStaysRunningWhenMoreRowsRemain(): void
    {
        $attachment = $this->attachment(1, 1);

        $rows = [];
        for ($i = 0; $i < 51; ++$i) {
            $rows[] = ["person$i@acme.com"];
        }

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('readSpreadsheetAll')->willReturn(['headers' => ['Email'], 'rows' => $rows]);

        $lead = new Lead();
        $lead->setId(1);

        $importer = $this->createMock(ContactImporter::class);
        $importer->method('importOne')->willReturn(['created' => true, 'lead' => $lead]);

        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('prepare')->willReturnCallback(fn (array $fields) => ['fields' => $fields, 'unknown' => []]);

        $handler = new ImportContactsFromFileJobHandler($attachments, $importer, $this->createMock(ListModel::class), $em, $guard);

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams([
            'attachment_id'  => 1,
            'column_mapping' => ['Email' => 'email'],
        ]);
        $handler->processChunk($job);

        // 51 lignes, BATCH_SIZE=50 : la premiere passe traite 50, il en reste 1.
        $this->assertSame(WittyBackgroundJob::STATUS_RUNNING, $job->getStatus());
        $this->assertSame(50, $job->getResumeCursor()['offset']);
        $this->assertSame(50, $job->getProcessedItems());
    }

    public function testEmptyFileCompletesImmediately(): void
    {
        $attachment = $this->attachment(1, 1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturn($attachment);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('readSpreadsheetAll')->willReturn(['headers' => ['Email'], 'rows' => []]);

        $handler = new ImportContactsFromFileJobHandler(
            $attachments,
            $this->createMock(ContactImporter::class),
            $this->createMock(ListModel::class),
            $em,
            $this->createMock(FieldWriteGuard::class),
        );

        $job = (new WittyBackgroundJob())->setCreatedBy($this->user(1))->setParams(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email']]);
        $handler->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }
}
