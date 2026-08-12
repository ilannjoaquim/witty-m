<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Apollo;

use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API REST classique d'Apollo (pas le serveur MCP, qui exige
 * OAuth 2.0 cote "partenaire" — hors de portee ici, cf. discussion produit :
 * un utilisateur Apollo s'authentifie simplement par cle API, en-tete
 * `x-api-key` — https://docs.apollo.io/reference/apollo-api).
 *
 * Limite volontairement a l'enrichissement (people/organizations, simple et
 * en masse) : pas de recherche (juge inutile et couteuse), pas de reveal de
 * telephone ni d'enrichissement "waterfall" (les deux exigent un
 * webhook_url public en HTTPS pour la livraison asynchrone du resultat —
 * une brique supplementaire volontairement hors scope pour l'instant).
 */
class ApolloClient
{
    private const BASE_URL = 'https://api.apollo.io/api/v1';

    public function __construct(
        private WittyConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, ['query' => $query]);
    }

    /**
     * @param array<string, mixed> $body
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body, array $query = []): array
    {
        return $this->request('POST', $path, ['query' => $query, 'json' => $body]);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $options): array
    {
        try {
            $response = $this->httpClient->request($method, self::BASE_URL.$path, $options + [
                'headers' => [
                    'x-api-key'    => $this->config->getApolloApiKey(),
                    'Content-Type' => 'application/json',
                ],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new ApolloException(sprintf('Apollo : appel impossible (%s).', $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            $this->throwFromErrorBody($status, $body);
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Trois formes d'erreur rencontrees selon le code HTTP : texte brut
     * (401), `{"error": "...", "error_code": "..."}` (403/422),
     * `{"message": "..."}` (429, quota d'appels).
     */
    private function throwFromErrorBody(int $status, string $body): never
    {
        $decoded = json_decode($body, true);

        $message = is_array($decoded)
            ? (string) ($decoded['error'] ?? $decoded['message'] ?? $body)
            : trim($body);

        throw new ApolloException(sprintf('Apollo (HTTP %d) : %s', $status, '' !== $message ? $message : 'erreur inconnue'));
    }
}
