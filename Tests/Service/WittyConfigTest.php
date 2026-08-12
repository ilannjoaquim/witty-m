<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service;

use Mautic\CoreBundle\Helper\PathsHelper;
use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\IntegrationsBundle\Integration\Interfaces\IntegrationInterface;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;

/**
 * WittyConfig determine, a partir des cles API renseignees, quels fournisseurs
 * le chat peut proposer et avec quel modele : c'est la logique qui remplace
 * l'ancien choix unique "un fournisseur pour toute l'instance".
 */
class WittyConfigTest extends TestCase
{
    public function testNoConfigurationMeansNothingIsAvailable(): void
    {
        $config = $this->configWith(null);

        $this->assertFalse($config->isConfigured());
        $this->assertSame([], $config->getConfiguredProviders());
        $this->assertSame(WittyConfig::PROVIDER_ANTHROPIC, $config->getDefaultProvider(), 'Repli sur Anthropic tant que rien n est configure.');
    }

    public function testOnlyProvidersWithANonEmptyKeyAreConfigured(): void
    {
        $config = $this->configWith(true, [
            'anthropic_api_key' => 'sk-ant-123',
            'openai_api_key'    => '',
            'gemini_api_key'    => '   ',
        ]);

        $this->assertTrue($config->isConfigured());
        $this->assertSame(['anthropic'], $config->getConfiguredProviders());
        $this->assertTrue($config->isProviderConfigured('anthropic'));
        $this->assertFalse($config->isProviderConfigured('openai'), 'Une cle vide ou blanche ne compte pas.');
        $this->assertFalse($config->isProviderConfigured('gemini'));
        $this->assertSame('anthropic', $config->getDefaultProvider());
    }

    public function testUnpublishedIntegrationIsNeverConfigured(): void
    {
        // Des cles presentes mais un plugin desactive ne doit pas rendre le
        // chat utilisable : c'est l'etat "je configure mais je n'ai pas encore
        // valide" et il doit rester inerte.
        $config = $this->configWith(false, ['anthropic_api_key' => 'sk-ant-123']);

        $this->assertFalse($config->isConfigured());
    }

    public function testModelFallsBackToProviderDefaultWhenNotOverridden(): void
    {
        $config = $this->configWith(true, ['anthropic_api_key' => 'sk-ant-123'], []);

        $this->assertSame('claude-sonnet-5', $config->getModel(WittyConfig::PROVIDER_ANTHROPIC));
        $this->assertSame('gpt-4o', $config->getModel(WittyConfig::PROVIDER_OPENAI), 'Meme sans cle, un modele de repli existe toujours.');
    }

    public function testModelOverrideIsPerProviderAndDoesNotLeakToOthers(): void
    {
        $config = $this->configWith(true, ['anthropic_api_key' => 'sk-ant-123'], [
            'anthropic_model' => 'claude-opus-5',
        ]);

        $this->assertSame('claude-opus-5', $config->getModel(WittyConfig::PROVIDER_ANTHROPIC));
        $this->assertSame('gpt-4o', $config->getModel(WittyConfig::PROVIDER_OPENAI), 'La surcharge d un fournisseur ne doit pas affecter les autres.');
    }

    public function testProviderLabelsAreHumanReadable(): void
    {
        $config = $this->configWith(null);

        $this->assertSame('Anthropic (Claude)', $config->getProviderLabel('anthropic'));
        $this->assertSame('OpenAI (GPT)', $config->getProviderLabel('openai'));
        $this->assertSame('Google (Gemini)', $config->getProviderLabel('gemini'));
    }

    public function testBrightDataIsIndependentFromAiProviderKeys(): void
    {
        // Aucune cle fournisseur IA, seulement Bright Data : ne doit ni rendre
        // le plugin "configure" au sens des fournisseurs, ni planter.
        $config = $this->configWith(true, ['brightdata_api_key' => 'bd-token-123']);

        $this->assertTrue($config->isBrightDataConfigured());
        $this->assertSame('bd-token-123', $config->getBrightDataApiKey());
        $this->assertSame([], $config->getConfiguredProviders(), 'Bright Data n est pas un fournisseur de modele.');
    }

    public function testBrightDataRequiresAPublishedIntegrationLikeOtherKeys(): void
    {
        $config = $this->configWith(false, ['brightdata_api_key' => 'bd-token-123']);

        $this->assertFalse($config->isBrightDataConfigured(), 'Un plugin desactive ne doit exposer aucune capacite, meme avec une cle presente.');
    }

    public function testBrightDataProModeDefaultsToDisabled(): void
    {
        $config = $this->configWith(true, ['brightdata_api_key' => 'bd-token-123'], []);

        $this->assertFalse($config->isBrightDataProModeEnabled());

        $config = $this->configWith(true, ['brightdata_api_key' => 'bd-token-123'], ['brightdata_pro_mode' => true]);

        $this->assertTrue($config->isBrightDataProModeEnabled());
    }

