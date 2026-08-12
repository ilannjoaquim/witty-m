<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Quickenrich\Exception\QuickenrichException;
use MauticPlugin\WittyBundle\Service\Quickenrich\QuickenrichClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Recherche des contacts dans la base QuickEnrich (Contact Finder,
 * `POST /employees/contact-finder`) — PAS dans Mautic, ne pas confondre avec
 * search_contacts (celui-la cherche parmi les contacts DEJA dans Mautic).
 * Base externe, gratuite (`credits_used` toujours 0 pour cet endpoint) : un
 * vrai outil de decouverte, contrairement a Prospeo/Apollo dont la recherche
 * a ete jugee trop couteuse pour etre integree.
 *
 * Endpoint de decouverte uniquement : jamais d email ni de telephone en
 * clair dans la reponse, seulement des booleens has_email/has_phone
 * indiquant si la donnee existe. Reveler la valeur d un contact deja
 * identifie se fait ensuite via quickenrich_find_employee_email /
 * quickenrich_find_employee_phone (Employee Search / Phone Search).
 *
 * Cinq champs (country_code, industry_linkedin, number_of_employees,
 * revenue, services) attendent une chaine exacte issue de
 * quickenrich_list_filter_values, sous peine de 422 — les six autres
 * (title, locality, company_name, company_url, city, bio_li) sont du texte
 * libre.
 */
class QuickenrichSearchContactsTool extends AbstractTool
{
    /** Dimensions a texte libre (aucune contrainte de valeur exacte). */
    private const OPEN_TEXT_DIMENSIONS = ['title', 'locality', 'company_name', 'company_url', 'city', 'bio_li'];

    /** Dimensions a valeur exacte, cf. quickenrich_list_filter_values. */
    private const EXACT_MATCH_DIMENSIONS = ['number_of_employees', 'revenue', 'country_code', 'industry_linkedin', 'services'];

    public function __construct(private QuickenrichClient $quickenrich)
    {
    }

    public function getName(): string
    {
        return 'quickenrich_search_contacts';
    }

    public function getDescription(): string
    {
        return 'Cherche des contacts dans la base QuickEnrich (externe, gratuite), PAS dans Mautic — ne renvoie '
            .'jamais d email/telephone en clair, seulement has_email/has_phone (la donnee existe ou non). Pour '
            .'reveler la vraie valeur d un contact retenu, utiliser ensuite quickenrich_find_employee_email / '
            .'quickenrich_find_employee_phone. Au moins un filtre est obligatoire (un include/exclude non vide sur '
            .'une dimension, ou has_email/has_phone=true). country_code/industry_linkedin/number_of_employees/'
            .'revenue/services attendent une valeur exacte : appeler quickenrich_list_filter_values avant si '
            .'besoin. Limite a 120 appels/minute.';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getSchema(): array
    {
        $properties = [];

        foreach (self::OPEN_TEXT_DIMENSIONS as $dimension) {
            $properties[$dimension] = self::filterDimensionSchema('Texte libre.');
        }

        foreach (self::EXACT_MATCH_DIMENSIONS as $dimension) {
            $properties[$dimension] = self::filterDimensionSchema('Valeur exacte issue de quickenrich_list_filter_values.');
        }

        $properties['has_email'] = ['type' => 'boolean', 'description' => 'true pour ne garder que les contacts avec un email en base (jamais revele ici).'];
        $properties['has_phone'] = ['type' => 'boolean', 'description' => 'true pour ne garder que les contacts avec un telephone en base (jamais revele ici).'];
        $properties['page']      = ['type' => 'integer', 'description' => 'Defaut 1.'];
        $properties['per_page']  = ['type' => 'integer', 'description' => 'Defaut 10, maximum 100.'];

        return $this->schema($properties, []);
    }

    public function execute(array $arguments): array
    {
        $body = [];
        $hasActiveFilter = false;

        foreach ([...self::OPEN_TEXT_DIMENSIONS, ...self::EXACT_MATCH_DIMENSIONS] as $dimension) {
            $raw = is_array($arguments[$dimension] ?? null) ? $arguments[$dimension] : [];

            $include = array_values(array_filter(array_map('strval', (array) ($raw['include'] ?? []))));
            $exclude = array_values(array_filter(array_map('strval', (array) ($raw['exclude'] ?? []))));

            if ([] === $include && [] === $exclude) {
                continue;
            }

            $hasActiveFilter = true;
            $body[$dimension] = ['include' => $include, 'exclude' => $exclude];
        }

        if (true === ($arguments['has_email'] ?? false)) {
            $body['has_email'] = true;
            $hasActiveFilter   = true;
        }

        if (true === ($arguments['has_phone'] ?? false)) {
            $body['has_phone'] = true;
            $hasActiveFilter   = true;
        }

        if (!$hasActiveFilter) {
            return ['status' => 'error', 'error' => 'Au moins un filtre est obligatoire (include/exclude non vide, ou has_email/has_phone=true).'];
        }

        $body['page']     = max(1, (int) ($arguments['page'] ?? 1));
        $body['per_page'] = max(1, min(100, (int) ($arguments['per_page'] ?? 10)));

        try {
            $response = $this->quickenrich->post('/employees/contact-finder', $body);
        } catch (QuickenrichException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok(array_filter([
            'contacts'   => array_values((array) ($response['data'] ?? [])),
            'pagination' => $response['meta'] ?? null,
        ], static fn ($value): bool => null !== $value));
    }

    /**
     * @return array<string, mixed>
     */
    private static function filterDimensionSchema(string $description): array
    {
        return [
            'type'        => 'object',
            'description' => $description,
            'properties'  => [
                'include' => ['type' => 'array', 'items' => ['type' => 'string']],
                'exclude' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
        ];
    }
}
