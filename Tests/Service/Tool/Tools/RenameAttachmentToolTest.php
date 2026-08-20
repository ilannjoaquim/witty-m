<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Attachment\Exception\AttachmentNotFoundException;
use MauticPlugin\WittyBundle\Service\Tool\Tools\RenameAttachmentTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class RenameAttachmentToolTest extends TestCase
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

        $tool   = new RenameAttachmentTool($attachments, $this->createMock(WittyConfig::class));
        $output = $tool->execute(['attachment_id' => 999, 'filename' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverRenamesWhenConfirmationIsRequired(): void
    {
        $existing = $this->attachment(1, 'ancien-nom.csv');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($existing);
        $attachments->expects($this->never())->method('rename');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool   = new RenameAttachmentTool($attachments, $config);
        $output = $tool->execute(['attachment_id' => 1, 'filename' => 'nouveau-nom.csv']);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testRenamesDirectlyWhenGlobalConfirmationIsDisabled(): void
    {
        $existing = $this->attachment(1, 'ancien-nom.csv');
        $renamed  = $this->attachment(1, 'nouveau-nom.csv');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($existing);
        $attachments->expects($this->once())->method('rename')->with($existing, 'nouveau-nom.csv')->willReturn($renamed);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool   = new RenameAttachmentTool($attachments, $config);
        $output = $tool->execute(['attachment_id' => 1, 'filename' => 'nouveau-nom.csv']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('nouveau-nom.csv', $output['filename']);
    }

    public function testConfirmedRenamesEvenWhenConfirmationIsRequired(): void
    {
        $existing = $this->attachment(1, 'ancien-nom.csv');
        $renamed  = $this->attachment(1, 'nouveau-nom.csv');

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('resolve')->with(1)->willReturn($existing);
        $attachments->expects($this->once())->method('rename')->willReturn($renamed);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool   = new RenameAttachmentTool($attachments, $config);
        $output = $tool->execute(['attachment_id' => 1, 'filename' => 'nouveau-nom.csv', 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
    }
}
