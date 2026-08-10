<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;

/**
 * Bibliotheque des templates d'email geres depuis la section Witty > Templates
 * (cf. TemplateManager, Controller/TemplateController.php). Chaque template
 * est du HTML final : PHP ne sait pas compiler du MJML (le compilateur
 * officiel tourne en Node ou dans le navigateur, cf. le builder MJML de
 * Mautic base sur grapesjs-mjml) et le plugin n'ajoute aucune dependance pour
 * ca ; un template email s'ecrit donc directement en HTML, comme un template
 * de landing page.
 *
 * Avant la section Templates, ces templates etaient des dossiers livres avec
 * le plugin (Templates/Email/) ; ils ont ete repris dans witty_templates par
 * Migrations/Version_2_8_0.php et restent modifiables/supprimables comme
 * n'importe quel template cree depuis l'UI.
 */
class EmailTemplateLibrary
{
    public function __construct(private TemplateManager $manager)
    {
    }

    /**
     * @return array<string, WittyTemplate>
     */
    public function all(): array
    {
        $templates = [];

        foreach ($this->manager->listByType(WittyTemplate::TYPE_EMAIL) as $template) {
            $templates[$template->getKey()] = $template;
        }

        return $templates;
    }

    public function get(string $key): ?WittyTemplate
    {
        return $this->manager->findByTypeAndKey(WittyTemplate::TYPE_EMAIL, $key);
    }

    /**
     * Remplace les emplacements par les valeurs fournies.
     *
     * Toutes les valeurs sont echappees : le modele produit du texte, pas du
     * HTML. Sans cela, une esperluette ou un chevron dans un titre casserait la
     * mise en page, et une valeur malveillante pourrait injecter du balisage.
     *
     * Static : ne depend d'aucun etat d'instance, uniquement du template et
     * des valeurs fournies. Permet aux tests d'exercer la substitution sur un
     * WittyTemplate construit a la main (cf. BuiltInTemplateLoader) sans base
     * de donnees.
     *
     * @param array<string, string> $values
     *
     * @return array{html: string, missing: array<int, string>}
     */
    public static function render(WittyTemplate $template, array $values): array
    {
        $resolved = $template->getDefaults();

        foreach ($values as $key => $value) {
            $resolved[strtoupper((string) $key)] = (string) $value;
        }

        $missing = array_values(array_diff($template->getRequiredKeys(), array_keys(array_filter(
            $resolved,
            static fn (string $value): bool => '' !== trim($value),
        ))));

        $html = PlaceholderRenderer::render(
            $template->getHtml(),
            $resolved,
            $template->getPlaceholderKeys(),
            [],
            $template->getHtmlBrContextKeys(),
        );

        return ['html' => $html, 'missing' => $missing];
    }
}
