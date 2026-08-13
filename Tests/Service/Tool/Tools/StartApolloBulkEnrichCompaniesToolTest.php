<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\ApolloBulkEnrichCompaniesJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartApolloBulkEnrichCompaniesTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Contrairement a start_apollo_bulk_enrich_people (un segment entier), cet
 * outil recoit une liste explicite de company_ids (pas de notion de segment
 * d entreprises cote Mautic) : ce test couvre surtout la validation
 * (Apollo non configure, liste vide, doublons deduits) plutot que la
 * derivation des identifiants, deja couverte par le test du handler.
 */
class StartApolloBulkEnrichCompaniesToolTest extends TestCase
{
    public function testNotConfiguredIsRejected(): void
    {
        $output = $this->tool(false)->execute(['company_ids' => [1, 2]]);

        $this->assertSame('error', $output['status']);
    }

    public function testEmptyCompanyIdsIsRejected(): void
    {
        $output = $this->tool(true)->execute(['company_ids' => []]);

        $this->assertSame('error', $output['status']);
    }

    public function testDuplicateIdsAreDeduplicated(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJob $job): bool => 3 === count($job->getParams()['company_ids']),
        ));

        $output = $this->tool(true, $em)->execute(['company_ids' => [1, 2, 2, 3]]);

        $this->assertSame('ok', $output['status']);
    }

    public function testValidRequestCreatesAJobOfTheRightType(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJob $job): bool => ApolloBulkEnrichCompaniesJobHandler::TYPE === $job->getType(),
        ));

        $output = $this->tool(true, $em)->execute(['company_ids' => [1, 2]]);

        $this->assertSame('ok', $output['status']);
    }

    private function tool(bool $configured, ?EntityManagerInterface $em = null): StartApolloBulkEnrichCompaniesTool
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('isApolloConfigured')->willReturn($configured);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(new User());

        return new StartApolloBulkEnrichCompaniesTool($em ?? $this->createMock(EntityManagerInterface::class), $userHelper, $config);
    }
}
