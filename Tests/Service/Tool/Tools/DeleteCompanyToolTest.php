<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\DeleteCompanyTool;
use PHPUnit\Framework\TestCase;

class DeleteCompanyToolTest extends TestCase
{
    public function testUnknownCompanyReturnsAnError(): void
    {
        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->willReturn(null);

        $tool   = new DeleteCompanyTool($companyModel, $this->createMock(CorePermissions::class));
        $output = $tool->execute(['company_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testDeniedPermissionNeverReachesDeleteEntity(): void
    {
        $company = new Company();

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->with(1)->willReturn($company);
        $companyModel->expects($this->never())->method('deleteEntity');

        // Contrairement aux contacts, la suppression d'entreprise n'a pas de
        // notion own/other cote coeur Mautic : seul isGranted('...deleteother')
        // est verifie (cf. CompanyController::deleteAction()).
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->with('lead:leads:deleteother')->willReturn(false);

        $tool   = new DeleteCompanyTool($companyModel, $security);
        $output = $tool->execute(['company_id' => 1, 'confirmed' => true]);

        $this->assertSame('denied', $output['status']);
    }

    public function testLockedCompanyIsNeverDeleted(): void
    {
        $company = new Company();

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->with(1)->willReturn($company);
        $companyModel->method('isLocked')->with($company)->willReturn(true);
        $companyModel->expects($this->never())->method('deleteEntity');

        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturn(true);

        $tool   = new DeleteCompanyTool($companyModel, $security);
        $output = $tool->execute(['company_id' => 1, 'confirmed' => true]);

        $this->assertSame('error', $output['status']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverDeletes(): void
    {
        $company = new Company();
        $company->setName('Acme Corp');

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->with(1)->willReturn($company);
        $companyModel->method('isLocked')->willReturn(false);
        $companyModel->expects($this->never())->method('deleteEntity');

        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturn(true);

        $tool   = new DeleteCompanyTool($companyModel, $security);
        $output = $tool->execute(['company_id' => 1]);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testConfirmedDeletesTheCompany(): void
    {
        $company = new Company();
        $company->setName('Acme Corp');

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntity')->with(1)->willReturn($company);
        $companyModel->method('isLocked')->willReturn(false);
        $companyModel->expects($this->once())->method('deleteEntity')->with($company);

        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturn(true);

        $tool   = new DeleteCompanyTool($companyModel, $security);
        $output = $tool->execute(['company_id' => 1, 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['deleted']);
    }
}
