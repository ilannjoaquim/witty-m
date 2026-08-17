<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Service\Job\Handlers\DeduplicateContactsJobHandler;
use MauticPlugin\WittyBundle\Service\Lead\DuplicateContactGroupFinder;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartDeduplicateContactsTool;
use PHPUnit\Framework\TestCase;

class StartDeduplicateContactsToolTest extends TestCase
{
    public function testNoDuplicatesFoundReturnsOkWithoutCreatingAJob(): void
    {
        $finder = $this->createMock(DuplicateContactGroupFinder::class);
        $finder->method('find')->willReturn([]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $tool   = new StartDeduplicateContactsTool($finder, $em, $this->createMock(UserHelper::class));
        $output = $tool->execute([]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(0, $output['groups_found']);
    }

    public function testWithoutConfirmedReturnsAPreviewAndNeverCreatesAJob(): void
    {
        $finder = $this->createMock(DuplicateContactGroupFinder::class);
        $finder->method('find')->willReturn([
            ['field' => 'email', 'ids' => [1, 2, 3]],
            ['field' => 'email', 'ids' => [4, 5]],
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $tool   = new StartDeduplicateContactsTool($finder, $em, $this->createMock(UserHelper::class));
        $output = $tool->execute([]);

        $this->assertSame('confirmation_required', $output['status']);
        $this->assertSame(2, $output['preview']['groups_found']);
        // (3-1) + (5-1) = 3 contacts a fusionner au total
        $this->assertSame(3, $output['preview']['contacts_a_fusionner']);
    }

    public function testConfirmedCreatesAJobWithTheGroupsAsParams(): void
    {
        $finder = $this->createMock(DuplicateContactGroupFinder::class);
        $finder->method('find')->willReturn([
            ['field' => 'email', 'ids' => [1, 2, 3]],
        ]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(function ($job): bool {
            return DeduplicateContactsJobHandler::TYPE === $job->getType()
                && [[1, 2, 3]] === $job->getParams()['groups']
                && 2 === $job->getTotalItems();
        }));
        $em->expects($this->once())->method('flush');

        $tool   = new StartDeduplicateContactsTool($finder, $em, $this->createMock(UserHelper::class));
        $output = $tool->execute(['confirmed' => true]);

        $this->assertSame('ok', $output['status']);
    }
}
