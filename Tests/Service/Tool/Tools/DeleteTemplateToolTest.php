<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\DeleteTemplateTool;
use PHPUnit\Framework\TestCase;

/**
 * delete_template retire un template a TOUTES les conversations futures :
 * comme delete_entity/manage_tags(delete)/end_meet_room/delete_meet_recording,
 * il exige confirmed=true meme si le mode confirmation global est desactive
 * (pas de dependance a WittyConfig ici, contrairement a create/update_template).
 */
class DeleteTemplateToolTest extends TestCase
{
    public function testUnknownTemplateIsReported(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn(null);

        $output = (new DeleteTemplateTool($manager))->execute(['type' => 'email', 'key' => 'nope']);

        $this->assertSame('error', $output['status']);
    }

    public function testConfirmationIsAlwaysRequired(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($this->existingTemplate());
        $manager->expects($this->never())->method('delete');

        $output = (new DeleteTemplateTool($manager))->execute(['type' => 'email', 'key' => 'webinar']);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testDeletesOnceConfirmed(): void
    {
        $template = $this->existingTemplate();
        $manager  = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($template);
        $manager->expects($this->once())->method('delete')->with($template);

        $output = (new DeleteTemplateTool($manager))->execute(['type' => 'email', 'key' => 'webinar', 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
    }

    private function existingTemplate(): WittyTemplate
    {
        $template = new WittyTemplate();
        $template->setType(WittyTemplate::TYPE_EMAIL);
        $template->setKey('webinar');
        $template->setName('Webinar');

        return $template;
    }
}
