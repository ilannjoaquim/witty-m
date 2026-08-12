<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\QuickenrichBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartQuickenrichBulkSearchTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Reprend les regles de validation de QuickenrichSearchContactsTool (au
 * moins un filtre actif) mais y ajoute ce qui est propre a la version "job" :
 * target_count obligatoire et borne, et le fait que le job cree porte bien le
 * type attendu par QuickenrichBulkSearchJobHandler pour etre repris au
 * prochain passage de cron.
 */
class StartQuickenrichBulkSearchToolTest extends TestCase
{
    public function testNotConfiguredIsRejected(): void
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('isQuickenrichConfigured')->willReturn(false);

        $output = $this->tool($config)->execute(['has_email' => true, 'target_count' => 100]);

        $this->assertSame('error', $output['status']);
    }

    public function testNoActiveFilterIsRejected(): void
    {
        $output = $this->tool()->execute(['target_count' => 100]);

        $this->assertSame('error', $output['status']);
    }

    public function testMissingTargetCountIsRejected(): void
    {
        $output = $this->tool()->execute(['has_email' => true]);

        $this->assertSame('error', $output['status']);
    }

    public function testValidRequestCreatesAJobOfTheRightType(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJob $job): bool => QuickenrichBulkSearchJobHandler::TYPE === $job->getType()
                && true === $job->getParams()['body']['has_email']
                && 500 === $job->getParams()['target_count'],
        ));
        $em->expects($this->once())->method('flush');

        $output = $this->tool(null, $em)->execute(['has_email' => true, 'target_count' => 500]);

        $this->assertSame('ok', $output['status']);
    }

    public function testTargetCountIsCappedAtTheMaximum(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJob $job): bool => 50000 === $job->getParams()['target_count'],
        ));

        $this->tool(null, $em)->execute(['has_email' => true, 'target_count' => 999999]);
    }

    private function tool(?WittyConfig $config = null, ?EntityManagerInterface $em = null): StartQuickenrichBulkSearchTool
    {
        $config ??= $this->configuredMock();
        $em ??= $this->createMock(EntityManagerInterface::class);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(new \Mautic\UserBundle\Entity\User());

        return new StartQuickenrichBulkSearchTool($config, $em, $userHelper);
    }

    private function configuredMock(): WittyConfig
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('isQuickenrichConfigured')->willReturn(true);

        return $config;
    }
}
