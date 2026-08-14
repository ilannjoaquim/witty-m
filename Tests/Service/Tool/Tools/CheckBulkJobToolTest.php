<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CheckBulkJobTool;
use PHPUnit\Framework\TestCase;

/**
 * Le point qui merite un test dedie : un job_id n'appartenant pas a
 * l'utilisateur courant doit etre traite comme introuvable (jamais expose,
 * ni son existence ni son contenu), meme methode que
 * CheckWaterfallEnrichmentTool pour la meme raison.
 */
class CheckBulkJobToolTest extends TestCase
{
    public function testJobBelongingToAnotherUserIsTreatedAsNotFound(): void
    {
        $owner  = $this->userWithId(1);
        $viewer = $this->userWithId(2);

        $job = (new WittyBackgroundJob())->setType('apollo_bulk_enrich_people')->setLabel('X')->setCreatedBy($owner);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $output = $this->tool($repository, $viewer)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
    }

    public function testOwnJobIsReturnedSerialized(): void
    {
        $user = $this->userWithId(1);

        $job = (new WittyBackgroundJob())
            ->setType('apollo_bulk_enrich_people')
            ->setLabel('Enrichissement segment X')
            ->setCreatedBy($user)
            ->setStatus(WittyBackgroundJob::STATUS_RUNNING)
            ->setTotalItems(300)
            ->setProcessedItems(120);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $output = $this->tool($repository, $user)->execute(['job_id' => 42]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame('running', $output['job']['status']);
        $this->assertSame(120, $output['job']['processed_items']);
    }

    public function testResumeCountIsOmittedWhenNeverResumed(): void
    {
        $user = $this->userWithId(1);
        $job  = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($user);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $output = $this->tool($repository, $user)->execute(['job_id' => 42]);

        $this->assertArrayNotHasKey('resume_count', $output['job']);
    }

    public function testResumeCountIsExposedOnceAJobHasBeenResumed(): void
    {
        $user = $this->userWithId(1);
        $job  = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($user)->setResumeCount(2);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $output = $this->tool($repository, $user)->execute(['job_id' => 42]);

        $this->assertSame(2, $output['job']['resume_count']);
    }

    public function testNoArgumentsListsRecentJobsForCurrentUser(): void
    {
        $user = $this->userWithId(1);
        $job  = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($user);

        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->expects($this->once())->method('findRecentForUser')->with(1, null, 20)->willReturn([$job]);

        $output = $this->tool($repository, $user)->execute([]);

        $this->assertSame('ok', $output['status']);
        $this->assertCount(1, $output['jobs']);
    }

    private function tool(WittyBackgroundJobRepository $repository, User $user): CheckBulkJobTool
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        return new CheckBulkJobTool($em, $userHelper);
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
