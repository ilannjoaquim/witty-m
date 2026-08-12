<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Quickenrich;

use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client de l'API REST de QuickEnrich (recherche de contacts, gratuite —
 * `credits_used: 0` — et endpoints de reference publics pour les valeurs de
 * filtre exactes attendues). Authentification par jeton Bearer, contrairement
 * a Apollo (en-tete `x-api-key`) et Prospeo (en-tete `X-KEY`) : un schema
 * different par fournisseur, pas une convention commune a reprendre d'un
 * client a l'autre.
 *
 * Les endpoints de lookup (GET /lookups/*) sont publics selon la doc
 * QuickEnrich (aucune cle requise) : le jeton est neanmoins envoye
 * systematiquement ici, plus simple qu'un chemin de code separe et sans
 * consequence sur un endpoint public.
 */
class QuickenrichClient
{
    private const BASE_URL = 'https://app.quickenrich.io/api';

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
     *
     * @return array<string, mixed>
     */
    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, ['json' => $body]);
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
                    'Authorization' => 'Bearer '.$this->config->getQuickenrichApiKey(),
                    'Accept'        => 'application/json',
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 30,
            ]);
            $status = $response->getStatusCode();
            $body   = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new QuickenrichException(sprintf('QuickEnrich : appel impossible (%s).', $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($body, true);

        if ($status >= 400) {
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $body) : trim($body);

            throw new QuickenrichException(sprintf('QuickEnrich (HTTP %d) : %s', $status, '' !== $message ? $message : 'erreur inconnue'));
        }

        return is_array($decoded) ? $decoded : [];
    }
}
