<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Enrichit UNE entreprise via l'API Apollo (Organization Enrichment,
 * `GET /organizations/enrich`) : industrie, taille, revenu, technologies,
 * localisation. Au moins un identifiant requis (domain, linkedin_url, name,
 * website) ; en fournir plusieurs ameliore la precision du match.
 *
 * Consomme 1 credit par entreprise trouvee.
 */
class EnrichCompanyTool extends AbstractTool
{
    public function getName(): string
    {
        return 'enrich_company';
    }

    public function __construct(private ApolloClient $apollo)
    {
    }

    public function getDescription(): string
    {
        return 'Enrichit une entreprise via Apollo (industrie, taille, revenu, technologies, localisation). Au moins '
            .'un identifiant obligatoire (domain, linkedin_url, name, website) ; en fournir plusieurs ameliore la '
            .'precision du match. Consomme 1 credit Apollo par entreprise trouvee.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'domain'       => ['type' => 'string', 'description' => 'Domaine de l entreprise (sans www., ex. apollo.io).'],
            'linkedin_url' => ['type' => 'string'],
            'name'         => ['type' => 'string'],
            'website'      => ['type' => 'string', 'description' => 'URL complete du site de l entreprise.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $params = array_filter([
            'domain'       => trim((string) ($arguments['domain'] ?? '')),
            'linkedin_url' => trim((string) ($arguments['linkedin_url'] ?? '')),
            'name'         => trim((string) ($arguments['name'] ?? '')),
            'website'      => trim((string) ($arguments['website'] ?? '')),
        ], static fn (string $value): bool => '' !== $value);

        if ([] === $params) {
            return ['status' => 'error', 'error' => 'Au moins un identifiant est obligatoire (domain, linkedin_url, name, website).'];
        }

        try {
            $response = $this->apollo->get('/organizations/enrich', $params);
        } catch (ApolloException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $organization = is_array($response['organization'] ?? null) ? $response['organization'] : [];

        if ([] === $organization) {
            return $this->ok(['found' => false]);
        }

        return $this->ok(['found' => true, 'organization' => ApolloResponseTrimmer::trimCompany($organization)]);
    }
}