    public function testProspeoIsIndependentFromAiProviderKeys(): void
    {
        // Meme raisonnement que Bright Data : une capacite de l agent, pas un
        // fournisseur de modele.
        $config = $this->configWith(true, ['prospeo_api_key' => 'ps-token-123']);

        $this->assertTrue($config->isProspeoConfigured());
        $this->assertSame('ps-token-123', $config->getProspeoApiKey());
        $this->assertSame([], $config->getConfiguredProviders(), 'Prospeo n est pas un fournisseur de modele.');
    }

    public function testProspeoRequiresAPublishedIntegrationLikeOtherKeys(): void
    {
        $config = $this->configWith(false, ['prospeo_api_key' => 'ps-token-123']);

        $this->assertFalse($config->isProspeoConfigured(), 'Un plugin desactive ne doit exposer aucune capacite, meme avec une cle presente.');
    }

    public function testApolloIsIndependentFromAiProviderKeys(): void
    {
        // Meme raisonnement que Bright Data/Prospeo : une capacite de l agent,
        // pas un fournisseur de modele.
        $config = $this->configWith(true, ['apollo_api_key' => 'apollo-token-123']);

        $this->assertTrue($config->isApolloConfigured());
        $this->assertSame('apollo-token-123', $config->getApolloApiKey());
        $this->assertSame([], $config->getConfiguredProviders(), 'Apollo n est pas un fournisseur de modele.');
    }

    public function testApolloRequiresAPublishedIntegrationLikeOtherKeys(): void
    {
        $config = $this->configWith(false, ['apollo_api_key' => 'apollo-token-123']);

        $this->assertFalse($config->isApolloConfigured(), 'Un plugin desactive ne doit exposer aucune capacite, meme avec une cle presente.');
    }

    public function testQuickenrichIsIndependentFromAiProviderKeys(): void
    {
        // Meme raisonnement que Bright Data/Prospeo/Apollo : une capacite de
        // l agent, pas un fournisseur de modele.
        $config = $this->configWith(true, ['quickenrich_api_key' => 'qe-token-123']);

        $this->assertTrue($config->isQuickenrichConfigured());
        $this->assertSame('qe-token-123', $config->getQuickenrichApiKey());
        $this->assertSame([], $config->getConfiguredProviders(), 'QuickEnrich n est pas un fournisseur de modele.');
    }

    public function testQuickenrichRequiresAPublishedIntegrationLikeOtherKeys(): void
    {
        $config = $this->configWith(false, ['quickenrich_api_key' => 'qe-token-123']);

        $this->assertFalse($config->isQuickenrichConfigured(), 'Un plugin desactive ne doit exposer aucune capacite, meme avec une cle presente.');
    }

    /**
     * Contrairement a Bright Data/Prospeo/Apollo/QuickEnrich, data.gouv.fr
     * n a pas de cle API (serveur public) : c est un simple interrupteur
     * feature_settings qui gate la capacite, pas api_keys.
     */
    public function testDatagouvIsGatedByASwitchNotAKey(): void
    {
        $config = $this->configWith(true, [], ['datagouv_enabled' => true]);

        $this->assertTrue($config->isDatagouvEnabled());
        $this->assertSame([], $config->getConfiguredProviders(), 'data.gouv.fr n est pas un fournisseur de modele.');
    }

    public function testDatagouvDefaultsToDisabled(): void
    {
        $config = $this->configWith(true);

        $this->assertFalse($config->isDatagouvEnabled());
    }

    public function testDatagouvRequiresAPublishedIntegrationLikeOtherCapabilities(): void
    {
        $config = $this->configWith(false, [], ['datagouv_enabled' => true]);

        $this->assertFalse($config->isDatagouvEnabled(), 'Un plugin desactive ne doit exposer aucune capacite, meme l interrupteur active.');
    }

    /**
     * @param array<string, string> $apiKeys
     * @param array<string, string> $featureSettings
     */
    private function configWith(?bool $published, array $apiKeys = [], array $featureSettings = []): WittyConfig
    {
        $helper      = $this->createMock(IntegrationsHelper::class);
        $pathsHelper = $this->createMock(PathsHelper::class);

        if (null === $published) {
            $helper->method('getIntegration')->willThrowException(new IntegrationNotFoundException('Witty'));

            return new WittyConfig($helper, $pathsHelper);
        }

        $integration = $this->createMock(Integration::class);
        $integration->method('getIsPublished')->willReturn($published);
        $integration->method('getApiKeys')->willReturn($apiKeys);
        $integration->method('getFeatureSettings')->willReturn(['integration' => $featureSettings]);

        $object = $this->createMock(IntegrationInterface::class);
        $object->method('getIntegrationConfiguration')->willReturn($integration);

        $helper->method('getIntegration')->willReturn($object);

        return new WittyConfig($helper, $pathsHelper);
    }
}
