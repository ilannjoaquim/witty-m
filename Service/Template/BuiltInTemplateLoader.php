<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Template;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;

/**
 * Lit les templates encore livres en fichiers dans Templates/Email/ et
 * Templates/Page/ (manifest.json + template.html) pour produire des
 * WittyTemplate en memoire, non persistes.
 *
 * Deux consommateurs seulement, tous deux volontairement hors de la boucle
 * d'execution normale :
 *
 * - Migrations/Version_2_8_0.php, pour peupler witty_templates a
 *   l'installation/mise a jour du plugin (une seule fois) ;
 * - les tests de Service/Template/*LibraryTest.php, pour verifier la
 *   substitution sur des templates realistes sans toucher de base de
 *   donnees.
 *
 * A l'execution normale, EmailTemplateLibrary/PageTemplateLibrary lisent
 * uniquement TemplateManager (donc la base) : ces dossiers ne sont plus
 * consultes. Ils restent neanmoins la source la plus pratique pour
 * ecrire/relire du HTML avec la coloration syntaxique d'un editeur plutot
 * que dans un champ de formulaire.
 */
final class BuiltInTemplateLoader
{
    public static function loadEmail(string $key): ?WittyTemplate
    {
        return self::load(WittyTemplate::TYPE_EMAIL, self::emailDirectory().'/'.$key);
    }

    public static function loadPage(string $key): ?WittyTemplate
    {
        return self::load(WittyTemplate::TYPE_PAGE, self::pageDirectory().'/'.$key);
    }

    /**
     * Tous les templates livres, des deux types : c'est ce que la migration
     * d'installation seme dans witty_templates.
     *
     * @return WittyTemplate[]
     */
    public static function all(): array
    {
        $templates = [];

        foreach (self::directories(self::emailDirectory()) as $path) {
            $template = self::load(WittyTemplate::TYPE_EMAIL, $path);

            if (null !== $template) {
                $templates[] = $template;
            }
        }

        foreach (self::directories(self::pageDirectory()) as $path) {
            $template = self::load(WittyTemplate::TYPE_PAGE, $path);

            if (null !== $template) {
                $templates[] = $template;
            }
        }

        return $templates;
    }

    /**
     * @return array<int, string>
     */
    private static function directories(string $directory): array
    {
        if (!is_dir($directory)) {
            return [];
        }

        return array_map('strval', (array) glob($directory.'/*', GLOB_ONLYDIR));
    }

    private static function load(string $type, string $path): ?WittyTemplate
    {
        $manifestFile = $path.'/manifest.json';
        $htmlFile     = $path.'/template.html';

        if (!is_file($manifestFile) || !is_file($htmlFile)) {
            return null;
        }

        $manifest = json_decode((string) file_get_contents($manifestFile), true);

        if (!is_array($manifest)) {
            return null;
        }

        $template = new WittyTemplate();
        $template->setType($type);
        $template->setKey((string) ($manifest['key'] ?? basename($path)));
        $template->setName((string) ($manifest['name'] ?? basename($path)));
        $template->setDescription((string) ($manifest['description'] ?? ''));
        $template->setGoal((string) ($manifest['goal'] ?? ''));
        $template->setRules(array_map('strval', (array) ($manifest['rules'] ?? [])));
        $template->setPlaceholders(array_values((array) ($manifest['placeholders'] ?? [])));
        $template->setHtml((string) file_get_contents($htmlFile));

        return $template;
    }

    private static function emailDirectory(): string
    {
        return \dirname(__DIR__, 2).'/Templates/Email';
    }

    private static function pageDirectory(): string
    {
        return \dirname(__DIR__, 2).'/Templates/Page';
    }
}
