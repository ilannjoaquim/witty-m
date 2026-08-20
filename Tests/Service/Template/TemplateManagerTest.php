<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Template;

use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use PHPUnit\Framework\TestCase;

/**
 * normalizePlaceholders() est le point d'entree partage par
 * create_template/update_template. Bug reel corrige en session, reproduit
 * contre la vraie base locale (confirmation-webinar, 9 emplacements en
 * contexte js sur 37) : un aller-retour list_page_templates -> update_template
 * effacait le contexte js/html_br de tout emplacement dont l'agent
 * recopiait la sortie de WittyTemplate::describePlaceholders() (qui
 * n'exposait pas 'context') vers l'entree d'update_template (qui l'exige
 * pour ne pas retomber sur 'html'). Corrige des deux cotes : voir
 * Tests/Entity/WittyTemplateTest.php pour le cote lecture.
 */
class TemplateManagerTest extends TestCase
{
    public function testContextIsPreservedWhenProvided(): void
    {
        $result = TemplateManager::normalizePlaceholders([
            ['key' => 'a', 'context' => 'js'],
            ['key' => 'b', 'context' => 'html_br'],
        ]);

        $this->assertSame('js', $result[0]['context']);
        $this->assertSame('html_br', $result[1]['context']);
    }

    public function testContextDefaultsToHtmlWhenAbsent(): void
    {
        $result = TemplateManager::normalizePlaceholders([['key' => 'a']]);

        $this->assertSame('html', $result[0]['context']);
    }

    public function testAnInvalidContextValueFallsBackToHtmlRatherThanBeingStoredAsIs(): void
    {
        $result = TemplateManager::normalizePlaceholders([['key' => 'a', 'context' => 'bogus']]);

        $this->assertSame('html', $result[0]['context']);
    }

    public function testUnknownFieldsAreStrippedRatherThanPersisted(): void
    {
        // 'required' est un champ CALCULE par WittyTemplate::describePlaceholders(),
        // jamais stocke : un agent qui recopie tel quel ce qu'il a lu ne doit
        // jamais le faire persister (deviendrait faux des qu'un 'default' est
        // ajoute/retire ensuite, puisque rien ne le relit depuis le stockage).
        $result = TemplateManager::normalizePlaceholders([
            ['key' => 'a', 'label' => 'Label', 'required' => false, 'bogus_field' => 'x'],
        ]);

        $this->assertArrayNotHasKey('required', $result[0]);
        $this->assertArrayNotHasKey('bogus_field', $result[0]);
        $this->assertSame('Label', $result[0]['label']);
    }

    public function testFullRoundTripThroughDescribePlaceholdersNeverLosesTheRealContext(): void
    {
        // Reproduit exactement le scenario du bug : lire via describePlaceholders()
        // (ce que l'agent voit via list_email_templates/list_page_templates),
        // modifier un seul champ, renvoyer le tableau complet a normalizePlaceholders()
        // (ce qu'update_template applique).
        $template = new \MauticPlugin\WittyBundle\Entity\WittyTemplate();
        $template->setPlaceholders([
            ['key' => 'TITLE', 'label' => 'Titre', 'context' => 'html'],
            ['key' => 'COUNTDOWN_SECONDS', 'label' => 'Secondes', 'context' => 'js'],
            ['key' => 'HOOK', 'label' => 'Accroche', 'context' => 'html_br'],
        ]);

        $agentView               = $template->describePlaceholders();
        $agentView[0]['label']   = 'Titre (modifie)';

        $written = TemplateManager::normalizePlaceholders($agentView);

        $this->assertSame('html', $written[0]['context']);
        $this->assertSame('js', $written[1]['context'], 'Le contexte js ne doit jamais se perdre dans un aller-retour.');
        $this->assertSame('html_br', $written[2]['context'], 'Le contexte html_br ne doit jamais se perdre dans un aller-retour.');
        $this->assertSame('Titre (modifie)', $written[0]['label']);
    }
}
