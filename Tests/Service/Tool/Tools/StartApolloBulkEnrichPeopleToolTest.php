<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartApolloBulkEnrichPeopleTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * execute() construit son comptage de membres actifs via une QueryBuilder
 * directe sur l'entite Mautic ListLead (IDENTITY(), ORDER BY alias) : la
 * doubler fidelement avec createMock(EntityManagerInterface::class) exigerait
 * de simuler toute la chaine QueryBuilder->Query->hydrate, peu fiable et peu
 * lisible. Cette requete (identique dans sa forme a celle
 * d'ApolloBulkEnrichPeopleJobHandler::nextLeadIds()) a ete verifiee dans cette
 * session par un script autonome contre une vraie base MySQL locale
 * (segment reel, contacts reels, exclusion confirmee des membres
 * manually_removed=1) plutot que via ce fichier. Seule la validation
 * anterieure a cette requete (segment introuvable) est testee ici.
 */
class StartApolloBulkEnrichPeopleToolTest extends TestCase
{
    public function testNotConfiguredIsRejectedWithoutQueryingSegments(): void
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->expects($this->never())->method('getEntity');

        $config = $this->createMock(WittyConfig::class);
        $config->method('isApolloConfigured')->willReturn(false);

        $output = (new StartApolloBulkEnrichPeopleTool($listModel, $this->createMock(EntityManagerInterface::class), $this->createMock(UserHelper::class), $config))
            ->execute(['segment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnknownSegmentIsRejected(): void
    {
        $listModel = $this->createMock(ListModel::class);
        $listModel->method('getEntity')->willReturn(null);

        $em         = $this->createMock(EntityManagerInterface::class);
        $userHelper = $this->createMock(UserHelper::class);

        $config = $this->createMock(WittyConfig::class);
        $config->method('isApolloConfigured')->willReturn(true);

        $output = (new StartApolloBulkEnrichPeopleTool($listModel, $em, $userHelper, $config))->execute(['segment_id' => 999]);

        $this->assertSame('error', $output['status']);
    }
}
