<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\SearchContactsTool;
use PHPUnit\Framework\TestCase;

/**
 * LeadRepository::getEntities() est un override complet (custom fields via
 * CustomFieldRepositoryTrait), pas un Doctrine\ORM\Tools\Pagination\Paginator :
 * sans withTotalCount=true, il renvoie juste le tableau de la page courante, et
 * son count() ne dit rien du total (bug reel corrige en session : le total
 * variait selon start avant ce fix, verifie contre une vraie base locale).
 */
class SearchContactsToolTest extends TestCase
{
    public function testAsksLeadModelForTheRealTotalAndForwardsStartAndLimit(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->once())
            ->method('getEntities')
            ->with($this->callback(function (array $args): bool {
                return 40 === $args['start']
                    && 25 === $args['limit']
                    && 'email:*@acme.com' === $args['filter']['string']
                    && true === $args['withTotalCount'];
            }))
            ->willReturn(['count' => 137, 'results' => []]);

        $tool   = new SearchContactsTool($leadModel);
        $output = $tool->execute(['query' => 'email:*@acme.com', 'start' => 40, 'limit' => 25]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(137, $output['total']);
        $this->assertSame(40, $output['start']);
        $this->assertSame(0, $output['count']);
    }

    public function testLimitIsClampedToOneHundredAndStartCannotGoNegative(): void
    {
        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->expects($this->once())
            ->method('getEntities')
            ->with($this->callback(fn (array $args): bool => 100 === $args['limit'] && 0 === $args['start']))
            ->willReturn(['count' => 0, 'results' => []]);

        $tool = new SearchContactsTool($leadModel);
        $tool->execute(['query' => 'x', 'limit' => 9000, 'start' => -5]);
    }

    public function testResultsComeFromTheResultsKeyNotTheTopLevelArray(): void
    {
        $lead = new Lead();
        $lead->setEmail('jane@acme.com');
        $lead->setFirstname('Jane');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntities')->willReturn(['count' => 1, 'results' => [$lead]]);

        $tool   = new SearchContactsTool($leadModel);
        $output = $tool->execute(['query' => 'jane']);

        $this->assertSame(1, $output['count']);
        $this->assertSame('jane@acme.com', $output['contacts'][0]['email']);
    }

    public function testExtraFieldsAreIncludedOnlyWhenNonEmpty(): void
    {
        $lead = new Lead();
        $lead->addUpdatedField('phone', '0600000000');

        $leadModel = $this->createMock(LeadModel::class);
        $leadModel->method('getEntities')->willReturn(['count' => 1, 'results' => [$lead]]);

        $tool   = new SearchContactsTool($leadModel);
        $output = $tool->execute(['query' => 'x', 'fields' => ['phone', 'unknown_alias']]);

        $this->assertSame(['phone' => '0600000000'], $output['contacts'][0]['fields']);
    }
}
