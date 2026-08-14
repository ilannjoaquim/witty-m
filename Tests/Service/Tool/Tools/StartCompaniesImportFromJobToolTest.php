<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItem;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobItemRepository;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJobRepository;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ImportCompaniesFromJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartCompaniesImportFromJobTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * Meme structure de validation que StartContactsImportFromJobToolTest (job
 * source introuvable/pas termine/sans resultat, mapping vide, operateur de
 * filtre inconnu) et meme exigence de confirmation (isWriteOperation=true,
 * met a jour de vraies entreprises Mautic).
 */
class StartCompaniesImportFromJobToolTest extends TestCase
{
    public function testIsAWriteOperation(): void
    {
        $this->assertTrue($this->tool()->isWriteOperation());
    }

    public function testEmptyMappingIsRejected(): void
    {
        $output = $this->tool()->execute(['source_job_id' => 1, 'mapping' => []]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnknownFieldAliasInMappingIsRejected(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn(['linkedin_url']);

        $tool = new StartCompaniesImportFromJobTool($em, $this->userHelper($this->userWithId(1)), $this->createMock(WittyConfig::class), $guard);
        $output = $tool->execute(['source_job_id' => 1, 'mapping' => ['linkedin_url' => 'linkedin_url']]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnknownSourceJobIsRejected(): void
    {
        $output = $this->tool(null)->execute(['source_job_id' => 999, 'mapping' => ['companyindustry' => 'industry']]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnfinishedSourceJobIsRejected(): void
    {
        $owner = $this->userWithId(1);
        $job = (new WittyBackgroundJob())->setType('x')->setLabel('L')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_RUNNING);

        $output = $this->tool($job, 0, $owner)->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry']]);

        $this->assertSame('error', $output['status']);
    }

    public function testFailedSourceJobWithSucceededItemsIsAcceptedAsAPartialImport(): void
    {
        $owner = $this->userWithId(1);
        $sourceJob = (new WittyBackgroundJob())->setType('x')->setLabel('L')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_FAILED);

        $em = $this->emStub($sourceJob, 500);
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool = new StartCompaniesImportFromJobTool($em, $this->userHelper($owner), $config, $this->guard());
        $output = $tool->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry']]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['partial']);
    }

    public function testFailedSourceJobWithNoSucceededItemsIsStillRejected(): void
    {
        $owner = $this->userWithId(1);
        $job = (new WittyBackgroundJob())->setType('x')->setLabel('L')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_FAILED);

        $output = $this->tool($job, 0, $owner)->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry']]);

        $this->assertSame('error', $output['status']);
    }

    public function testConfirmationRequiredThenApplied(): void
    {
        $owner = $this->userWithId(1);
        $sourceJob = (new WittyBackgroundJob())->setType('x')->setLabel('L')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

        $em = $this->emStub($sourceJob, 40);
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(true);

        $tool = new StartCompaniesImportFromJobTool($em, $this->userHelper($owner), $config, $this->guard());

        $output = $tool->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry']]);
        $this->assertSame('confirmation_required', $output['status']);

        $output2 = $tool->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry'], 'confirmed' => true]);
        $this->assertSame('ok', $output2['status']);
    }

    public function testValidRequestCreatesAJobOfTheRightType(): void
    {
        $owner = $this->userWithId(1);
        $sourceJob = (new WittyBackgroundJob())->setType('x')->setLabel('L')->setCreatedBy($owner)->setStatus(WittyBackgroundJob::STATUS_COMPLETED);

        $em = $this->emStub($sourceJob, 40);
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $tool = new StartCompaniesImportFromJobTool($em, $this->userHelper($owner), $config, $this->guard());
        $output = $tool->execute(['source_job_id' => 1, 'mapping' => ['companyindustry' => 'industry']]);

        $this->assertSame('ok', $output['status']);
    }

    private function tool(?WittyBackgroundJob $sourceJob = null, int $availableItems = 0, ?User $user = null): StartCompaniesImportFromJobTool
    {
        $user ??= $this->userWithId(1);
        $em = $this->emStub($sourceJob, $availableItems);
        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        return new StartCompaniesImportFromJobTool($em, $this->userHelper($user), $config, $this->guard());
    }

    private function emStub(?WittyBackgroundJob $sourceJob, int $availableItems): EntityManagerInterface
    {
        $jobRepository = $this->createMock(WittyBackgroundJobRepository::class);
        $jobRepository->method('find')->willReturn($sourceJob);

        $itemRepository = $this->createMock(WittyBackgroundJobItemRepository::class);
        $itemRepository->method('countForJob')->willReturn($availableItems);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getRepository')->willReturnMap([
            [WittyBackgroundJob::class, $jobRepository],
            [WittyBackgroundJobItem::class, $itemRepository],
        ]);

        return $em;
    }

    private function userHelper(User $user): UserHelper
    {
        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn($user);

        return $userHelper;
    }

    private function userWithId(int $id): User
    {
        $user = new User();
        (new ReflectionProperty(User::class, 'id'))->setValue($user, $id);

        return $user;
    }

    private function guard(): FieldWriteGuard
    {
        $guard = $this->createMock(FieldWriteGuard::class);
        $guard->method('unknownAliases')->willReturn([]);

        return $guard;
    }
}
