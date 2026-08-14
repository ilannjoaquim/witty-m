<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CreateCompanyTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class CreateCompanyToolTest extends TestCase
{
    public function testUnknownFieldAliasIsRejectedWithoutSaving(): void
    {
        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->expects($this->never())->method('saveEntity');

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => [], 'unknown' => ['companylinkedin_url']]);

        $tool = new CreateCompanyTool($companyModel, $this->createMock(WittyConfig::class), $fieldWriteGuard);
        $output = $tool->execute(['name' => 'Acme', 'fields' => ['companylinkedin_url' => 'https://linkedin.com/company/acme']]);

        $this->assertSame('error', $output['status']);
    }
}
