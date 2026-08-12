<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Apollo;

/**
 * Reduit un objet `person`/`organization` Apollo (des dizaines de champs :
 * donnees CRM internes type salesforce_id, technologies detaillees,
 * evenements de levee de fonds...) aux champs reellement exploitables par
 * l'agent — le reste gonflerait le contexte pour rien. Partage entre les
 * versions simple et en masse de chaque outil (meme forme d'objet,
 * EnrichPersonTool.php/BulkEnrichPeopleTool.php et
 * EnrichCompanyTool.php/BulkEnrichCompaniesTool.php).
 *
 * Deux categories de champs volontairement conservees, au-dela de
 * l'identite/coordonnees de base :
 *
 * - **Qualification** (seniority/departments/subdepartments/functions et
 *   employment_history) : ce qui permet a l'agent de repondre a une demande
 *   du type "je veux les contacts avec plus de 5 ans d'experience" —
 *   deductible des dates de employment_history, pas d'un champ direct.
 *   employment_history lui-meme est allege (trimEmploymentHistory()) : les
 *   ids internes/doublons, dates de creation/maj et champs toujours vides
 *   dans les reponses observees (degree, major, grade_level...) sont
 *   retires, seuls poste/entreprise/dates/poste-actuel restent.
 * - **Reseaux sociaux et sites**, personne ET entreprise employeuse : au cas
 *   ou l'utilisateur veuille les mapper vers des champs personnalises Mautic
 *   ensuite (linkedin_url/twitter_url/facebook_url ne sont pas des champs
 *   contact standards de Mautic, mais rien n'empeche d'en creer).
 */
final class ApolloResponseTrimmer
{
    /**
     * @param array<string, mixed> $person
     *
     * @return array<string, mixed>
     */
    public static function trimPerson(array $person): array
    {
        $organization = is_array($person['organization'] ?? null) ? $person['organization'] : [];

        return array_filter([
            'id'           => $person['id'] ?? null,
            'first_name'   => $person['first_name'] ?? null,
            'last_name'    => $person['last_name'] ?? null,
            'name'         => $person['name'] ?? null,
            'title'        => $person['title'] ?? null,
            'email'        => $person['email'] ?? null,
            'email_status' => $person['email_status'] ?? null,
            'city'         => $person['city'] ?? null,
            'state'        => $person['state'] ?? null,
            'country'      => $person['country'] ?? null,

            // Qualification.
            'seniority'         => $person['seniority'] ?? null,
            'departments'       => $person['departments'] ?? null,
            'subdepartments'    => $person['subdepartments'] ?? null,
            'functions'         => $person['functions'] ?? null,
            'employment_history' => self::trimEmploymentHistory((array) ($person['employment_history'] ?? [])),

            // Reseaux sociaux et site, personne.
            'linkedin_url' => $person['linkedin_url'] ?? null,
            'twitter_url'  => $person['twitter_url'] ?? null,
            'facebook_url' => $person['facebook_url'] ?? null,

            // Entreprise employeuse : identite + reseaux sociaux et site.
            'organization_name'          => $organization['name'] ?? null,
            'organization_domain'        => $organization['primary_domain'] ?? null,
            'organization_website'       => $organization['website_url'] ?? null,
            'organization_linkedin_url'  => $organization['linkedin_url'] ?? null,
            'organization_twitter_url'   => $organization['twitter_url'] ?? null,
            'organization_facebook_url'  => $organization['facebook_url'] ?? null,
        ], static fn ($value): bool => null !== $value && [] !== $value);
    }

    /**
     * @param array<string, mixed> $organization
     *
     * @return array<string, mixed>
     */
    public static function trimCompany(array $organization): array
    {
        return array_filter([
            'id'                     => $organization['id'] ?? null,
            'name'                   => $organization['name'] ?? null,
            'website_url'            => $organization['website_url'] ?? null,
            'primary_domain'         => $organization['primary_domain'] ?? null,
            'linkedin_url'           => $organization['linkedin_url'] ?? null,
            'twitter_url'            => $organization['twitter_url'] ?? null,
            'facebook_url'           => $organization['facebook_url'] ?? null,
            'industry'                => $organization['industry'] ?? null,
            'estimated_num_employees' => $organization['estimated_num_employees'] ?? null,
            'city'                   => $organization['city'] ?? null,
            'state'                  => $organization['state'] ?? null,
            'country'                => $organization['country'] ?? null,
            'annual_revenue_printed' => $organization['annual_revenue_printed'] ?? null,
            'founded_year'           => $organization['founded_year'] ?? null,
            'short_description'      => $organization['short_description'] ?? null,
        ], static fn ($value): bool => null !== $value && [] !== $value);
    }

    /**
     * Un `employment_history` brut porte une vingtaine de champs par poste,
     * la plupart toujours vides dans les reponses observees (degree, major,
     * grade_level, raw_address, emails, description...) ou redondants
     * (id/_id/key trois fois le meme identifiant). Seuls poste, entreprise,
     * dates et "poste actuel" servent a qualifier un contact (ex. calculer
     * une anciennete/experience totale).
     *
     * @param array<int, mixed> $history
     *
     * @return array<int, array<string, mixed>>
     */
    private static function trimEmploymentHistory(array $history): array
    {
        $trimmed = [];

        foreach ($history as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $trimmed[] = array_filter([
                'title'             => $entry['title'] ?? null,
                'organization_name' => $entry['organization_name'] ?? null,
                'start_date'        => $entry['start_date'] ?? null,
                'end_date'          => $entry['end_date'] ?? null,
                'current'           => $entry['current'] ?? null,
            ], static fn ($value): bool => null !== $value);
        }

        return $trimmed;
    }
}
