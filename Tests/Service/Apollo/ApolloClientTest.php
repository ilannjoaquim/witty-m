<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Apollo;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Apollo renvoie ses erreurs sous trois formes differentes selon le code
 * HTTP : texte brut (401), `{"error": ...}` (403/422), `{"message": ...}`
 * (429). C'est ce que throwFromErrorBody() doit absorber sans plancher sur
 * un format precis — le cas qui merite un test dedie, en plus de
 * l'authentification (en-tete x-api-key) et du decodage JSON du succes.
 */
class ApolloClientTest extends TestCase
{
    public function testGetSendsTheApiKeyHeaderAndDecodesTheResponse(): void
    {
        $response = $this->response(200, json_encode(['person' => ['id' => 'p1']]));

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('request')->with(
            'GET',
            'https://api.apollo.io/api/v1/people/match',
            $this->callback(static fn (array $options): bool => 'test-key' === ($options['headers']['x-api-key'] ?? null)),
        )->willReturn($response);

        $client = new ApolloClient($this->config('test-key'), $http);
        $result = $client->get('/people/match', ['name' => 'Tim Zheng']);

        $this->assertSame('p1', $result['person']['id']);
    }

    public function testPlainTextErrorBodyIsPropagated(): void
    {
        $response = $this->response(401, 'Invalid API key. See docs.');

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $client = new ApolloClient($this->config('bad-key'), $http);

        $this->expectException(ApolloException::class);
        $this->expectExceptionMessageMatches('/Invalid API key/');

        $client->get('/people/match', ['name' => 'x']);
    }

    public function testJsonErrorBodyExtractsTheErrorField(): void
    {
        $response = $this->response(403, json_encode(['error' => 'not authorized', 'error_code' => 'API_INACCESSIBLE']));

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('request')->willReturn($response);

        $client = new ApolloClient($this->config('test-key'), $http);

        $this->expectException(ApolloException::class);
        $this->expectExceptionMessageMatches('/not authorized/');

        $client->get('/organizations/enrich', ['domain' => 'apollo.io']);
    }

    public function testPostSendsTheJsonBody(): void
    {
        $response = $this->response(200, json_encode(['matches' => []]));

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('request')->with(
            'POST',
            'https://api.apollo.io/api/v1/people/bulk_match',
            $this->callback(static fn (array $options): bool => [['name' => 'Tim Zheng']] === ($options['json']['details'] ?? null)),
        )->willReturn($response);

        $client = new ApolloClient($this->config('test-key'), $http);
        $client->post('/people/bulk_match', ['details' => [['name' => 'Tim Zheng']]]);
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

            public function getApolloApiKey(): string
            {
                return $this->apiKey;
            }
        };
    }
}
