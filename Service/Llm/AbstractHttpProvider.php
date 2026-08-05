<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

abstract class AbstractHttpProvider implements LlmProviderInterface
{
    public function __construct(protected HttpClientInterface $httpClient)
    {
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     *
     * @return array<string, mixed>
     */
    protected function post(string $url, array $payload, array $headers): array
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers + ['Content-Type' => 'application/json'],
                'json'    => $payload,
                'timeout' => 120,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new LlmException(sprintf('%s: appel HTTP impossible (%s)', static::class, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf('Reponse non-JSON du fournisseur (HTTP %d).', $status));
        }

        if ($status >= 400) {
            $this->throwFromErrorBody($status, $body);
        }

        return $decoded;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $query
     *
     * @return array<string, mixed>
     */
    protected function get(string $url, array $headers, array $query = []): array
    {
        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => $headers,
                'query'   => $query,
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new LlmException(sprintf('%s: appel HTTP impossible (%s)', static::class, $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($body, true);

        if (!is_array($decoded)) {
            throw new LlmException(sprintf('Reponse non-JSON du fournisseur (HTTP %d).', $status));
        }

        if ($status >= 400) {
            $this->throwFromErrorBody($status, $body);
        }

        return $decoded;
    }

    /**
     * Consomme un flux SSE et passe chaque evenement decode a $onEvent.
     *
     * Le decoupage se fait sur les lignes et non sur les chunks HTTP : un chunk
     * peut couper un evenement en plein milieu, et deux evenements peuvent
     * arriver dans le meme chunk.
     *
     * @param array<string, mixed>              $payload
     * @param array<string, string>             $headers
     * @param callable(array<string, mixed>): void $onEvent
     */
    protected function streamPost(string $url, array $payload, array $headers, callable $onEvent): void
    {
        try {
            $response = $this->httpClient->request('POST', $url, [
                'headers' => $headers + ['Content-Type' => 'application/json', 'Accept' => 'text/event-stream'],
                'json'    => $payload,
                'timeout' => 300,
                'buffer'  => false,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 400) {
                $this->throwFromErrorBody($status, $response->getContent(false));
            }

            $buffer = '';

            foreach ($this->httpClient->stream($response) as $chunk) {
                if ($chunk->isTimeout()) {
                    continue;
                }

                $buffer .= $chunk->getContent();

                while (false !== ($position = strpos($buffer, "\n"))) {
                    $line   = rtrim(substr($buffer, 0, $position), "\r");
                    $buffer = substr($buffer, $position + 1);

                    if (!str_starts_with($line, 'data:')) {
                        // Lignes "event:", ":" (keep-alive) et lignes vides : sans interet,
                        // le type d'evenement est deja dans la charge utile JSON.
                        continue;
                    }

                    $data = trim(substr($line, 5));

                    if ('' === $data || '[DONE]' === $data) {
                        continue;
                    }

                    $decoded = json_decode($data, true);

                    if (is_array($decoded)) {
                        $onEvent($decoded);
                    }
                }

                if ($chunk->isLast()) {
                    break;
                }
            }
        } catch (LlmException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new LlmException(sprintf('%s: flux interrompu (%s)', static::class, $e->getMessage()), 0, $e);
        }
    }

    /**
     * Les trois fournisseurs renvoient leurs erreurs sous {"error": {...}}.
     */
    protected function throwFromErrorBody(int $status, string $body): never
    {
        $decoded = json_decode($body, true);
        $message = is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['error']['status'] ?? $body)
            : $body;

        throw new LlmException(sprintf('Erreur fournisseur (HTTP %d) : %s', $status, (string) $message));
    }

    /**
     * Gemini et, dans une moindre mesure, les autres fournisseurs refusent
     * certains mots-cles JSON Schema. On nettoie de facon defensive.
     *
     * @param array<mixed> $schema
     *
     * @return array<mixed>
     */
    protected function sanitizeSchema(array $schema, bool $stripAdditionalProperties = false): array
    {
        unset($schema['$schema'], $schema['definitions'], $schema['$defs']);

        if ($stripAdditionalProperties) {
            unset($schema['additionalProperties']);
        }

        foreach (['properties', 'items'] as $key) {
            if (!isset($schema[$key]) || !is_array($schema[$key])) {
                continue;
            }

            if ('items' === $key) {
                $schema[$key] = $this->sanitizeSchema($schema[$key], $stripAdditionalProperties);
                continue;
            }

            foreach ($schema[$key] as $name => $definition) {
                if (is_array($definition)) {
                    $schema[$key][$name] = $this->sanitizeSchema($definition, $stripAdditionalProperties);
                }
            }

            // PHP ne distingue pas tableau vide et objet vide : sans ceci,
            // json_encode() serialise `properties: []` au lieu de `{}` et le
            // fournisseur rejette le schema ("[] is not of type object").
            if ([] === $schema[$key]) {
                $schema[$key] = new \stdClass();
            }
        }

        return $schema;
    }
}
