<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

/**
 * Un template d'email livre avec le plugin : le MJML source (editable dans le
 * builder), le HTML pre-compile (envoye aux destinataires) et les consignes de
 * redaction bloc par bloc.
 *
 * Les consignes ne sont pas de la decoration : c'est ce qui permet au modele de
 * remplir chaque emplacement correctement au lieu d'inventer un email generique.
 */
final class EmailTemplate
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
        public readonly ?string $mjml,
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
     * Emplacements ou un `<br>` litteral doit survivre l'echappement (ex. un
     * accroche sur deux lignes), voir PlaceholderRenderer::escapeHtmlAllowingBr().
     *
     * @return array<int, string>
     */
    public function getHtmlBrContextKeys(): array
    {
        return array_values(array_map(
            static fn (array $p): string => (string) $p['key'],
            array_filter($this->placeholders, static fn (array $p): bool => 'html_br' === ($p['context'] ?? 'html')),
        ));
    }

    /**
     * Emplacements sans valeur par defaut : ceux qu'il faut obligatoirement
     * fournir pour que l'email ait un sens.
     *
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
     * Definition exposee au modele : le libelle et la consigne comptent autant
     * que la cle.
     *
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
