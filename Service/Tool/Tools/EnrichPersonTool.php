<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloResponseTrimmer;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Enrichit UN profil via l'API Apollo (People Enrichment,
 * `GET /people/match`) : titre, entreprise, localisation et, si demande,
 * email professionnel — a partir d'un identifiant deja connu (nom+entreprise,
 * domaine, email, id Apollo, URL LinkedIn...). Ne cherche jamais de nouveaux
 * profils par criteres (pas de recherche integree, jugee inutile et
 * couteuse) : fournir le maximum d'identifiants connus pour un bon match.
 *
 * Consomme des credits Apollo (1-9 par profil, 0 si rien trouve) des qu une
 * information "credit-consuming" (demographie ou email) est trouvee. Pas de
 * reveal telephone ni d enrichissement "waterfall" ici : les deux exigent un
 * webhook_url public en HTTPS pour la livraison asynchrone, hors scope.
 */
class EnrichPersonTool extends AbstractTool
{
    public function __construct(private ApolloClient $apollo)
    {
    }

    public function getName(): string
    {
        return 'enrich_person';
    }

    public function getDescription(): string
    {
        return 'Enrichit un profil via Apollo (titre, entreprise, localisation, email si reveal_personal_emails). '
            .'Fournis le maximum d identifiants connus (nom+entreprise/domaine, email, id Apollo, URL LinkedIn) pour '
            .'un bon match : une simple recherche par nom seul peut ne rien trouver. Consomme des credits Apollo des '
            .'qu une donnee est trouvee — ne pas appeler en boucle sans raison.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        return $this->schema([
            'first_name'        => ['type' => 'string'],
            'last_name'         => ['type' => 'string'],
            'name'              => ['type' => 'string', 'description' => 'Nom complet, alternative a first_name/last_name.'],
            'email'             => ['type' => 'string'],
            'organization_name' => ['type' => 'string', 'description' => 'Employeur actuel ou passe.'],
            'domain'            => ['type' => 'string', 'description' => 'Domaine de l employeur (sans www., ex. apollo.io).'],
            'id'                => ['type' => 'string', 'description' => 'Identifiant Apollo de la personne, si deja connu.'],
            'linkedin_url'      => ['type' => 'string'],
            'reveal_personal_emails' => ['type' => 'boolean', 'description' => 'Revele l email si trouve (consomme des credits). Defaut false.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        $params = array_filter([
            'first_name'        => trim((string) ($arguments['first_name'] ?? '')),
            'last_name'         => trim((string) ($arguments['last_name'] ?? '')),
            'name'              => trim((string) ($arguments['name'] ?? '')),
            'email'             => trim((string) ($arguments['email'] ?? '')),
            'organization_name' => trim((string) ($arguments['organization_name'] ?? '')),
            'domain'            => trim((string) ($arguments['domain'] ?? '')),
            'id'                => trim((string) ($arguments['id'] ?? '')),
            'linkedin_url'      => trim((string) ($arguments['linkedin_url'] ?? '')),
        ], static fn (string $value): bool => '' !== $value);

        if ([] === $params) {
            return ['status' => 'error', 'error' => 'Au moins un identifiant est obligatoire (name, email, domain, id, linkedin_url...).'];
        }

        if (true === ($arguments['reveal_personal_emails'] ?? false)) {
            $params['reveal_personal_emails'] = 'true';
        }

        try {
            $response = $this->apollo->get('/people/match', $params);
        } catch (ApolloException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $person = is_array($response['person'] ?? null) ? $response['person'] : [];

        if ([] === $person) {
            return $this->ok(['found' => false]);
        }

        return $this->ok(['found' => true, 'person' => ApolloResponseTrimmer::trimPerson($person)]);
    }
}
