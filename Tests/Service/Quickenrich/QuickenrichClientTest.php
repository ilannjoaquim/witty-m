<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Quickenrich;

use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * QuickEnrich authentifie par jeton Bearer (contrairement au x-api-key
 * d'Apollo et au X-KEY de Prospeo) : c'est ce qui merite un test dedie, en
 * plus du decodage JSON et de la propagation d'erreur (meme enveloppe
 * {success, message, code} qu'Apollo pour ses erreurs).
 */
class QuickenrichClientTest extends TestCase
{
    public function testPostSendsTheBearerHeaderAndDecodesTheResponse(): void
    {
        $response = $this->response(200, json_encode(['data' => [['first_name' => 'Jane']]]));

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('request')->with(
            'POST',
            'https://app.quickenrich.io/api/employees/contact-finder',
            $this->callback(static fn (array $options): bool => 'Bearer test-key' === ($options['headers']['Authorization'] ?? null)),
        )->willReturn($response);

        $client = new QuickenrichClient($this->config('test-key'), $http);
        $result = $client->post('/employees/contact-finder', ['has_email' => true]);

        $this->assertSame('Jane', $result['data'][0]['first_name']);
    }

    public function testErrorBodyExtractsTheMessageField(): void
    {
        $response = $this->response(422, json_encode(['success' => false, 'message' => 'Invalid industry_linkedin value', 'code' => 422]));

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $client = new QuickenrichClient($this->config('test-key'), $http);

        $this->expectException(QuickenrichException::class);
        $this->expectExceptionMessageMatches('/Invalid industry_linkedin value/');

        $client->post('/employees/contact-finder', ['industry_linkedin' => ['include' => ['nope'], 'exclude' => []]]);
    }

    public function testGetForwardsTheQueryParameters(): void
    {
        $response = $this->response(200, json_encode(['US', 'GB']));

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('request')->with(
            'GET',
            'https://app.quickenrich.io/api/lookups/company-services',
            $this->callback(static fn (array $options): bool => 'Dev' === ($options['query']['q'] ?? null)),
        )->willReturn($response);

        $client = new QuickenrichClient($this->config('test-key'), $http);
        $client->get('/lookups/company-services', ['q' => 'Dev']);
    }

    private function response(int $status, string $body): ResponseInterface
    {
        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getContent')->willReturn($body);

        return $response;
    }

    private function config(string $apiKey): WittyConfig
    {
        return new class($apiKey) extends WittyConfig {
            public function __construct(private string $apiKey)
            {
            }

            public function getQuickenrichApiKey(): string
            {
                return $this->apiKey;
            }
        };
    }
}
