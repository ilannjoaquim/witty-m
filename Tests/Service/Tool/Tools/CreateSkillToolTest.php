<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittySkill;
use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateSkillTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * create_skill alimente une bibliotheque PARTAGEE (nom+description restent en
 * permanence dans le prompt systeme, cf. PromptBuilder : ne l'appeler que sur
 * demande explicite). Cas dedies : le refus d'un nom deja pris (read_skill/
 * update_skill ne savent resoudre un skill que par nom exact, un doublon
 * serait ambigu) et la description auto-derivee du contenu si absente.
 */
class CreateSkillToolTest extends TestCase
{
    public function testNameIsRequired(): void
    {
        $manager = $this->createMock(SkillManager::class);

        $output = $this->tool($manager, false)->execute(['name' => '', 'content' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testContentIsRequired(): void
    {
        $manager = $this->createMock(SkillManager::class);

        $output = $this->tool($manager, false)->execute(['name' => 'Cold Outreach', 'content' => '   ']);

        $this->assertSame('error', $output['status']);
    }

    public function testDuplicateNameIsRejected(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->with('Cold Outreach')->willReturn(new WittySkill());
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, false)->execute(['name' => 'Cold Outreach', 'content' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testDescriptionDefaultsToStartOfContentWhenOmitted(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn(null);
        $manager->expects($this->once())->method('save')->with($this->callback(
            static fn (WittySkill $skill): bool => str_starts_with($skill->getDescription(), 'Playbook complet'),
        ));

        $output = $this->tool($manager, false)->execute([
            'name' => 'Cold Outreach', 'content' => 'Playbook complet sur la prospection a froid.',
        ]);

        $this->assertSame('ok', $output['status']);
    }

    public function testConfirmationIsRequiredBeforeSaving(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn(null);
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, true)->execute(['name' => 'Onboarding', 'content' => 'Contenu.']);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testSavesOnceConfirmed(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn(null);
        $manager->expects($this->once())->method('save');

        $output = $this->tool($manager, true)->execute(['name' => 'Onboarding', 'content' => 'Contenu.', 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
    }

    private function tool(SkillManager $manager, bool $requiresConfirmation): CreateSkillTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new CreateSkillTool($manager, $config);
    }
}
