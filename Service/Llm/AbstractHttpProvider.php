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
            $message = $decoded['error']['message'] ?? $decoded['error']['status'] ?? $body;
            throw new LlmException(sprintf('Erreur fournisseur (HTTP %d) : %s', $status, (string) $message));
        }

        return $decoded;
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
        }

        return $schema;
    }
}
