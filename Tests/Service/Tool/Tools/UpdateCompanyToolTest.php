<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\Tools\UpdateCompanyTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

class UpdateCompanyToolTest extends TestCase
{
    public function testUnknownFieldAliasIsRejectedBeforeResolvingTheCompany(): void
    {
        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->expects($this->never())->method('getEntity');
        $companyModel->expects($this->never())->method('saveEntity');

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => [], 'unknown' => ['companylinkedin_url']]);

        $tool = new UpdateCompanyTool($companyModel, $this->createMock(WittyConfig::class), $fieldWriteGuard);
        $output = $tool->execute(['company_id' => 1, 'fields' => ['companylinkedin_url' => 'https://linkedin.com/company/acme']]);

        $this->assertSame('error', $output['status']);
    }

    public function testCountryValueIsNormalizedByTheGuardBeforeWriting(): void
    {
        $company = new Company();

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->with(1)->willReturn($company);
        $companyModel->expects($this->once())->method('setFieldValues')->with($company, ['companycountry' => 'France']);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $fieldWriteGuard = $this->createMock(FieldWriteGuard::class);
        $fieldWriteGuard->method('prepare')->willReturn(['fields' => ['companycountry' => 'France'], 'unknown' => []]);

        $tool = new UpdateCompanyTool($companyModel, $config, $fieldWriteGuard);
        $output = $tool->execute(['company_id' => 1, 'fields' => ['companycountry' => 'FR']]);

        $this->assertSame('ok', $output['status']);
    }
}
