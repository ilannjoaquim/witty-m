<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateTemplateTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * update_template identifie sa cible par (type, key) — ce que
 * list_email_templates/list_page_templates exposent deja a l'agent — jamais
 * par un id numerique. Chaque champ fourni remplace l'existant en entier :
 * le cas a couvrir est qu'un champ absent des arguments ne touche pas au
 * champ existant, et qu'aucun champ fourni est rejete plutot que de faire un
 * no-op silencieux.
 */
class UpdateTemplateToolTest extends TestCase
{
    public function testUnknownTemplateIsReported(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn(null);

        $output = $this->tool($manager, false)->execute(['type' => 'email', 'key' => 'nope', 'name' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testNoFieldsProvidedIsRejected(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($this->existingTemplate());
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, false)->execute(['type' => 'email', 'key' => 'webinar']);

        $this->assertSame('error', $output['status']);
    }

    public function testHtmlIsReplacedInPlace(): void
    {
        $template = $this->existingTemplate();
        $manager  = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($template);
        $manager->expects($this->once())->method('save')->with($template);

        $output = $this->tool($manager, false)->execute([
            'type' => 'email', 'key' => 'webinar', 'html' => '<p>nouveau</p>',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('<p>nouveau</p>', $template->getHtml());
    }

    public function testFieldsNotProvidedAreLeftUntouched(): void
    {
        $template = $this->existingTemplate();
        $manager  = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($template);

        $this->tool($manager, false)->execute(['type' => 'email', 'key' => 'webinar', 'goal' => 'Nouvel objectif']);

        $this->assertSame('Original', $template->getName(), 'name non fourni : ne doit pas bouger.');
        $this->assertSame('<p>original</p>', $template->getHtml(), 'html non fourni : ne doit pas bouger.');
        $this->assertSame('Nouvel objectif', $template->getGoal());
    }

    public function testConfirmationIsRequiredBeforeSaving(): void
    {
        $template = $this->existingTemplate();
        $manager  = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($template);
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, true)->execute(['type' => 'email', 'key' => 'webinar', 'html' => '<p>nouveau</p>']);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame('<p>original</p>', $template->getHtml(), 'Rien ne doit changer avant confirmation.');
    }

    public function testPlaceholderWithoutKeyIsRejected(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->method('findByTypeAndKey')->willReturn($this->existingTemplate());
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, false)->execute([
            'type' => 'email', 'key' => 'webinar', 'placeholders' => [['label' => 'sans cle']],
        ]);

        $this->assertSame('error', $output['status']);
    }

    private function existingTemplate(): WittyTemplate
    {
        $template = new WittyTemplate();
        $template->setType(WittyTemplate::TYPE_EMAIL);
        $template->setKey('webinar');
        $template->setName('Original');
        $template->setHtml('<p>original</p>');

        return $template;
    }

    private function tool(TemplateManager $manager, bool $requiresConfirmation): UpdateTemplateTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new UpdateTemplateTool($manager, $config);
    }
}
