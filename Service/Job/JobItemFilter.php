<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job;

/**
 * Regles de filtrage DECLARATIVES appliquees au resultat d'un job source
 * (cf. Service/Job/Handlers/ImportContactsFromJobHandler.php) : volontairement
 * une poignee d'operateurs fixes plutot que du code arbitraire fourni par
 * l'agent — un script genere a la volee, execute sans supervision en tache de
 * fond, ouvrirait un acces non borne a l application (voir discussion produit
 * qui a mene a ce choix). Ces operateurs couvrent le besoin reel ("ignore les
 * lignes QuickEnrich sans email ni telephone") sans etre Turing-complets.
 *
 * `path` supporte la notation pointee pour descendre dans un resultat imbrique
 * (ex. "useremail.email" pour un item Apollo), la meme convention que
 * McpBulkSearchJobHandler n'a pas besoin mais qui se retrouve naturellement
 * dans des resultats Apollo/QuickEnrich a plusieurs niveaux.
 */
class JobItemFilter
{
    /**
     * @param array<string, mixed> $data
     */
    public static function resolvePath(array $data, string $path): mixed
    {
        if ('' === $path) {
            return null;
        }

        $current = $data;

        foreach (explode('.', $path) as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }

            $current = $current[$segment];
        }

        return $current;
    }

    /**
     * @param array<string, mixed>       $data
     * @param array<int, array<string, mixed>> $filters
     */
    public static function matchesAll(array $data, array $filters): bool
    {
        foreach ($filters as $filter) {
            if (!is_array($filter) || !self::matchesOne($data, $filter)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $filter
     */
    private static function matchesOne(array $data, array $filter): bool
    {
        $op    = (string) ($filter['op'] ?? '');
        $value = self::resolvePath($data, (string) ($filter['path'] ?? ''));

        return match ($op) {
            'has_field'        => null !== $value,
            'field_not_empty'  => null !== $value && '' !== trim((string) $value),
            'field_empty'      => null === $value || '' === trim((string) $value),
            'field_equals'     => self::looseEquals($value, $filter['value'] ?? null),
            'field_not_equals' => !self::looseEquals($value, $filter['value'] ?? null),
            // @ : un pattern PCRE invalide fourni par l'agent ne doit jamais
            // faire planter tout le job, seulement ecarter cette ligne (echec
            // ferme, plus sur qu un match par defaut).
            'field_matches'    => null !== $value && 1 === @preg_match((string) ($filter['pattern'] ?? ''), (string) $value),
            // Operateur inconnu : echec ferme (la ligne est ecartee), jamais
            // suppose passant par defaut.
            default            => false,
        };
    }

    private static function looseEquals(mixed $a, mixed $b): bool
    {
        return $a == $b; // @phpstan-ignore-line comparaison volontairement souple (types JSON heterogenes attendus)
    }
}
