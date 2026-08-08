<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ImportLeadsFromFileTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Le mapping de colonnes, le plafond de lignes et le dedupe par email sont la
 * logique propre a cet outil (tout le reste vient de AttachmentManager/LeadModel,
 * deja couverts ou deja convention Mautic) : c'est ce qui est teste ici.
 */
class ImportLeadsFromFileToolTest extends TestCase
{
    public function testMissingEmailMappingIsRejectedBeforeReadingTheFile(): void
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->expects($this->never())->method('resolve');

        $tool = $this->tool($attachments, $this->createMock(LeadModel::class), true);

        $output = $tool->execute([
            'attachment_id'  => 1,
            'column_mapping' => ['Prenom' => 'firstname'],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testFileAboveTheRowCapIsRejectedWithoutImporting(): void
    {
        $rows = array_fill(0, 501, ['x@example.test']);

        $attachments = $this->attachmentsResolving(['headers' => ['Email'], 'rows' => $rows]);
        $leadModel   = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $tool   = $this->tool($attachments, $leadModel, true);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email']]);

        $this->assertSame('error', $output['status']);
    }

    public function testInvalidRowsAreSkippedAndValidRowsPreviewedBeforeConfirmation(): void
    {
        $attachments = $this->attachmentsResolving([
            'headers' => ['Email', 'Prenom'],
            'rows'    => [
                ['jane@example.test', 'Jane'],
                ['pas-un-email', 'Oups'],
                ['', 'Vide'],
            ],
        ]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('saveEntity');

        $tool   = $this->tool($attachments, $leadModel, true);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email', 'Prenom' => 'firstname']]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(1, $output['preview']['valid_count']);
        $this->assertCount(2, $output['preview']['skipped_rows']);
        $this->assertSame([['email' => 'jane@example.test', 'firstname' => 'Jane']], $output['preview']['sample']);
    }

    public function testConfirmedImportCreatesNewContactsAndUpdatesExistingOnes(): void
    {
        $attachments = $this->attachmentsResolving([
            'headers' => ['Email'],
            'rows'    => [['new@example.test'], ['existing@example.test']],
        ]);

        $existingLead = new Lead();

        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturnCallback(
            static fn (array $criteria): array => 'existing@example.test' === $criteria['email'] ? [$existingLead] : [],
        );

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->exactly(2))->method('setFieldValues');
        $leadModel->expects($this->exactly(2))->method('saveEntity');

        $tool   = $this->tool($attachments, $leadModel, true);
        $output = $tool->execute([
            'attachment_id'  => 1,
            'column_mapping' => ['Email' => 'email'],
            'confirmed'      => true,
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $output['created']);
        $this->assertSame(1, $output['updated']);
    }

    public function testConfirmationNotRequiredWhenGloballyDisabled(): void
    {
        $attachments = $this->attachmentsResolving(['headers' => ['Email'], 'rows' => [['a@b.test']]]);

        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->once())->method('saveEntity');

        $tool   = $this->tool($attachments, $leadModel, false);
        $output = $tool->execute(['attachment_id' => 1, 'column_mapping' => ['Email' => 'email']]);

        $this->assertSame('ok', $output['status']);
    }

    /**
     * @param array{headers: string[], rows: array<int, array<int, string>>} $data
     */
    private function attachmentsResolving(array $data): AttachmentManager
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn(new WittyAttachment());
        $attachments->method('readSpreadsheetAll')->willReturn($data);

        return $attachments;
    }

    private function tool(AttachmentManager $attachments, LeadModel $leadModel, bool $requiresConfirmation): ImportLeadsFromFileTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new ImportLeadsFromFileTool($attachments, $leadModel, $config);
    }
}
