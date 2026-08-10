<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Template;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\BuiltInTemplateLoader;
use MauticPlugin\WittyBundle\Service\Template\EmailTemplateLibrary;
use PHPUnit\Framework\TestCase;

/**
 * La substitution reçoit du texte produit par un modèle et le place dans du
 * HTML envoyé à des contacts : l'échappement et le respect des emplacements
 * déclarés ne sont pas des détails, ce sont les garanties du mécanisme.
 *
 * Exerce EmailTemplateLibrary::render() (statique, aucun etat) sur les
 * templates encore livres en fichiers via BuiltInTemplateLoader : ce sont les
 * memes qui sont semes dans witty_templates par Migrations/Version_2_8_0.php,
 * mais lire directement les fichiers evite toute dependance base de donnees
 * dans ce test.
 */
class EmailTemplateLibraryTest extends TestCase
{
    public function testWebinarTemplateIsShippedAndComplete(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar');

        $this->assertNotNull($template, 'Le template webinar doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->getHtml(), 'Le HTML compile doit etre present (dev/build-templates.sh).');

        // Chaque emplacement du HTML doit etre documente, sinon le modele ne
        // saura pas quoi mettre dedans et la valeur restera vide.
        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->getHtml(), $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testValuesAreEscapedBeforeReachingTheHtml(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar');

        $rendered = EmailTemplateLibrary::render($template, ['HEADLINE' => '<script>alert(1)</script> & "quoted"']);

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered['html']);
        $this->assertStringContainsString('&lt;script&gt;', $rendered['html']);
        $this->assertStringContainsString('&amp;', $rendered['html']);
    }

    public function testDefaultsFillTheImagePlaceholders(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar');

        $rendered = EmailTemplateLibrary::render($template, []);

        $this->assertStringContainsString('placehold.co', $rendered['html'], 'Le logo doit avoir une image par defaut.');
        $this->assertStringContainsString('giphy.gif', $rendered['html'], 'Le visuel d accroche doit avoir un GIF par defaut.');
    }

    public function testMissingRequiredPlaceholdersAreReported(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar');

        $rendered = EmailTemplateLibrary::render($template, ['HEADLINE' => 'Demain 10h30']);

        $this->assertNotContains('HEADLINE', $rendered['missing']);
        $this->assertContains('PROBLEM', $rendered['missing']);
        $this->assertNotContains('LOGO_URL', $rendered['missing'], 'Un emplacement avec valeur par defaut n est pas obligatoire.');
    }

    public function testMauticTokensSurviveSubstitution(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar');

        $rendered = EmailTemplateLibrary::render($template, []);

        foreach (['{contactfield=firstname|there}', '{contactfield=email}', '{webview_url}', '{unsubscribe_url}'] as $token) {
            $this->assertStringContainsString($token, $rendered['html'], sprintf('Le token Mautic %s doit rester intact.', $token));
        }
    }

    public function testUndeclaredBracesAreLeftUntouched(): void
    {
        // Un template peut documenter sa propre syntaxe dans son HTML
        // (ex. un commentaire) sans que ca soit un emplacement : ce n'est pas
        // declare dans les placeholders, ca ne doit donc pas etre efface.
        $template = new WittyTemplate();
        $template->setType(WittyTemplate::TYPE_EMAIL);
        $template->setPlaceholders([['key' => 'HEADLINE']]);
        $template->setHtml('<p>{{HEADLINE}}</p><!-- Placeholders are written {{LIKE_THIS}} -->');

        $rendered = EmailTemplateLibrary::render($template, ['HEADLINE' => 'Demain 10h30']);

        $this->assertStringContainsString('{{LIKE_THIS}}', $rendered['html']);
        $this->assertStringContainsString('Demain 10h30', $rendered['html']);
    }

    public function testWebinarDay0TemplateIsShippedAndComplete(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar-day0');

        $this->assertNotNull($template, 'Le template webinar-day0 doit etre livre avec le plugin.');
        $this->assertNotSame('', $template->getHtml());

        preg_match_all('/\{\{([A-Z0-9_]+)\}\}/', $template->getHtml(), $matches);

        $this->assertSame(
            [],
            array_values(array_diff(array_unique($matches[1]), $template->getPlaceholderKeys())),
            'Tout emplacement present dans le HTML doit etre decrit dans le manifeste.',
        );
    }

    public function testHookLineAllowsLiteralLineBreakButBlocksOtherTags(): void
    {
        $template = BuiltInTemplateLoader::loadEmail('webinar-day0');

        $rendered = EmailTemplateLibrary::render($template, [
            'HOOK' => 'Everyone wants leads.<br/>Almost nobody fixes follow-up.<img src=x onerror=alert(1)>',
        ]);

        // La normalisation produit toujours <br> (sans slash), quelle que soit
        // la forme fournie en entree.
        $this->assertStringContainsString('Everyone wants leads.<br>Almost nobody fixes follow-up.', $rendered['html']);
        // Pas une recherche generique de "<img" : le HTML compile contient de
        // vraies balises <img> ailleurs (logo, visuel d accroche). Seule la
        // charge injectee dans HOOK doit rester neutralisee.
        $this->assertStringNotContainsString('<img src=x onerror=alert(1)>', $rendered['html']);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(1)&gt;', $rendered['html']);
    }
}
