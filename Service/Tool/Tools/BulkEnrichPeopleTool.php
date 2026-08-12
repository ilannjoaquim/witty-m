<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Enrichit jusqu'a 10 profils en un appel via l'API Apollo (Bulk People
 * Enrichment, `POST /people/bulk_match`). Meme logique que enrich_person,
 * en masse : chaque entree de people doit fournir au moins un identifiant
 * exploitable (nom+entreprise/domaine, email, id Apollo, URL LinkedIn).
 *
 * Credits factures uniquement pour les profils ou une donnee est trouvee
 * (voir credits_consumed dans la reponse), jamais pour un profil non trouve.
 */
class BulkEnrichPeopleTool extends AbstractTool
{
    private const MAX_PEOPLE = 10;

    public function __construct(private ApolloClient $apollo)
    {
    }

    public function getName(): string
    {
        return 'bulk_enrich_people';
    }

    public function getDescription(): string
    {
        return 'Enrichit jusqu a '.self::MAX_PEOPLE.' profils en un appel via Apollo. people est un tableau, chaque '
            .'entree avec au moins un identifiant exploitable (first_name+last_name ou name, email, organization_name/domain, '
            .'id, linkedin_url). Consomme des credits uniquement pour les profils ou une donnee est trouvee.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'people' => [
                'type'        => 'array',
                'description' => 'Un objet par profil a enrichir, max '.self::MAX_PEOPLE.'.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'first_name'        => ['type' => 'string'],
                        'last_name'         => ['type' => 'string'],
                        'name'              => ['type' => 'string'],
                        'email'             => ['type' => 'string'],
                        'organization_name' => ['type' => 'string'],
                        'domain'            => ['type' => 'string'],
                        'id'                => ['type' => 'string'],
                        'linkedin_url'      => ['type' => 'string'],
                    ],
                ],
            ],
            'reveal_personal_emails' => ['type' => 'boolean', 'description' => 'Revele les emails trouves pour tous les profils matches (consomme des credits). Defaut false.'],
        ], ['people']);
    }

    public function execute(array $arguments): array
    {
        $rawPeople = (array) ($arguments['people'] ?? []);

        if ([] === $rawPeople) {
            return ['status' => 'error', 'error' => 'people est obligatoire et ne peut pas etre vide.'];
        }

        if (count($rawPeople) > self::MAX_PEOPLE) {
            return ['status' => 'error', 'error' => sprintf('Trop de profils (%d, maximum %d). Repartis-les sur plusieurs appels.', count($rawPeople), self::MAX_PEOPLE)];
        }

        $details = [];

        foreach ($rawPeople as $entry) {
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
            return ['status' => 'error', 'error' => 'Aucun profil valide : chaque entree doit avoir au moins un identifiant.'];
        }

        $query = true === ($arguments['reveal_personal_emails'] ?? false) ? ['reveal_personal_emails' => 'true'] : [];

        try {
            $response = $this->apollo->post('/people/bulk_match', ['details' => $details], $query);
        } catch (ApolloException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $matches = array_map(
            static fn (array $person): array => ApolloResponseTrimmer::trimPerson($person),
            array_values(array_filter((array) ($response['matches'] ?? []), 'is_array')),
        );

        return $this->ok(array_filter([
            'requested'        => count($details),
            'credits_consumed' => $response['credits_consumed'] ?? null,
            'missing_records'  => $response['missing_records'] ?? null,
            'matches'          => $matches,
        ], static fn ($value): bool => null !== $value));
    }
}
