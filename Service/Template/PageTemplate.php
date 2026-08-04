<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

/**
 * Un template de landing page livre avec le plugin.
 *
 * A la difference d'un template d'email, le HTML n'est jamais compile ni
 * rendu par une integration Mautic : il est injecte tel quel dans
 * Page::customHtml, avec template='mautic_code_mode'. C'est le mecanisme natif
 * de Mautic pour figer une page en edition "code source" (voir ThemeListType) :
 * ouvrir la page dans le builder GrapesJS plus tard passerait le HTML dans son
 * moteur de rendu par composants, qui ne garantit pas la survie d'un <script>.
 * Un template avec de la logique JavaScript (compte a rebours, etats
 * dynamiques...) doit passer par ce mecanisme et non par un theme.
 */
final class PageTemplate
{
    /**
     * @param array<int, string>               $rules
     * @param array<int, array<string, mixed>> $placeholders
     */
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly string $description,
        public readonly string $goal,
        public readonly array $rules,
        public readonly array $placeholders,
        public readonly string $html,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function getPlaceholderKeys(): array
    {
        return array_map(static fn (array $p): string => (string) $p['key'], $this->placeholders);
    }

    /**
     * Emplacements atterrissant dans une chaine JavaScript (a l'interieur d'un
     * `<script>`) plutot que dans du HTML visible : ils ont besoin d'un
     * echappement different, voir PlaceholderRenderer.
     *
     * @return array<int, string>
     */
    public function getJsContextKeys(): array
    {
        return $this->keysWithContext('js');
    }

    /**
     * Emplacements HTML ou un `<br>` litteral doit survivre l'echappement
     * (titre sur deux lignes), voir PlaceholderRenderer::escapeHtmlAllowingBr().
     *
     * @return array<int, string>
     */
    public function getHtmlBrContextKeys(): array
    {
        return $this->keysWithContext('html_br');
    }

    /**
     * @return array<int, string>
     */
    private function keysWithContext(string $context): array
    {
        return array_values(array_map(
            static fn (array $p): string => (string) $p['key'],
            array_filter($this->placeholders, static fn (array $p): bool => $context === ($p['context'] ?? 'html')),
        ));
    }

    /**
     * @return array<int, string>
     */
    public function getRequiredKeys(): array
    {
        $required = [];

        foreach ($this->placeholders as $placeholder) {
            if (!array_key_exists('default', $placeholder)) {
                $required[] = (string) $placeholder['key'];
            }
        }

        return $required;
    }

    /**
     * @return array<string, string>
     */
    public function getDefaults(): array
    {
        $defaults = [];

        foreach ($this->placeholders as $placeholder) {
            if (array_key_exists('default', $placeholder)) {
                $defaults[(string) $placeholder['key']] = (string) $placeholder['default'];
            }
        }

        return $defaults;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function describePlaceholders(): array
    {
        return array_map(static fn (array $p): array => array_filter([
            'key'      => $p['key'] ?? '',
            'label'    => $p['label'] ?? '',
            'guidance' => $p['guidance'] ?? '',
            'example'  => $p['example'] ?? null,
            'default'  => $p['default'] ?? null,
            'required' => !array_key_exists('default', $p),
        ], static fn ($value): bool => null !== $value), $this->placeholders);
    }
}
