<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateTemplateTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * create_template alimente une bibliotheque PARTAGEE, reutilisee par toutes
 * les conversations futures (cf. PromptBuilder : ne l appeler que sur
 * demande explicite). Les cas qui meritent un test dedie : la normalisation
 * des emplacements (cle en majuscules, cle obligatoire) et le flux de
 * confirmation, communs a update_template.
 */
class CreateTemplateToolTest extends TestCase
{
    public function testNameIsRequired(): void
    {
        $tool = $this->tool($this->createMock(TemplateManager::class), false);

        $output = $tool->execute(['type' => 'email', 'name' => '', 'html' => '<p>x</p>']);

        $this->assertSame('error', $output['status']);
    }

    public function testHtmlIsRequired(): void
    {
        $tool = $this->tool($this->createMock(TemplateManager::class), false);

        $output = $tool->execute(['type' => 'email', 'name' => 'Webinar', 'html' => '   ']);

        $this->assertSame('error', $output['status']);
    }

    public function testPlaceholderWithoutKeyIsRejected(): void
    {
        $tool = $this->tool($this->createMock(TemplateManager::class), false);

        $output = $tool->execute([
            'type' => 'email', 'name' => 'Webinar', 'html' => '<p>x</p>',
            'placeholders' => [['label' => 'Sans cle']],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testPlaceholderKeysAreUppercased(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->expects($this->once())->method('save')->with($this->callback(
            static function (WittyTemplate $template): bool {
                return 'HEADLINE' === $template->getPlaceholders()[0]['key'];
            },
        ));

        $tool = $this->tool($manager, false);

        $output = $tool->execute([
            'type' => 'email', 'name' => 'Webinar', 'html' => '<p>{{HEADLINE}}</p>',
            'placeholders' => [['key' => 'headline', 'label' => 'Headline']],
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testConfirmationIsRequiredBeforeSaving(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->expects($this->never())->method('save');

        $tool = $this->tool($manager, true);

        $output = $tool->execute(['type' => 'page', 'name' => 'Landing', 'html' => '<html></html>']);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testSavesOnceConfirmed(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->expects($this->once())->method('save');

        $tool = $this->tool($manager, true);

        $output = $tool->execute(['type' => 'page', 'name' => 'Landing', 'html' => '<html></html>', 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
    }

    public function testDefaultsToEmailTypeWhenTypeIsInvalid(): void
    {
        $manager = $this->createMock(TemplateManager::class);
        $manager->expects($this->once())->method('save')->with($this->callback(
            static fn (WittyTemplate $template): bool => WittyTemplate::TYPE_EMAIL === $template->getType(),
        ));

        $tool = $this->tool($manager, false);

        $output = $tool->execute(['type' => 'not-a-real-type', 'name' => 'x', 'html' => '<p>x</p>']);

        $this->assertSame('ok', $output['status']);
    }

    private function tool(TemplateManager $manager, bool $requiresConfirmation): CreateTemplateTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new CreateTemplateTool($manager, $config);
    }
}
