<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\EnrichCompanyTool;
use PHPUnit\Framework\TestCase;

class EnrichCompanyToolTest extends TestCase
{
    public function testNoIdentifierIsRejectedWithoutCallingApollo(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->expects($this->never())->method('get');

        $output = (new EnrichCompanyTool($apollo))->execute([]);

        $this->assertSame('error', $output['status']);
    }

    public function testNoMatchIsReportedAsFoundFalse(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn([]);

        $output = (new EnrichCompanyTool($apollo))->execute(['domain' => 'unknown.test']);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['found']);
    }

    public function testResponseIsTrimmedToUsefulFields(): void
    {
        $apollo = $this->createMock(ApolloClient::class);
        $apollo->method('get')->willReturn([
            'organization' => [
                'id' => 'o1', 'name' => 'Apollo.io', 'primary_domain' => 'apollo.io',
                'estimated_num_employees' => 1600,
                'current_technologies' => [['uid' => 'ai', 'name' => 'AI']],
                'funding_events' => [['id' => 'noise']],
            ],
        ]);

        $output = (new EnrichCompanyTool($apollo))->execute(['domain' => 'apollo.io']);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['found']);
        $this->assertSame('Apollo.io', $output['organization']['name']);
        $this->assertArrayNotHasKey('current_technologies', $output['organization']);
        $this->assertArrayNotHasKey('funding_events', $output['organization']);
    }
}
