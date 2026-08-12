<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Mcp;

use MauticPlugin\WittyBundle\Service\Mcp\DatagouvMcpClient;
use MauticPlugin\WittyBundle\Service\Mcp\Exception\McpException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * Meme transport JSON-RPC 2.0 "Streamable HTTP" que BrightDataMcpClientTest
 * (handshake initialize/notifications puis reutilisation de session), mais le
 * point propre a ce client est l'ABSENCE de toute authentification : ni
 * en-tete (X-KEY comme Prospeo, Authorization comme QuickEnrich), ni jeton
 * dans l'URL (comme Bright Data) — le serveur data.gouv.fr est public. Ce
 * qui gate l'activation ici est un interrupteur (isDatagouvEnabled()), pas
 * une cle presente/absente : merite un test dedie distinct des autres
 * clients MCP.
 */
class DatagouvMcpClientTest extends TestCase
{
    public function testListToolsPerformsHandshakeThenReturnsMappedDefinitions(): void
    {
        $methodsCalled = [];

        $client = $this->client(function (string $httpMethod, string $url, array $options) use (&$methodsCalled): ResponseInterface {
            $this->assertSame('https://mcp.data.gouv.fr/mcp', $url);

            $body             = json_decode((string) $options['body'], true);
            $methodsCalled[]  = $body['method'];

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
                            'name'        => 'search_datasets',
                            'description' => 'Cherche des jeux de donnees par mots-cles',
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
        $this->assertSame([[
            'name'        => 'search_datasets',
            'description' => 'Cherche des jeux de donnees par mots-cles',
            'schema'      => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
        ]], $definitions);
    }

    public function testUnconfiguredClientReturnsNoToolsWithoutAnyHttpCall(): void
    {
        $client = new DatagouvMcpClient(
            $this->configWith(false),
            new MockHttpClient(function (): ResponseInterface {
                throw new \RuntimeException('Aucun appel HTTP attendu.');
            }),
        );

        $this->assertFalse($client->isConfigured());
        $this->assertSame([], $client->listTools());
    }

    public function testNoAuthenticationHeaderIsSentAnywhere(): void
    {
        $client = $this->client(function (string $httpMethod, string $url, array $options): ResponseInterface {
            $body = json_decode((string) $options['body'], true);

            foreach ($options['headers'] as $header) {
                $this->assertStringNotContainsStringIgnoringCase('authorization', $header, 'Serveur public : aucun en-tete d authentification attendu.');
                $this->assertStringNotContainsStringIgnoringCase('x-key', $header, 'Serveur public : aucun en-tete d authentification attendu.');
            }

            return match ($body['method']) {
                'initialize'                => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => []], JSON_THROW_ON_ERROR)),
                'notifications/initialized' => new MockResponse('', ['http_code' => 202]),
                'tools/list'                => new MockResponse(json_encode(['jsonrpc' => '2.0', 'id' => $body['id'], 'result' => ['tools' => []]], JSON_THROW_ON_ERROR)),
                default => throw new \RuntimeException('Methode JSON-RPC inattendue : '.$body['method']),
            };
        });

        $client->listTools();
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
            'content' => [['type' => 'text', 'text' => '{"title":"Population par commune"}']],
            'isError' => false,
        ]);

        $output = $client->callTool('get_dataset_info', ['dataset_id' => 'population-communes']);

        $this->assertSame(['status' => 'ok', 'result' => '{"title":"Population par commune"}'], $output);
    }

    public function testCallToolReturningIsErrorTrueYieldsErrorStatus(): void
    {
        $client = $this->clientReadyForCalls([
            'content' => [['type' => 'text', 'text' => 'Dataset introuvable.']],
            'isError' => true,
        ]);

        $output = $client->callTool('get_dataset_info', ['dataset_id' => 'inconnu']);

        $this->assertSame('error', $output['status']);
        $this->assertSame('Dataset introuvable.', $output['result']);
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

        $client->callTool('search_datasets', []);
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
    private function clientReadyForCalls(array $toolCallResult): DatagouvMcpClient
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

    private function client(callable $responseFactory): DatagouvMcpClient
    {
        return new DatagouvMcpClient($this->configWith(true), new MockHttpClient($responseFactory));
    }

    private function configWith(bool $enabled): WittyConfig
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('isDatagouvEnabled')->willReturn($enabled);

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
