<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Liste les valeurs exactes acceptees par quickenrich_search_contacts pour
 * ses dimensions a correspondance stricte (country_code, industry_linkedin,
 * number_of_employees, revenue, services) — une valeur hors liste y renvoie
 * une erreur 422. Relaie un des cinq endpoints de reference publics de
 * QuickEnrich (GET /lookups/*, aucune cle requise cote QuickEnrich mais
 * meme gate isQuickenrichConfigured() que le reste de l integration, pour
 * rester coherent).
 */
class QuickenrichListFilterValuesTool extends AbstractTool
{
    /** dimension (argument accepte) -> chemin de l endpoint de reference correspondant. */
    private const ENDPOINTS = [
        'country_code'       => '/lookups/country-codes',
        'industry_linkedin'  => '/lookups/industries',
        'number_of_employees' => '/lookups/employee-ranges',
        'revenue'            => '/lookups/revenue-ranges',
        'services'           => '/lookups/company-services',
    ];

    public function __construct(private QuickenrichClient $quickenrich)
    {
    }

    public function getName(): string
    {
        return 'quickenrich_list_filter_values';
    }

    public function getDescription(): string
    {
        return 'Liste les valeurs exactes acceptees par quickenrich_search_contacts pour une dimension a '
            .'correspondance stricte (country_code, industry_linkedin, number_of_employees, revenue, services). '
            .'A appeler avant de filtrer sur une de ces dimensions : une valeur hors liste declenche une erreur. '
            .'Pour services (liste ouverte), q filtre par autocompletion plutot que de tout renvoyer.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'dimension' => ['type' => 'string', 'enum' => array_keys(self::ENDPOINTS)],
            'q'         => ['type' => 'string', 'description' => 'Filtre par autocompletion, utilise seulement par dimension=services.'],
        ], ['dimension']);
    }

    public function execute(array $arguments): array
    {
        $dimension = (string) ($arguments['dimension'] ?? '');
        $endpoint  = self::ENDPOINTS[$dimension] ?? null;

        if (null === $endpoint) {
            return ['status' => 'error', 'error' => sprintf('dimension inconnue : %s. Valeurs acceptees : %s', $dimension, implode(', ', array_keys(self::ENDPOINTS)))];
        }

        $query = [];

        if ('services' === $dimension) {
            $q = trim((string) ($arguments['q'] ?? ''));

            if ('' !== $q) {
                $query['q'] = $q;
            }
        }

        try {
            $response = $this->quickenrich->get($endpoint, $query);
        } catch (QuickenrichException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $values = is_array($response['data'] ?? null) ? array_values($response['data']) : array_values($response);

        return $this->ok(['dimension' => $dimension, 'values' => $values]);
    }
}
