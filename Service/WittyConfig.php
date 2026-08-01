<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service;

use Mautic\IntegrationsBundle\Exception\IntegrationNotFoundException;
use Mautic\IntegrationsBundle\Helper\IntegrationsHelper;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\WittyBundle\Integration\WittyIntegration;

/**
 * Acces centralise a la configuration du plugin.
 *
 * La source de verite est l'integration Mautic (Parametres > Plugins > Witty) :
 * la cle API vit dans la colonne api_keys, chiffree par Mautic, le reste dans
 * feature_settings. Rien n'est ecrit dans local.php.
 */
class WittyConfig
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';

    public const DEFAULT_MAX_ITERATIONS = 8;

    /**
     * ATTENTION : les identifiants de modeles evoluent vite chez les trois
     * fournisseurs. Ce sont uniquement des valeurs de repli ; le modele exact
     * se renseigne dans la fiche du plugin.
     */
    private const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC => 'claude-sonnet-5',
        self::PROVIDER_OPENAI    => 'gpt-4o',
        self::PROVIDER_GEMINI    => 'gemini-2.5-flash',
    ];

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    public function getProvider(): string
    {
        $provider = (string) ($this->getFeatureSettings()['provider'] ?? '');

        return array_key_exists($provider, self::DEFAULT_MODELS) ? $provider : self::PROVIDER_ANTHROPIC;
    }

    public function getModel(): string
    {
        $model = trim((string) ($this->getFeatureSettings()['model'] ?? ''));

        return '' !== $model ? $model : self::DEFAULT_MODELS[$this->getProvider()];
    }

    public function getApiKey(): string
    {
        $configuration = $this->getConfiguration();

        if (null === $configuration) {
            return '';
        }

        // Dechiffree par IntegrationsHelper::getIntegrationConfiguration().
        return trim((string) ($configuration->getApiKeys()['api_key'] ?? ''));
    }

    /**
     * Le plugin doit etre active dans sa fiche ET disposer d'une cle API.
     */
    public function isConfigured(): bool
    {
        return $this->isPublished() && '' !== $this->getApiKey();
    }

    public function isPublished(): bool
    {
        return (bool) $this->getConfiguration()?->getIsPublished();
    }

    public function getMaxIterations(): int
    {
        $max = (int) ($this->getFeatureSettings()['max_iterations'] ?? 0);

        return max(1, min(20, 0 === $max ? self::DEFAULT_MAX_ITERATIONS : $max));
    }

    public function requiresConfirmation(): bool
    {
        // Par defaut on confirme : une integration jamais enregistree ne doit pas
        // se retrouver avec les ecritures automatiques activees.
        return (bool) ($this->getFeatureSettings()['require_confirmation'] ?? true);
    }

    /**
     * @return mixed[]
     */
    private function getFeatureSettings(): array
    {
        $configuration = $this->getConfiguration();

        if (null === $configuration) {
            return [];
        }

        $settings = $configuration->getFeatureSettings()['integration'] ?? [];

        return is_array($settings) ? $settings : [];
    }

    /**
     * Null tant que `mautic:plugins:reload` n'a pas cree la ligne en base.
     */
    private function getConfiguration(): ?Integration
    {
        try {
            return $this->integrationsHelper->getIntegration(WittyIntegration::NAME)->getIntegrationConfiguration();
        } catch (IntegrationNotFoundException) {
            return null;
        }
    }
}
