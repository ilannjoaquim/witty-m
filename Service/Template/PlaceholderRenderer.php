<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

/**
 * Substitution de `{{PLACEHOLDER}}`, partagee par les bibliotheques de
 * templates (email, page).
 *
 * Le contexte d'echappement compte : un emplacement qui atterrit dans du HTML
 * doit etre echappe en HTML, mais un emplacement qui atterrit dans une chaine
 * JavaScript (ex. `title: "{{EVENT_TITLE}}"` a l'interieur d'un `<script>`)
 * casserait si on lui appliquait `htmlspecialchars` : une apostrophe y devient
 * `&#039;`, six caracteres litteraux puisque le contenu d'un `<script>` n'est
 * jamais interprete comme du HTML par le navigateur. D'ou la liste
 * `$jsContextKeys`.
 */
final class PlaceholderRenderer
{
    private const PATTERN = '/\{\{([A-Z0-9_]+)\}\}/';

    /**
     * @param array<string, string> $resolved          valeur finale par cle, deja fusionnee avec les defauts
     * @param array<int, string>    $declaredKeys       emplacements reconnus ; le reste ressort intact
     * @param array<int, string>    $jsContextKeys      sous-ensemble a echapper pour une chaine JS plutot que du HTML
     * @param array<int, string>    $htmlBrContextKeys  sous-ensemble HTML ou un <br> litteral doit survivre (ex. un
     *                                                   titre sur deux lignes) ; tout le reste de la valeur reste
     *                                                   echappe normalement, aucune autre balise ne passe
     */
    public static function render(
        string $subject,
        array $resolved,
        array $declaredKeys,
        array $jsContextKeys = [],
        array $htmlBrContextKeys = [],
    ): string {
        $declared = array_flip($declaredKeys);
        $jsKeys   = array_flip($jsContextKeys);
        $brKeys   = array_flip($htmlBrContextKeys);

        return (string) preg_replace_callback(
            self::PATTERN,
            static function (array $matches) use ($resolved, $declared, $jsKeys, $brKeys): string {
                if (!isset($declared[$matches[1]])) {
                    return $matches[0];
                }

                $value = $resolved[$matches[1]] ?? '';

                if (isset($jsKeys[$matches[1]])) {
                    return self::escapeJsString($value);
                }

                return isset($brKeys[$matches[1]])
                    ? self::escapeHtmlAllowingBr($value)
                    : htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $subject,
        );
    }

    /**
     * Echappement HTML standard, puis reouverture du seul `<br>` : une valeur
     * comme `<img src=x onerror=...>` reste neutralisee, une valeur comme
     * `Line one<br>line two` retrouve son retour a la ligne.
     */
    private static function escapeHtmlAllowingBr(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return (string) preg_replace('#&lt;br\s*/?&gt;#i', '<br>', $escaped);
    }

    /**
     * Echappement pour une valeur inseree entre guillemets doubles dans un
     * litteral JS. Un retour a la ligne brut y serait une erreur de syntaxe.
     */
    private static function escapeJsString(string $value): string
    {
        $value = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);

        return str_replace(["\r\n", "\n", "\r"], '\\n', $value);
    }
}
