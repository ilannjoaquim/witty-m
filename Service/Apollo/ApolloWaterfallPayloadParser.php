<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Apollo;

/**
 * Extrait, du JSON POSTe par Apollo sur le webhook waterfall (cf.
 * Controller/ApolloWaterfallWebhookController.php), les seuls champs utiles a
 * conserver pour une demande mono-personne : l'email et/ou le telephone
 * trouves (ou null si la source waterfall n'a rien trouve), plus quelques
 * compteurs pour le contexte. Isole dans sa propre classe (plutot qu'inline
 * dans le controleur) pour rester testable sans avoir a booter le framework.
 *
 * https://docs.apollo.io/docs/enrich-phone-and-email-using-data-waterfall —
 * la charge utile porte toujours un tableau `people`, meme pour une seule
 * personne (l'appel initial n'utilise jamais bulk_match ici, cf.
 * EnrichPersonWaterfallTool) : seul le premier element est exploite.
 */
class ApolloWaterfallPayloadParser
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function extract(array $payload): array
    {
        $person = is_array($payload['people'] ?? null) ? ($payload['people'][0] ?? null) : null;
        $person = is_array($person) ? $person : [];

        $emails = is_array($person['emails'] ?? null) ? $person['emails'] : [];
        $phones = is_array($person['phone_numbers'] ?? null) ? $person['phone_numbers'] : [];

        $firstEmail = is_array($emails[0] ?? null) ? $emails[0] : [];
        $firstPhone = is_array($phones[0] ?? null) ? $phones[0] : [];

        return array_filter([
            'email'                    => '' !== (string) ($firstEmail['email'] ?? '') ? (string) $firstEmail['email'] : null,
            'phone'                    => '' !== (string) ($firstPhone['sanitized_number'] ?? $firstPhone['raw_number'] ?? '')
                ? (string) ($firstPhone['sanitized_number'] ?? $firstPhone['raw_number'])
                : null,
            'email_records_enriched'   => (int) ($payload['email_records_enriched'] ?? 0),
            'mobile_records_enriched'  => (int) ($payload['mobile_records_enriched'] ?? 0),
            'credits_consumed'         => (int) ($payload['credits_consumed'] ?? 0),
        ], static fn ($value): bool => null !== $value);
    }

    /**
     * "success" est le seul statut documente pour une livraison webhook
     * aboutie ; tout le reste (absent, ou une valeur explicitement
     * differente) est traite comme un echec cote plugin plutot que suppose
     * reussi par defaut.
     *
     * @param array<string, mixed> $payload
     */
    public static function isSuccess(array $payload): bool
    {
        return 'success' === ($payload['status'] ?? null);
    }
}
