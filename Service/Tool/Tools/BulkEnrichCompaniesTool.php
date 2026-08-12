<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Enrichit jusqu'a 10 entreprises en un appel via l'API Apollo (Bulk
 * Organization Enrichment, `POST /organizations/bulk_enrich`). Meme logique
 * que enrich_company, en masse : chaque entree doit fournir au moins un
 * identifiant (domain, linkedin_url, name, website).
 *
 * Consomme 1 credit par entreprise trouvee.
 */
class BulkEnrichCompaniesTool extends AbstractTool
{
    private const MAX_COMPANIES = 10;

    public function __construct(private ApolloClient $apollo)
    {
    }

    public function getName(): string
    {
        return 'bulk_enrich_companies';
    }

    public function getDescription(): string
    {
        return 'Enrichit jusqu a '.self::MAX_COMPANIES.' entreprises en un appel via Apollo. companies est un '
            .'tableau, chaque entree avec au moins un identifiant (domain, linkedin_url, name, website). Consomme 1 '
            .'credit Apollo par entreprise trouvee.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'companies' => [
                'type'        => 'array',
                'description' => 'Un objet par entreprise a enrichir, max '.self::MAX_COMPANIES.'.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'domain'       => ['type' => 'string'],
                        'linkedin_url' => ['type' => 'string'],
                        'name'         => ['type' => 'string'],
                        'website'      => ['type' => 'string'],
                    ],
                ],
            ],
        ], ['companies']);
    }

    public function execute(array $arguments): array
    {
        $rawCompanies = (array) ($arguments['companies'] ?? []);

        if ([] === $rawCompanies) {
            return ['status' => 'error', 'error' => 'companies est obligatoire et ne peut pas etre vide.'];
        }

        if (count($rawCompanies) > self::MAX_COMPANIES) {
            return ['status' => 'error', 'error' => sprintf('Trop d entreprises (%d, maximum %d). Repartis-les sur plusieurs appels.', count($rawCompanies), self::MAX_COMPANIES)];
        }

        $details = [];

        foreach ($rawCompanies as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $fields = array_filter(
                array_map('strval', $entry),
                static fn (string $value): bool => '' !== trim($value),
            );

            if ([] !== $fields) {
                $details[] = $fields;
            }
        }

        if ([] === $details) {
            return ['status' => 'error', 'error' => 'Aucune entreprise valide : chaque entree doit avoir au moins un identifiant.'];
        }

        try {
            $response = $this->apollo->post('/organizations/bulk_enrich', ['details' => $details]);
        } catch (ApolloException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $organizations = array_map(
            static fn (array $organization): array => ApolloResponseTrimmer::trimCompany($organization),
            array_values(array_filter((array) ($response['organizations'] ?? []), 'is_array')),
        );

        return $this->ok(array_filter([
            'requested'        => count($details),
            'missing_records'  => $response['missing_records'] ?? null,
            'organizations'    => $organizations,
        ], static fn ($value): bool => null !== $value));
    }
}
