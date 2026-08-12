<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\QuickenrichFindEmployeePhoneTool;
use PHPUnit\Framework\TestCase;

/**
 * Meme structure que QuickenrichFindEmployeeEmailToolTest : le point propre a
 * phone-search est que "non trouve" est un succes cote API (data: [],
 * meta.reason=PHONE_NOT_FOUND, credits_used: 0) et non une erreur HTTP — ce
 * qui doit se traduire par found=false, pas par status=error.
 */
class QuickenrichFindEmployeePhoneToolTest extends TestCase
{
    public function testMissingIdentifiersAreRejectedWithoutCallingQuickenrich(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->never())->method('get');

        $output = (new QuickenrichFindEmployeePhoneTool($client))->execute(['company_url' => 'https://techcorp.com']);

        $this->assertSame('error', $output['status']);
    }

    public function testPhoneFoundIsReturned(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('get')->with(
            '/employees/phone-search',
            ['linkedin_url' => 'https://linkedin.com/in/johndoe'],
        )->willReturn(['data' => ['first_name' => 'John', 'employee_phone' => '+1-555-0123']]);

        $output = (new QuickenrichFindEmployeePhoneTool($client))->execute(['linkedin_url' => 'https://linkedin.com/in/johndoe']);

        $this->assertSame('ok', $output['status']);
        $this->assertTrue($output['found']);
        $this->assertSame('+1-555-0123', $output['employee']['employee_phone']);
    }

    public function testPhoneNotFoundIsNotAnError(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('get')->willReturn(['data' => [], 'meta' => ['credits_used' => 0, 'reason' => 'PHONE_NOT_FOUND']]);

        $output = (new QuickenrichFindEmployeePhoneTool($client))->execute(['linkedin_url' => 'https://linkedin.com/in/nobody']);

        $this->assertSame('ok', $output['status']);
        $this->assertFalse($output['found']);
    }
}
