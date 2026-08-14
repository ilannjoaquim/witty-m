<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Tool\Tools\CancelBulkJobTool;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Question posee en session : l'agent n'avait aucun moyen d'arreter un job
 * si l'utilisateur se retracte. Le point qui compte ici : cet outil ne
 * touche JAMAIS resumeCursor/succeeded_items/les items deja enregistres —
 * seulement le statut — pour que resume_bulk_job puisse reprendre plus tard
 * exactement ou l'annulation a eu lieu (cf. ResumeBulkJobToolTest).
 */
class CancelBulkJobToolTest extends TestCase
{
    public function testJobBelongingToAnotherUserIsTreatedAsNotFound(): void
    {
        $owner  = $this->userWithId(1);
        $viewer = $this->userWithId(2);

        $job = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_RUNNING);

        $output = $this->tool($job, $viewer)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
    }

    /**
     * @dataProvider terminalStatusProvider
     */
    public function testAlreadyTerminalJobCannotBeCancelledAgain(string $status): void
    {
        $owner = $this->userWithId(1);
        $job   = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner)->setStatus($status);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
        $this->assertSame($status, $job->getStatus());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function terminalStatusProvider(): array
    {
        return [
            'completed' => [WittyBackgroundJob::STATUS_COMPLETED],
            'failed'    => [WittyBackgroundJob::STATUS_FAILED],
            'cancelled' => [WittyBackgroundJob::STATUS_CANCELLED],
        ];
    }

    public function testQueuedJobIsCancelledWithoutTouchingItsProgress(): void
    {
        $owner = $this->userWithId(1);
        $job = (new WittyBackgroundJob())
            ->setType('t')
            ->setLabel('X')
            ->setCreatedBy($owner)
            ->setStatus(WittyBackgroundJob::STATUS_QUEUED)
            ->setResumeCursor(['page' => 12, 'collected' => 1100])
            ->setSucceededItems(1100);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(WittyBackgroundJob::STATUS_CANCELLED, $job->getStatus());
        $this->assertSame(['page' => 12, 'collected' => 1100], $job->getResumeCursor());
        $this->assertSame(1100, $job->getSucceededItems());
    }

    public function testRunningJobCanAlsoBeCancelled(): void
    {
        $owner = $this->userWithId(1);
        $job   = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_RUNNING);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(WittyBackgroundJob::STATUS_CANCELLED, $job->getStatus());
    }

    private function tool(WittyBackgroundJob $job, User $user): CancelBulkJobTool
    {
        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        return new CancelBulkJobTool($em, $userHelper);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
