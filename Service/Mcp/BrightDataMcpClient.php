<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Mcp;

use MauticPlugin\WittyBundle\Service\Mcp\Exception\McpException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client du serveur MCP distant de Bright Data (recherche web, scraping,
 * navigation) — https://docs.brightdata.com/ai/mcp-server/remote/quickstart
 *
 * Transport "Streamable HTTP" (spec MCP) : un seul endpoint POST, en
 * JSON-RPC 2.0. Le serveur peut repondre soit en JSON simple, soit en flux
 * SSE (un ou plusieurs evenements "data: {...}") ; les deux sont geres ici.
 * L'authentification se fait par jeton en query string (pas d'en-tete
 * Authorization) : c'est le choix de Bright Data pour ce serveur precis, pas
 * une convention MCP generale.
 *
 * Une session MCP (en-tete Mcp-Session-Id, renvoyee par le serveur a
 * l'initialisation) est ouverte au premier appel et reutilisee pour toute la
 * duree de vie de l'objet — donc pour tous les tool calls d'un meme tour de
 * l'agent, puisque AgentRunner::run() reutilise le meme ToolRegistry (donc le
 * meme client) sur toutes ses iterations.
 */
class BrightDataMcpClient implements McpClientInterface
{
    private const ENDPOINT = 'https://mcp.brightdata.com/mcp';

    private const PROTOCOL_VERSION = '2025-06-18';

    private bool $initialized = false;

    private ?string $sessionId = null;

    private int $nextId = 1;

    public function __construct(
        private WittyConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    public function getNamespace(): string
    {
        return 'brightdata';
    }

    public function isConfigured(): bool
    {
        return $this->config->isBrightDataConfigured();
    }

    /**
     * @return array<int, array{name: string, description: string, schema: array<mixed>}>
     */
    public function listTools(): array
    {
        if (!$this->isConfigured()) {
            return [];
        }

        $this->ensureInitialized();

        $result = $this->call('tools/list', new \stdClass());
        $tools  = is_array($result['tools'] ?? null) ? $result['tools'] : [];

        $definitions = [];

        foreach ($tools as $tool) {
            if (!is_array($tool) || '' === (string) ($tool['name'] ?? '')) {
                continue;
            }

            $schema = is_array($tool['inputSchema'] ?? null)
                ? $tool['inputSchema']
                : ['type' => 'object', 'properties' => new \stdClass()];

            $definitions[] = [
                'name'        => (string) $tool['name'],
                'description' => (string) ($tool['description'] ?? ''),
                'schema'      => $schema,
            ];
        }

        return $definitions;
    }

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    public function callTool(string $name, array $arguments): array
    {
        $this->ensureInitialized();

        $result = $this->call('tools/call', ['name' => $name, 'arguments' => $arguments]);

        $texts = [];

        foreach ((array) ($result['content'] ?? []) as $block) {
            if (is_array($block) && 'text' === ($block['type'] ?? null) && isset($block['text'])) {
                $texts[] = (string) $block['text'];
            }
        }

        $isError = true === ($result['isError'] ?? false);

        if ([] !== $texts) {
            return ['status' => $isError ? 'error' : 'ok', 'result' => implode("\n", $texts)];
        }

        // Contenu non textuel (image, ressource embarquee...) : on renvoie la
        // charge utile brute, le modele saura l'interpreter au besoin.
        return ['status' => $isError ? 'error' : 'ok', 'result' => $result['content'] ?? $result];
    }

    private function ensureInitialized(): void
    {
        if ($this->initialized) {
            return;
        }

        $this->call('initialize', [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => new \stdClass(),
            'clientInfo'      => ['name' => 'witty-mautic', 'version' => '1.0'],
        ]);

        // Notification (pas de "id") : rien a attendre en retour, juste
        // l'accuse HTTP.
        $this->send([
            'jsonrpc' => '2.0',
            'method'  => 'notifications/initialized',
        ], false);

        $this->initialized = true;
    }

    /**
     * @return array<string, mixed>
     */
    private function call(string $method, array|\stdClass $params): array
    {
        $response = $this->send([
            'jsonrpc' => '2.0',
            'id'      => $this->nextId++,
            'method'  => $method,
            'params'  => $params,
        ], true);

        if (isset($response['error'])) {
            $error = $response['error'];
            throw new McpException(sprintf(
                'Bright Data MCP (%s) : %s',
                $method,
                is_array($error) ? (string) ($error['message'] ?? json_encode($error)) : (string) $error,
            ));
        }

        $result = $response['result'] ?? null;

        return is_array($result) ? $result : [];
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>|null null pour une notification (aucune reponse attendue)
     */
    private function send(array $payload, bool $expectResponse): ?array
    {
        $url = self::ENDPOINT.'?token='.rawurlencode($this->config->getBrightDataApiKey());

        if ($this->config->isBrightDataProModeEnabled()) {
            $url .= '&pro=1';
        }

        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        ];

        if (null !== $this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $response         = $this->httpClient->request('POST', $url, [
                'headers' => $headers,
                'json'    => $payload,
                'timeout' => 120,
            ]);
            $status           = $response->getStatusCode();
            $responseHeaders  = $response->getHeaders(false);
            $body             = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpException(sprintf('Bright Data MCP : appel impossible (%s).', $e->getMessage()), 0, $e);
        }

        $sessionId = $responseHeaders['mcp-session-id'][0] ?? null;

        if (null !== $sessionId) {
            $this->sessionId = $sessionId;
        }

        if ($status >= 400) {
            throw new McpException(sprintf('Bright Data MCP : HTTP %d — %s', $status, $body));
        }

        if (!$expectResponse) {
            return null;
        }

        $contentType = $responseHeaders['content-type'][0] ?? '';
        $expectedId  = $payload['id'] ?? null;

        $message = str_contains($contentType, 'text/event-stream')
            ? $this->parseSseMessage($body, $expectedId)
            : json_decode($body, true);

        if (!is_array($message)) {
            throw new McpException(sprintf('Bright Data MCP : reponse non-JSON (HTTP %d).', $status));
        }

        return $message;
    }

    /**
     * Extrait, parmi les evenements "data: {...}" d'un flux SSE, le message
     * JSON-RPC dont l'id correspond a la requete — ou a defaut le dernier
     * message JSON-RPC valide (cas d'un evenement serveur sans id).
     *
     * @return array<string, mixed>|null
     */
    private function parseSseMessage(string $body, mixed $expectedId): ?array
    {
        $last = null;

        foreach (explode("\n", $body) as $line) {
            $line = rtrim($line, "\r");

            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $data = trim(substr($line, 5));

            if ('' === $data) {
                continue;
            }

            $decoded = json_decode($data, true);

            if (!is_array($decoded)) {
                continue;
            }

            if (null !== $expectedId && ($decoded['id'] ?? null) === $expectedId) {
                return $decoded;
            }

            $last = $decoded;
        }

        return $last;
    }
}
