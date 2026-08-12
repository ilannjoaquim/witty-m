<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ListBulkJobItemsTool;
use PHPUnit\Framework\TestCase;

/**
 * Deux points propres a cet outil : le scope par proprietaire (meme
 * raisonnement que CheckBulkJobTool) verifie sur l'entite JOB (pas sur
 * chaque item), et la pagination (limit/offset, plafond) transmise telle
 * quelle au repository plutot que recalculee ici.
 */
class ListBulkJobItemsToolTest extends TestCase
{
    public function testJobBelongingToAnotherUserIsRejected(): void
    {
        $owner  = $this->userWithId(1);
        $viewer = $this->userWithId(2);

        $job = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [WittyBackgroundJob::class, $this->jobRepositoryReturning($job)],
        ]);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($viewer);

        $output = (new ListBulkJobItemsTool($em, $userHelper))->execute(['job_id' => 5]);

        $this->assertSame('error', $output['status']);
    }

    public function testItemsArePaginatedAndSerialized(): void
    {
        $user = $this->userWithId(1);
        $job  = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($user);

        $item = (new WittyBackgroundJobItem())->setExternalRef('123')->setStatus('succeeded')->setData(['email' => 'a@b.com']);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->expects($this->once())->method('findForJob')->with(5, null, 50, 0)->willReturn([$item]);
        $itemRepository->method('countForJob')->willReturn(1);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [WittyBackgroundJob::class, $this->jobRepositoryReturning($job)],
            [WittyBackgroundJobItem::class, $itemRepository],
        ]);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        $output = (new ListBulkJobItemsTool($em, $userHelper))->execute(['job_id' => 5]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(1, $output['total']);
        $this->assertSame('123', $output['items'][0]['external_ref']);
        $this->assertSame('a@b.com', $output['items'][0]['data']['email']);
    }

    private function jobRepositoryReturning(WittyBackgroundJob $job): object
    {
        $repository = $this->createMock(\MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        return $repository;
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        $ref  = new \ReflectionProperty(User::class, 'id');
        $ref->setAccessible(true);
        $ref->setValue($user, $id);

        return $user;
    }
}
