<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\BulkEnrichCompaniesTool;
use PHPUnit\Framework\TestCase;

class BulkEnrichCompaniesToolTest extends TestCase
{
    public function testEmptyListIsRejected(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $output = (new BulkEnrichCompaniesTool($apollo))->execute(['companies' => []]);

        $this->assertSame('error', $output['status']);
    }

    public function testMoreThanTenCompaniesIsRejected(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('post');

        $output = (new BulkEnrichCompaniesTool($apollo))->execute(['companies' => array_fill(0, 11, ['domain' => 'x.test'])]);

        $this->assertSame('error', $output['status']);
    }

    public function testOrganizationsAreTrimmedAndReturned(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->once())->method('post')->with(
            '/organizations/bulk_enrich',
            $this->callback(static fn (array $body): bool => 2 === count($body['details'])),
        )->willReturn([
            'missing_records' => 0,
            'organizations' => [
                ['id' => 'o1', 'name' => 'Apollo.io', 'funding_events' => [['noise' => true]]],
                ['id' => 'o2', 'name' => 'Microsoft'],
            ],
        ]);

        $output = (new BulkEnrichCompaniesTool($apollo))->execute(['companies' => [['domain' => 'apollo.io'], ['domain' => 'microsoft.com']]]);

        $this->assertSame('ok', $output['status']);
        $this->assertCount(2, $output['organizations']);
        $this->assertArrayNotHasKey('funding_events', $output['organizations'][0]);
    }
}
