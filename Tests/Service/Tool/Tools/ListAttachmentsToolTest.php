<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyAttachment;
use MauticPlugin\WittyBundle\Service\Attachment\AttachmentManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ListAttachmentsTool;
use PHPUnit\Framework\TestCase;

/**
 * Seul comportement propre a cet outil : traduire ses arguments (search/limit)
 * vers AttachmentManager::listForUser() et mettre en forme le resultat. Le
 * filtrage lui-meme (LIKE sur le nom de fichier) vit dans
 * WittyAttachmentRepository::findAllForUser(), pas ici.
 */
class ListAttachmentsToolTest extends TestCase
{
    public function testPassesSearchAndClampsLimit(): void
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->expects($this->once())
            ->method('listForUser')
            ->with('logo', 100)
            ->willReturn([]);

        (new ListAttachmentsTool($attachments))->execute(['search' => 'logo', 'limit' => 500]);
    }

    public function testEmptySearchListsWithoutFiltering(): void
    {
        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->expects($this->once())
            ->method('listForUser')
            ->with(null, 25)
            ->willReturn([]);

        (new ListAttachmentsTool($attachments))->execute([]);
    }

    public function testMapsAttachmentsToTheirAssetUrl(): void
    {
        $attachment = new WittyAttachment();
        $attachment->setOriginalFilename('logo-ete.png')->setKind(WittyAttachment::KIND_IMAGE)->setSize(1234);

        $attachments = $this->createMock(AttachmentManager::class);
        $attachments->method('listForUser')->willReturn([$attachment]);
        $attachments->method('assetUrl')->with($attachment)->willReturn('/asset/1:logo');

        $output = (new ListAttachmentsTool($attachments))->execute(['search' => 'logo']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $output['count']);
        $this->assertSame('logo-ete.png', $output['attachments'][0]['filename']);
        $this->assertSame('/asset/1:logo', $output['attachments'][0]['asset_url']);
    }
}
