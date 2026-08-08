<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Mcp;

use MauticPlugin\WittyBundle\Service\Mcp\BrightDataMcpClient;
use MauticPlugin\WittyBundle\Service\Mcp\Exception\McpException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Le serveur MCP Bright Data parle JSON-RPC 2.0 sur un seul endpoint POST
 * ("Streamable HTTP") : chaque appel utile (tools/list, tools/call) doit
 * d'abord passer par une poignee de main initialize / notifications/initialized,
 * et reutiliser la session (en-tete Mcp-Session-Id) ouverte a l'initialisation.
 * Le serveur peut repondre en JSON simple ou en flux SSE : les deux formats
 * sont couverts ici.
 */
class BrightDataMcpClientTest extends TestCase
{
    private const API_KEY = 'bd-token-123';

    public function testListToolsPerformsHandshakeThenReturnsMappedDefinitions(): void
    {
        $methodsCalled = [];
        $capturedUrl   = null;

        $client = $this->client(function (string $httpMethod, string $url, array $options) use (&$methodsCalled, &$capturedUrl): ResponseInterface {
            $capturedUrl = $url;
            $body        = json_decode((string) $options['body'], true);
            $methodsCalled[] = $body['method'];

            return match ($body['method']) {
                'initialize' => new MockResponse(
                    json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['protocolVersion' => '2025-06-18']], JSON_THROW_ON_ERROR),
                    ['response_headers' => ['Mcp-Session-Id' => 'sess-abc']],
                ),
                'notifications/initialized' => new MockResponse('', ['http_code' => 202]),
                'tools/list' => new MockResponse(json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => $body['id'],
                    'result'  => ['tools' => [
                        [
                            'name'        => 'search_engine',
                            'description' => 'Recherche web',
                            'inputSchema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
                        ],
                        // Nom manquant : doit etre ignore plutot que de faire planter le mapping.
                        ['description' => 'Sans nom'],
                    ]],
                ], JSON_THROW_ON_ERROR)),
                default => throw new \RuntimeException('Methode JSON-RPC inattendue : '.$body['method']),
            };
        });

        $definitions = $client->listTools();

        $this->assertSame(['initialize', 'notifications/initialized', 'tools/list'], $methodsCalled, 'La poignee de main doit preceder tout appel utile.');
        $this->assertStringContainsString('token='.self::API_KEY, (string) $capturedUrl);
        $this->assertSame([[
            'name'        => 'search_engine',
            'description' => 'Recherche web',
            'schema'      => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ]], $definitions);
    }

    public function testUnconfiguredClientReturnsNoToolsWithoutAnyHttpCall(): void
    {
        $client = new BrightDataMcpClient(
            $this->configWith(false),
            new MockHttpClient(function (): ResponseInterface {
                throw new \RuntimeException('Aucun appel HTTP attendu.');
            }),
        );

        $this->assertFalse($client->isConfigured());
        $this->assertSame([], $client->listTools());
    }

    public function testSessionIdFromInitializeIsSentOnSubsequentCalls(): void
    {
        $headersByMethod = [];

        $client = $this->client(function (string $httpMethod, string $url, array $options) use (&$headersByMethod): ResponseInterface {
            $body = json_decode((string) $options['body'], true);
            $headersByMethod[$body['method']] = $options['headers'];

            return match ($body['method']) {
                'initialize' => new MockResponse(
                    json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => []], JSON_THROW_ON_ERROR),
                    ['response_headers' => ['Mcp-Session-Id' => 'sess-xyz']],
                ),
                'notifications/initialized' => new MockResponse('', ['http_code' => 202]),
                'tools/list' => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['tools' => []]], JSON_THROW_ON_ERROR)),
                default => throw new \RuntimeException('Methode JSON-RPC inattendue : '.$body['method']),
            };
        });

        $client->listTools();

        $this->assertNull($this->headerValue($headersByMethod['initialize'], 'Mcp-Session-Id'), 'Le tout premier appel n a pas encore de session a envoyer.');
        $this->assertSame('sess-xyz', $this->headerValue($headersByMethod['notifications/initialized'], 'Mcp-Session-Id'));
        $this->assertSame('sess-xyz', $this->headerValue($headersByMethod['tools/list'], 'Mcp-Session-Id'));
    }

    public function testCallToolConcatenatesTextContentIntoAnOkResult(): void
    {
        $client = $this->clientReadyForCalls([
            'content'  => [['type' => 'text', 'text' => 'Paris est la capitale de la France.']],
            'isError'  => false,
        ]);

        $output = $client->callTool('search_engine', ['query' => 'capitale de la france']);

        $this->assertSame(['status' => 'ok', 'result' => 'Paris est la capitale de la France.'], $output);
    }

    public function testCallToolReturningIsErrorTrueYieldsErrorStatus(): void
    {
        $client = $this->clientReadyForCalls([
            'content' => [['type' => 'text', 'text' => 'Timeout en scrapant la page cible.']],
            'isError' => true,
        ]);

        $output = $client->callTool('scrape_as_markdown', ['url' => 'https://example.test']);

        $this->assertSame('error', $output['status']);
        $this->assertSame('Timeout en scrapant la page cible.', $output['result']);
    }

    public function testJsonRpcErrorObjectThrowsMcpException(): void
    {
        $client = $this->client(function (string $httpMethod, string $url, array $options): ResponseInterface {
            $body = json_decode((string) $options['body'], true);

            return match ($body['method']) {
                'initialize'                => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => []], JSON_THROW_ON_ERROR)),
                'notifications/initialized' => new MockResponse('', ['http_code' => 202]),
                'tools/call'                => new MockResponse(json_encode([
                    'jsonrpc' => '2.0',
                    'id'      => $body['id'],
                    'error'   => ['code' => -32602, 'message' => 'Invalid params: query is required'],
                ], JSON_THROW_ON_ERROR)),
                default => throw new \RuntimeException('Methode JSON-RPC inattendue : '.$body['method']),
            };
        });

        $this->expectException(McpException::class);
        $this->expectExceptionMessage('Invalid params: query is required');

        $client->callTool('search_engine', []);
    }

    public function testServerSentEventsResponseIsParsedLikePlainJson(): void
    {
        $client = $this->client(function (string $httpMethod, string $url, array $options): ResponseInterface {
            $body = json_decode((string) $options['body'], true);

            if ('notifications/initialized' === $body['method']) {
                return new MockResponse('', ['http_code' => 202]);
            }

            $result = 'initialize' === $body['method'] ? [] : ['tools' => []];
            $sse    = "event: message\ndata: ".json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $result], JSON_THROW_ON_ERROR)."\n\n";

            return new MockResponse($sse, ['response_headers' => ['Content-Type' => 'text/event-stream']]);
        });

        $this->assertSame([], $client->listTools());
    }

    /**
     * @param array<string, mixed> $toolCallResult
     */
    private function clientReadyForCalls(array $toolCallResult): BrightDataMcpClient
    {
        return $this->client(function (string $httpMethod, string $url, array $options) use ($toolCallResult): ResponseInterface {
            $body = json_decode((string) $options['body'], true);

            return match ($body['method']) {
                'initialize'                => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => []], JSON_THROW_ON_ERROR)),
                'notifications/initialized' => new MockResponse('', ['http_code' => 202]),
                'tools/call'                => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => $toolCallResult], JSON_THROW_ON_ERROR)),
                default => throw new \RuntimeException('Methode JSON-RPC inattendue : '.$body['method']),
            };
        });
    }

    private function client(callable $responseFactory): BrightDataMcpClient
    {
        return new BrightDataMcpClient($this->configWith(true), new MockHttpClient($responseFactory));
    }

    private function configWith(bool $configured): WittyConfig
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('isBrightDataConfigured')->willReturn($configured);
        $config->method('getBrightDataApiKey')->willReturn($configured ? self::API_KEY : '');
        $config->method('isBrightDataProModeEnabled')->willReturn(false);

        return $config;
    }

    /**
     * MockHttpClient normalise les en-tetes en liste de chaines "Nom: valeur",
     * pas en tableau associatif.
     *
     * @param array<int, string> $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (str_starts_with($header, $name.':')) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
