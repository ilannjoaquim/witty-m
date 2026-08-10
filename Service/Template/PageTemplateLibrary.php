<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;

/**
 * Bibliotheque des templates de landing page geres depuis la section
 * Witty > Templates (cf. TemplateManager, Controller/TemplateController.php).
 *
 * Avant la section Templates, ces templates etaient des dossiers livres avec
 * le plugin (Templates/Page/) ; ils ont ete repris dans witty_templates par
 * Migrations/Version_2_8_0.php et restent modifiables/supprimables comme
 * n'importe quel template cree depuis l'UI.
 */
class PageTemplateLibrary
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

        foreach ($this->manager->listByType(WittyTemplate::TYPE_PAGE) as $template) {
            $templates[$template->getKey()] = $template;
        }

        return $templates;
    }

    public function get(string $key): ?WittyTemplate
    {
        return $this->manager->findByTypeAndKey(WittyTemplate::TYPE_PAGE, $key);
    }

    /**
     * Static : voir EmailTemplateLibrary::render(), meme raisonnement.
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
            $template->getJsContextKeys(),
            $template->getHtmlBrContextKeys(),
        );

        return ['html' => $html, 'missing' => $missing];
    }
}
