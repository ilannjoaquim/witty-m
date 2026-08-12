<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Mcp;

use MauticPlugin\WittyBundle\Service\Mcp\Exception\McpException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client du serveur MCP officiel de data.gouv.fr (plateforme ouverte des
 * donnees publiques francaises) — https://mcp.data.gouv.fr/mcp
 *
 * Expose dynamiquement des outils tels que search_datasets, get_dataset_info,
 * list_dataset_resources, get_resource_info, query_resource_data,
 * download_and_parse_resource, search_dataservices, get_dataservice_info,
 * get_dataservice_openapi_spec, get_metrics : aucun d'eux n'est code en dur
 * ici, cf. McpClientInterface. Aucun outil d'ecriture cote source : data.gouv.fr
 * ne documente que des outils de lecture (recherche/consultation de jeux de
 * donnees publics), rien qui touche Mautic.
 *
 * Meme transport "Streamable HTTP" JSON-RPC 2.0 que Bright Data/Prospeo (cf.
 * BrightDataMcpClient/ProspeoMcpClient, meme structure ici a dessein) : un
 * seul endpoint POST, reponse JSON simple ou flux SSE, session ouverte au
 * premier appel et reutilisee pour tous les tool calls d'un meme tour de
 * l'agent.
 *
 * Contrairement a Bright Data/Prospeo/Apollo/QuickEnrich, AUCUNE cle API
 * n'est requise (serveur public, en-tete "no API key required (read-only
 * tools)" dans la doc officielle) : ce n'est donc pas une cle mais un simple
 * interrupteur (feature_settings, WittyConfig::isDatagouvEnabled()) qui gate
 * l'activation, pour laisser le choix de l'exposer ou non a l'agent malgre
 * l'absence de secret a saisir.
 */
class DatagouvMcpClient implements McpClientInterface
{
    private const ENDPOINT = 'https://mcp.data.gouv.fr/mcp';

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
        return 'datagouv';
    }

    public function isConfigured(): bool
    {
        return $this->config->isDatagouvEnabled();
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
                'data.gouv.fr MCP (%s) : %s',
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
        $headers = [
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json, text/event-stream',
        ];

        if (null !== $this->sessionId) {
            $headers['Mcp-Session-Id'] = $this->sessionId;
        }

        try {
            $response         = $this->httpClient->request('POST', self::ENDPOINT, [
                'headers' => $headers,
                'json'    => $payload,
                'timeout' => 120,
            ]);
            $status           = $response->getStatusCode();
            $responseHeaders  = $response->getHeaders(false);
            $body             = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new McpException(sprintf('data.gouv.fr MCP : appel impossible (%s).', $e->getMessage()), 0, $e);
        }

        $sessionId = $responseHeaders['mcp-session-id'][0] ?? null;

        if (null !== $sessionId) {
            $this->sessionId = $sessionId;
        }

        if ($status >= 400) {
            throw new McpException(sprintf('data.gouv.fr MCP : HTTP %d — %s', $status, $body));
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
            throw new McpException(sprintf('data.gouv.fr MCP : reponse non-JSON (HTTP %d).', $status));
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
