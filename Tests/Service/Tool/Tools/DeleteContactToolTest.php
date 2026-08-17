<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadRepository;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\DeleteContactTool;
use PHPUnit\Framework\TestCase;

class DeleteContactToolTest extends TestCase
{
    public function testUnknownContactReturnsAnError(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->willReturn(null);

        $tool   = new DeleteContactTool($leadModel, $this->createMock(CorePermissions::class));
        $output = $tool->execute(['contact_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testDeniedPermissionNeverReachesDeleteEntity(): void
    {
        $lead = new Lead();

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);
        $leadModel->expects($this->never())->method('deleteEntity');

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturn(false);

        $tool   = new DeleteContactTool($leadModel, $security);
        $output = $tool->execute(['contact_id' => 1, 'confirmed' => true]);

        $this->assertSame('denied', $output['status']);
    }

    public function testLockedContactIsNeverDeleted(): void
    {
        $lead = new Lead();

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);
        $leadModel->method('isLocked')->with($lead)->willReturn(true);
        $leadModel->expects($this->never())->method('deleteEntity');

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturn(true);

        $tool   = new DeleteContactTool($leadModel, $security);
        $output = $tool->execute(['contact_id' => 1, 'confirmed' => true]);

        $this->assertSame('error', $output['status']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverDeletes(): void
    {
        $lead = new Lead();
        $lead->setEmail('jane@acme.com');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);
        $leadModel->method('isLocked')->willReturn(false);
        $leadModel->expects($this->never())->method('deleteEntity');

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturn(true);

        $tool   = new DeleteContactTool($leadModel, $security);
        $output = $tool->execute(['contact_id' => 1]);

        $this->assertSame('confirmation_required', $output['status']);
    }

    public function testConfirmedDeletesTheContact(): void
    {
        $lead = new Lead();
        $lead->setEmail('jane@acme.com');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntity')->with(1)->willReturn($lead);
        $leadModel->method('isLocked')->willReturn(false);
        $leadModel->expects($this->once())->method('deleteEntity')->with($lead);

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturn(true);

        $tool   = new DeleteContactTool($leadModel, $security);
        $output = $tool->execute(['contact_id' => 1, 'confirmed' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['deleted']);
    }

    public function testContactEmailIsAcceptedAsAnAlternativeToContactId(): void
    {
        $lead = new Lead();
        $lead->setEmail('jane@acme.com');

        $repository = $this->createMock(LeadRepository::class);
        $repository->method('findBy')->with(['email' => 'jane@acme.com'], null, 1)->willReturn([$lead]);

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->never())->method('getEntity');
        $leadModel->method('getRepository')->willReturn($repository);
        $leadModel->method('isLocked')->willReturn(false);

        $security = $this->createMock(CorePermissions::class);
        $security->method('hasEntityAccess')->willReturn(true);

        $tool   = new DeleteContactTool($leadModel, $security);
        $output = $tool->execute(['contact_email' => 'jane@acme.com']);

        $this->assertSame('confirmation_required', $output['status']);
    }
}
