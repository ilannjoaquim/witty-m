<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\Tools\DeleteAttachmentTool;
use PHPUnit\Framework\TestCase;

class DeleteAttachmentToolTest extends TestCase
{
    private function attachment(int $id, string $filename): WittyAttachment
    {
        $attachment = new WittyAttachment();
        (new \ReflectionProperty(WittyAttachment::class, 'id'))->setValue($attachment, $id);
        $attachment->setOriginalFilename($filename);

        return $attachment;
    }

    public function testUnknownAttachmentReturnsAnError(): void
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->willThrowException(new AttachmentNotFoundException('Piece jointe 999 introuvable.'));

        $tool   = new DeleteAttachmentTool($attachments);
        $output = $tool->execute(['attachment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverDeletesEvenIfGlobalConfirmationIsDisabled(): void
    {
        // Meme regle que delete_contact/delete_entity : une suppression exige
        // toujours confirmed=true, quel que soit le mode confirmation global
        // (contrairement a rename_attachment/update_contact).
        $existing = $this->attachment(1, 'fichier.csv');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($existing);
        $attachments->expects($this->never())->method('delete');

        $tool   = new DeleteAttachmentTool($attachments);
        $output = $tool->execute(['attachment_id' => 1]);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testConfirmedDeletesTheAttachment(): void
    {
        $existing = $this->attachment(1, 'fichier.csv');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($existing);
        $attachments->expects($this->once())->method('delete')->with($existing);

        $tool   = new DeleteAttachmentTool($attachments);
        $output = $tool->execute(['attachment_id' => 1, 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['deleted']);
        $this->assertSame('fichier.csv', $output['filename']);
    }
}
