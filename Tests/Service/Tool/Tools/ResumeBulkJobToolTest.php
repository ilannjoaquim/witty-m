<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Tool\Tools\ResumeBulkJobTool;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Le point qui compte ici n'est PAS le mecanisme de reprise lui-meme (deja
 * garanti par chaque handler, qui n'avance resumeCursor qu'apres un appel
 * fournisseur reussi) mais que cet outil ne touche JAMAIS resumeCursor : il se
 * contente de repasser le job en QUEUED pour que le prochain tick le reprenne
 * de lui-meme. Egalement teste : le scope par utilisateur (meme regle que tous
 * les autres outils de job), le refus sur un statut autre que failed, et le
 * plafond de tentatives.
 */
class ResumeBulkJobToolTest extends TestCase
{
    public function testJobBelongingToAnotherUserIsTreatedAsNotFound(): void
    {
        $owner  = $this->userWithId(1);
        $viewer = $this->userWithId(2);

        $job = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_FAILED);

        $output = $this->tool($job, $viewer)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
    }

    public function testNonFailedJobIsRejected(): void
    {
        $owner = $this->userWithId(1);
        $job   = (new WittyBackgroundJob())->setType('t')->setLabel('X')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_RUNNING);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
    }

    public function testFailedJobIsRequeuedWithoutTouchingResumeCursor(): void
    {
        $owner = $this->userWithId(1);
        $job = (new WittyBackgroundJob())
            ->setType('t')
            ->setLabel('X')
            ->setCreatedBy($owner)
            ->setStatus(WittyBackgroundJob::STATUS_FAILED)
            ->setErrorMessage('HTTP 500')
            ->setResumeCursor(['page' => 101, 'collected' => 10000])
            ->setSucceededItems(10000);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(WittyBackgroundJob::STATUS_QUEUED, $job->getStatus());
        $this->assertNull($job->getErrorMessage());
        $this->assertSame(['page' => 101, 'collected' => 10000], $job->getResumeCursor());
        $this->assertSame(1, $job->getResumeCount());
        $this->assertSame('HTTP 500', $output['last_error']);
    }

    public function testResumeAttemptsAreCapped(): void
    {
        $owner = $this->userWithId(1);
        $job = (new WittyBackgroundJob())
            ->setType('t')
            ->setLabel('X')
            ->setCreatedBy($owner)
            ->setStatus(WittyBackgroundJob::STATUS_FAILED)
            ->setResumeCount(ResumeBulkJobTool::MAX_RESUME_ATTEMPTS);

        $output = $this->tool($job, $owner)->execute(['job_id' => 42]);

        $this->assertSame('error', $output['status']);
        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    private function tool(WittyBackgroundJob $job, User $user): ResumeBulkJobTool
    {
        $repository = $this->createMock(WittyBackgroundJobRepository::class);
        $repository->method('find')->willReturn($job);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturn($repository);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        return new ResumeBulkJobTool($em, $userHelper);
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }
}
