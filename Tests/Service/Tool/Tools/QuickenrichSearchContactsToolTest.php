<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\QuickenrichSearchContactsTool;
use PHPUnit\Framework\TestCase;

/**
 * Deux comportements propres a cet outil, pas a QuickenrichClient : le refus
 * d'un appel sans le moindre filtre actif (documente comme obligatoire cote
 * QuickEnrich), et le fait qu'une dimension include/exclude vide n'est
 * jamais envoyee (pour ne pas la compter par erreur comme un filtre actif
 * cote API).
 */
class QuickenrichSearchContactsToolTest extends TestCase
{
    public function testNoFilterAtAllIsRejectedWithoutCallingQuickenrich(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->never())->method('post');

        $output = (new QuickenrichSearchContactsTool($client))->execute([]);

        $this->assertSame('error', $output['status']);
    }

    public function testEmptyDimensionsDoNotCountAsAnActiveFilter(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->never())->method('post');

        $output = (new QuickenrichSearchContactsTool($client))->execute([
            'title' => ['include' => [], 'exclude' => []],
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testHasEmailAloneIsAValidFilter(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('post')->with(
            '/employees/contact-finder',
            $this->callback(static fn (array $body): bool => true === ($body['has_email'] ?? null)),
        )->willReturn(['data' => [], 'meta' => ['total' => 0]]);

        $output = (new QuickenrichSearchContactsTool($client))->execute(['has_email' => true]);

        $this->assertSame('ok', $output['status']);
    }

    public function testActiveDimensionIsSentAsIncludeExclude(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('post')->with(
            '/employees/contact-finder',
            $this->callback(static fn (array $body): bool =>
                ['include' => ['CEO'], 'exclude' => ['Intern']] === ($body['title'] ?? null)
                && !array_key_exists('locality', $body)),
        )->willReturn(['data' => [], 'meta' => []]);

        (new QuickenrichSearchContactsTool($client))->execute([
            'title'    => ['include' => ['CEO'], 'exclude' => ['Intern']],
            'locality' => ['include' => [], 'exclude' => []],
        ]);
    }

    public function testPerPageIsCappedAtOneHundred(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('post')->with(
            $this->anything(),
            $this->callback(static fn (array $body): bool => 100 === $body['per_page']),
        )->willReturn(['data' => [], 'meta' => []]);

        (new QuickenrichSearchContactsTool($client))->execute(['has_email' => true, 'per_page' => 500]);
    }

    public function testResultsAndPaginationAreReturned(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('post')->willReturn([
            'data' => [['first_name' => 'Jane', 'has_email' => true, 'has_phone' => false]],
            'meta' => ['page' => 1, 'total' => 1],
        ]);

        $output = (new QuickenrichSearchContactsTool($client))->execute(['has_email' => true]);

        $this->assertSame('ok', $output['status']);
        $this->assertCount(1, $output['contacts']);
        $this->assertSame('Jane', $output['contacts'][0]['first_name']);
        $this->assertSame(1, $output['pagination']['total']);
    }
}
