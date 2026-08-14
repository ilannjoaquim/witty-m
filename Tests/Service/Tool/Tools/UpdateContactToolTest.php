<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateContactTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class UpdateContactToolTest extends TestCase
{
    public function testUnknownFieldAliasIsRejectedBeforeResolvingTheContact(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('getEntity');
        $leadModel->expects($this->never())->method('saveEntity');

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => [], 'unknown' => ['linkedin_url']]);

        $tool = new UpdateContactTool($leadModel, $this->createMock(WittyConfig::class), $fieldWriteGuard);
        $output = $tool->execute(['contact_id' => 1, 'fields' => ['linkedin_url' => 'https://linkedin.com/in/jane']]);

        $this->assertSame('error', $output['status']);
    }

    public function testCountryValueIsNormalizedByTheGuardBeforeWriting(): void
    {
        $lead = new Lead();

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);
        $leadModel->expects($this->once())->method('setFieldValues')->with($lead, ['country' => 'France'], false, false);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => ['country' => 'France'], 'unknown' => []]);

        $tool = new UpdateContactTool($leadModel, $config, $fieldWriteGuard);
        $output = $tool->execute(['contact_id' => 1, 'fields' => ['country' => 'FR']]);

        $this->assertSame('ok', $output['status']);
    }
}
