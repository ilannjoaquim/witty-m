<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\QuickenrichFindEmployeeEmailTool;
use PHPUnit\Framework\TestCase;

/**
 * Comportement propre a cet outil (pas a QuickenrichClient) : le refus d'un
 * appel sans linkedin_url ni le trio company_url+first_name+last_name, et le
 * fait qu'une reponse a data vide (aucun employe trouve) devient found=false
 * plutot que de faire remonter un tableau vide tel quel.
 */
class QuickenrichFindEmployeeEmailToolTest extends TestCase
{
    public function testMissingIdentifiersAreRejectedWithoutCallingQuickenrich(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->never())->method('get');

        $output = (new QuickenrichFindEmployeeEmailTool($client))->execute(['first_name' => 'Jane']);

        $this->assertSame('error', $output['status']);
    }

    public function testLinkedinUrlAloneIsSufficient(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('get')->with(
            '/employees/search',
            ['linkedin_url' => 'https://linkedin.com/in/johndoe'],
        )->willReturn(['data' => ['first_name' => 'John', 'email' => 'john@company.com']]);

        $output = (new QuickenrichFindEmployeeEmailTool($client))->execute(['linkedin_url' => 'https://linkedin.com/in/johndoe']);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['found']);
        $this->assertSame('john@company.com', $output['employee']['email']);
    }

    public function testCompanyUrlFirstNameLastNameTrioIsSufficient(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('get')->with(
            '/employees/search',
            ['company_url' => 'https://techcorp.com', 'first_name' => 'John', 'last_name' => 'Doe'],
        )->willReturn(['data' => ['first_name' => 'John']]);

        $output = (new QuickenrichFindEmployeeEmailTool($client))->execute([
            'company_url' => 'https://techcorp.com',
            'first_name'  => 'John',
            'last_name'   => 'Doe',
        ]);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['found']);
    }

    public function testEmptyDataMeansNotFound(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('get')->willReturn(['data' => []]);

        $output = (new QuickenrichFindEmployeeEmailTool($client))->execute(['linkedin_url' => 'https://linkedin.com/in/nobody']);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['found']);
        $this->assertArrayNotHasKey('employee', $output);
    }
}
