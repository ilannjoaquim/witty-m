<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittySkill;
use MauticPlugin\WittyBundle\Service\Skill\SkillManager;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateSkillTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * update_skill identifie sa cible par nom exact (SkillManager::findByName(),
 * insensible a la casse) — jamais par id, que l'agent ne voit nulle part pour
 * un skill. Chaque champ fourni remplace l'existant en entier ; un champ
 * absent ne doit pas bouger.
 */
class UpdateSkillToolTest extends TestCase
{
    public function testUnknownSkillIsReported(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn(null);

        $output = $this->tool($manager, false)->execute(['name' => 'Nope', 'content' => 'x']);

        $this->assertSame('error', $output['status']);
    }

    public function testNoFieldsProvidedIsRejected(): void
    {
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn($this->existingSkill());
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, false)->execute(['name' => 'Cold Outreach']);

        $this->assertSame('error', $output['status']);
    }

    public function testContentIsReplacedInPlace(): void
    {
        $skill   = $this->existingSkill();
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn($skill);
        $manager->expects($this->once())->method('save')->with($skill);

        $output = $this->tool($manager, false)->execute(['name' => 'Cold Outreach', 'content' => 'Nouveau contenu.']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('Nouveau contenu.', $skill->getContent());
    }

    public function testFieldsNotProvidedAreLeftUntouched(): void
    {
        $skill   = $this->existingSkill();
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn($skill);

        $this->tool($manager, false)->execute(['name' => 'Cold Outreach', 'description' => 'Nouvelle description']);

        $this->assertSame('Cold Outreach', $skill->getName(), 'name non fourni : ne doit pas bouger.');
        $this->assertSame('Contenu original.', $skill->getContent(), 'content non fourni : ne doit pas bouger.');
        $this->assertSame('Nouvelle description', $skill->getDescription());
    }

    public function testRenameUsesNewName(): void
    {
        $skill   = $this->existingSkill();
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn($skill);

        $output = $this->tool($manager, false)->execute(['name' => 'Cold Outreach', 'new_name' => 'Cold Outreach v2']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('Cold Outreach v2', $skill->getName());
    }

    public function testConfirmationIsRequiredBeforeSaving(): void
    {
        $skill   = $this->existingSkill();
        $manager = $this->createMock(SkillManager::class);
        $manager->method('findByName')->willReturn($skill);
        $manager->expects($this->never())->method('save');

        $output = $this->tool($manager, true)->execute(['name' => 'Cold Outreach', 'content' => 'Nouveau.']);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame('Contenu original.', $skill->getContent(), 'Rien ne doit changer avant confirmation.');
    }

    private function existingSkill(): WittySkill
    {
        $skill = new WittySkill();
        $skill->setName('Cold Outreach');
        $skill->setContent('Contenu original.');

        return $skill;
    }

    private function tool(SkillManager $manager, bool $requiresConfirmation): UpdateSkillTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn($requiresConfirmation);

        return new UpdateSkillTool($manager, $config);
    }
}
