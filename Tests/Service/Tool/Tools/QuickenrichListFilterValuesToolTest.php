<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\Tools\QuickenrichListFilterValuesTool;
use PHPUnit\Framework\TestCase;

/**
 * La doc QuickEnrich montre les endpoints de reference renvoyant un tableau
 * JSON brut (`["US", "GB", ...]`), pas une enveloppe {data: [...]} comme la
 * recherche elle-meme : l'outil doit absorber les deux formes sans lever
 * d'hypothese fausse sur l'une ou l'autre, c'est le cas qui merite un test
 * dedie.
 */
class QuickenrichListFilterValuesToolTest extends TestCase
{
    public function testUnknownDimensionIsRejectedWithoutCallingQuickenrich(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->never())->method('get');

        $output = (new QuickenrichListFilterValuesTool($client))->execute(['dimension' => 'not-a-real-one']);

        $this->assertSame('error', $output['status']);
    }

    public function testRawArrayResponseIsHandled(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('get')->with('/lookups/country-codes', [])->willReturn(['US', 'GB', 'FR']);

        $output = (new QuickenrichListFilterValuesTool($client))->execute(['dimension' => 'country_code']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(['US', 'GB', 'FR'], $output['values']);
    }

    public function testWrappedDataResponseIsHandled(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->method('get')->willReturn(['data' => ['51-200', '201-500']]);

        $output = (new QuickenrichListFilterValuesTool($client))->execute(['dimension' => 'number_of_employees']);

        $this->assertSame('ok', $output['status']);
        $this->assertSame(['51-200', '201-500'], $output['values']);
    }

    public function testQParameterOnlyAppliesToServices(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('get')->with('/lookups/company-services', ['q' => 'Dev'])->willReturn(['data' => []]);

        (new QuickenrichListFilterValuesTool($client))->execute(['dimension' => 'services', 'q' => 'Dev']);
    }

    public function testQParameterIsIgnoredForOtherDimensions(): void
    {
        $client = $this->createMock(QuickenrichClient::class);
        $client->expects($this->once())->method('get')->with('/lookups/country-codes', [])->willReturn(['US']);

        (new QuickenrichListFilterValuesTool($client))->execute(['dimension' => 'country_code', 'q' => 'ignored']);
    }
}
