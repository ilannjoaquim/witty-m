<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportContactsFromFileJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartContactsImportFromFileTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class StartContactsImportFromFileToolTest extends TestCase
{
    private function attachment(int $id): WittyAttachment
    {
        $attachment = new WittyAttachment();
        (new \ReflectionProperty(WittyAttachment::class, 'id'))->setValue($attachment, $id);
        $attachment->setOriginalFilename('apollo-export.csv');

        return $attachment;
    }

    private function guard(): FieldWriteGuard
    {
        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn([]);
        $guard->method('prepare')->willReturnCallback(fn (array $fields) => ['fields' => $fields, 'unknown' => []]);

        return $guard;
    }

    public function testMissingEmailInMappingIsRejectedBeforeReadingTheFile(): void
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->expects($this->never())->method('resolve');

        $tool   = new StartContactsImportFromFileTool($attachments, $this->createMock(ListModel::class), $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $this->guard(), $this->createMock(WittyConfig::class));
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Prenom' => 'firstname']]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnknownFieldAliasIsRejectedBeforeReadingTheFile(): void
    {
        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn(['bogus_alias']);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->expects($this->never())->method('resolve');

        $tool   = new StartContactsImportFromFileTool($attachments, $this->createMock(ListModel::class), $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $guard, $this->createMock(WittyConfig::class));
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email', 'X' => 'bogus_alias']]);

        $this->assertSame('error', $output['status']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverCreatesAJob(): void
    {
        $attachment = $this->attachment(1);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($attachment);
        $attachments->method('readSpreadsheetAll')->willReturn([
            'headers' => ['Email', 'First Name'],
            'rows'    => [['jane@acme.com', 'Jane'], ['', 'NoEmail']],
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool   = new StartContactsImportFromFileTool($attachments, $this->createMock(ListModel::class), $em, $this->createMock(UserHelper::class), $this->guard(), $config);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email', 'First Name' => 'firstname']]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(2, $output['preview']['total_rows']);
        $this->assertSame(1, $output['preview']['valid_count']);
    }

    public function testConfirmedCreatesAJobWithNoRowCap(): void
    {
        $attachment = $this->attachment(1);

        // 9949 lignes, largement au-dela des 500 d'import_leads_from_file :
        // le point precis rapporte en session (l agent bloque devant un
        // export Apollo de cette taille).
        $rows = [];
        for ($i = 0; $i < 9949; ++$i) {
            $rows[] = ["person$i@acme.com"];
        }

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->willReturn($attachment);
        $attachments->method('readSpreadsheetAll')->willReturn(['headers' => ['Email'], 'rows' => $rows]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(function ($job): bool {
            return ImportContactsFromFileJobHandler::TYPE === $job->getType()
                && 9949 === $job->getTotalItems()
                && 1 === $job->getParams()['attachment_id'];
        }));
        $em->expects($this->once())->method('flush');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool   = new StartContactsImportFromFileTool($attachments, $this->createMock(ListModel::class), $em, $this->createMock(UserHelper::class), $this->guard(), $config);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email']]);

        $this->assertSame('ok', $output['status']);
    }

    public function testUnknownSegmentIsRejected(): void
    {
        $attachment = $this->attachment(1);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->willReturn($attachment);
        $attachments->method('readSpreadsheetAll')->willReturn(['headers' => ['Email'], 'rows' => [['jane@acme.com']]]);

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->with(999)->willReturn(null);

        $tool   = new StartContactsImportFromFileTool($attachments, $listModel, $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $this->guard(), $this->createMock(WittyConfig::class));
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email'], 'segment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testSegmentNameAppearsInThePreviewWhenProvided(): void
    {
        $attachment = $this->attachment(1);
        $segment    = new LeadList();
        $segment->setName('Prospects US');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->willReturn($attachment);
        $attachments->method('readSpreadsheetAll')->willReturn(['headers' => ['Email'], 'rows' => [['jane@acme.com']]]);

        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->with(7)->willReturn($segment);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool   = new StartContactsImportFromFileTool($attachments, $listModel, $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $this->guard(), $config);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email'], 'segment_id' => 7]);

        $this->assertSame('Prospects US', $output['preview']['segment']);
    }
}
