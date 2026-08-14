<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateContactTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * L essentiel (dedoublonnage par email, refus du doublon) est trivial et pas
 * teste ici : ce qui merite un test dedie, c est le rejet d un alias de champ
 * qui n existe pas AVANT toute ecriture (cf. FieldWriteGuard, ajoute apres
 * qu un alias invente par l agent — linkedin_url au lieu de linkedin — se
 * soit perdu en silence en production).
 */
class CreateContactToolTest extends TestCase
{
    public function testUnknownFieldAliasIsRejectedWithoutSaving(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->never())->method('saveEntity');

        $config = $this->createMock(WittyConfig::class);

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => [], 'unknown' => ['linkedin_url']]);

        $tool = new CreateContactTool($leadModel, $config, $fieldWriteGuard);
        $output = $tool->execute(['email' => 'jane@example.test', 'fields' => ['linkedin_url' => 'https://linkedin.com/in/jane']]);

        $this->assertSame('error', $output['status']);
    }

    public function testKnownFieldsAreWrittenAsNormalizedByTheGuard(): void
    {
        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->willReturn([]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->expects($this->once())->method('setFieldValues')
            ->with($this->anything(), ['email' => 'jane@example.test', 'country' => 'France'], false, false);
        $leadModel->expects($this->once())->method('saveEntity');

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => ['country' => 'France'], 'unknown' => []]);

        $tool = new CreateContactTool($leadModel, $config, $fieldWriteGuard);
        $output = $tool->execute(['email' => 'jane@example.test', 'fields' => ['country' => 'FR']]);

        $this->assertSame('ok', $output['status']);
    }
}
