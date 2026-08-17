<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Company;
use Mautic\LeadBundle\Model\CompanyModel;
use MauticPlugin\WittyBundle\Service\Tool\Tools\SearchCompaniesTool;
use PHPUnit\Framework\TestCase;

/**
 * Meme piege que SearchContactsTool (voir sa docblock de test) :
 * CompanyRepository::getEntities() delegue aussi a
 * CustomFieldRepositoryTrait::getEntitiesWithCustomFields(), qui ne renvoie un
 * vrai total independant de la page courante que si withTotalCount=true est
 * demande explicitement.
 */
class SearchCompaniesToolTest extends TestCase
{
    public function testAsksCompanyModelForTheRealTotalAndForwardsStartAndLimit(): void
    {
        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->expects($this->once())
            ->method('getEntities')
            ->with($this->callback(function (array $args): bool {
                return 10 === $args['start']
                    && 5 === $args['limit']
                    && 'acme' === $args['filter']['string']
                    && true === $args['withTotalCount'];
            }))
            ->willReturn(['count' => 42, 'results' => []]);

        $tool   = new SearchCompaniesTool($companyModel);
        $output = $tool->execute(['query' => 'acme', 'start' => 10, 'limit' => 5]);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(42, $output['total']);
        $this->assertSame(10, $output['start']);
    }

    public function testResultsComeFromTheResultsKeyNotTheTopLevelArray(): void
    {
        $company = new Company();
        $company->setName('Acme Corp');

        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->method('getEntities')->willReturn(['count' => 1, 'results' => [$company]]);

        $tool   = new SearchCompaniesTool($companyModel);
        $output = $tool->execute(['query' => 'acme']);

        $this->assertSame(1, $output['count']);
        $this->assertSame('Acme Corp', $output['companies'][0]['name']);
    }

    public function testLimitIsClampedToOneHundredAndStartCannotGoNegative(): void
    {
        $companyModel = $this->createMock(CompanyModel::class);
        $companyModel->expects($this->once())
            ->method('getEntities')
            ->with($this->callback(fn (array $args): bool => 100 === $args['limit'] && 0 === $args['start']))
            ->willReturn(['count' => 0, 'results' => []]);

        $tool = new SearchCompaniesTool($companyModel);
        $tool->execute(['query' => 'x', 'limit' => 9000, 'start' => -5]);
    }
}
