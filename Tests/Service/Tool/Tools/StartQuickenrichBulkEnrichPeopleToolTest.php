<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartQuickenrichBulkEnrichPeopleTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Meme choix que StartApolloBulkEnrichPeopleToolTest : le comptage de
 * membres actifs du segment (QueryBuilder direct sur ListLead, forme
 * identique a ApolloBulkEnrichPeopleJobHandler::nextLeadIds()) n'est pas
 * double ici (chaine QueryBuilder->Query peu fiable a mocker fidelement) —
 * verifie par un script autonome contre une vraie base MySQL locale dans
 * cette session (segment reel avec un membre ayant un LinkedIn et un membre
 * sans, les deux correctement geres). Seule la validation anterieure a cette
 * requete est testee ici.
 */
class StartQuickenrichBulkEnrichPeopleToolTest extends TestCase
{
    public function testNotConfiguredIsRejectedWithoutQueryingSegments(): void
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->expects($this->never())->method('getEntity');

        $config = $this->createMock(WittyConfig::class);
        $config->method('isQuickenrichConfigured')->willReturn(false);

        $output = (new StartQuickenrichBulkEnrichPeopleTool($listModel, $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $config))
            ->execute(['segment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnknownSegmentIsRejected(): void
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->willReturn(null);

        $config = $this->createMock(WittyConfig::class);
        $config->method('isQuickenrichConfigured')->willReturn(true);

        $output = (new StartQuickenrichBulkEnrichPeopleTool($listModel, $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $config))
            ->execute(['segment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }
}
