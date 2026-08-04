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
 * les cles API vivent dans la colonne api_keys, chiffrees par Mautic, le reste
 * dans feature_settings. Rien n'est ecrit dans local.php.
 *
 * Le plugin n'impose plus un fournisseur unique : chaque cle renseignee rend
 * son fournisseur disponible, et c'est le front (fil de discussion) qui choisit
 * lequel utiliser a chaque tour, parmi ceux effectivement configures. La
 * configuration ne fixe donc plus qu'un ordre de preference par defaut.
 */
class WittyConfig
{
    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';
    public const PROVIDER_DEEPSEEK  = 'deepseek';

    public const DEFAULT_MAX_ITERATIONS = 8;

    /**
     * ATTENTION : les identifiants de modeles evoluent vite chez les trois
     * fournisseurs. Ce sont uniquement des valeurs de repli ; le modele exact
     * se renseigne dans la fiche du plugin (facultatif) ou depuis le chat.
     */
    private const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC => 'claude-sonnet-5',
        self::PROVIDER_OPENAI    => 'gpt-4o',
        self::PROVIDER_GEMINI    => 'gemini-2.5-flash',
        self::PROVIDER_DEEPSEEK  => 'deepseek-chat',
    ];

    private const PROVIDER_LABELS = [
        self::PROVIDER_ANTHROPIC => 'Anthropic (Claude)',
        self::PROVIDER_OPENAI    => 'OpenAI (GPT)',
        self::PROVIDER_GEMINI    => 'Google (Gemini)',
        self::PROVIDER_DEEPSEEK  => 'DeepSeek',
    ];

    /** Nom du champ de cle API par fournisseur, dans Integration::getApiKeys(). */
    private const API_KEY_FIELDS = [
        self::PROVIDER_ANTHROPIC => 'anthropic_api_key',
        self::PROVIDER_OPENAI    => 'openai_api_key',
        self::PROVIDER_GEMINI    => 'gemini_api_key',
        self::PROVIDER_DEEPSEEK  => 'deepseek_api_key',
    ];

    public function __construct(
        private IntegrationsHelper $integrationsHelper,
    ) {
    }

    /**
     * Fournisseurs pour lesquels une cle API non vide est renseignee, dans
     * l'ordre de preference par defaut (Anthropic, OpenAI, Gemini).
     *
     * @return array<int, string>
     */
    public function getConfiguredProviders(): array
    {
        return array_values(array_filter(
            array_keys(self::DEFAULT_MODELS),
            fn (string $provider): bool => '' !== $this->getApiKey($provider),
        ));
    }

    public function isProviderConfigured(string $provider): bool
    {
        return in_array($provider, $this->getConfiguredProviders(), true);
    }

    /**
     * Premier fournisseur configure, utilise quand le chat n'en precise aucun
     * (nouvelle conversation, ou fournisseur demande devenu indisponible).
     */
    public function getDefaultProvider(): string
    {
        return $this->getConfiguredProviders()[0] ?? self::PROVIDER_ANTHROPIC;
    }

    public function getProviderLabel(string $provider): string
    {
        return self::PROVIDER_LABELS[$provider] ?? ucfirst($provider);
    }

    /**
     * L'URL du serveur plugNmeet vit dans feature_settings (pas secrete),
     * la cle et le secret dans api_keys (chiffres par Mautic).
     */
    public function getPlugNmeetServerUrl(): string
    {
        return rtrim(trim((string) ($this->getFeatureSettings()['plugnmeet_server_url'] ?? '')), '/');
    }

    public function getPlugNmeetApiKey(): string
    {
        $configuration = $this->getConfiguration();

        return null === $configuration ? '' : trim((string) ($configuration->getApiKeys()['plugnmeet_api_key'] ?? ''));
    }

    public function getPlugNmeetApiSecret(): string
    {
        $configuration = $this->getConfiguration();

        return null === $configuration ? '' : trim((string) ($configuration->getApiKeys()['plugnmeet_api_secret'] ?? ''));
    }

    public function isPlugNmeetConfigured(): bool
    {
        return $this->isPublished()
            && '' !== $this->getPlugNmeetServerUrl()
            && '' !== $this->getPlugNmeetApiKey()
            && '' !== $this->getPlugNmeetApiSecret();
    }

    public function getApiKey(string $provider): string
    {
        $configuration = $this->getConfiguration();
        $field         = self::API_KEY_FIELDS[$provider] ?? null;

        if (null === $configuration || null === $field) {
            return '';
        }

        // Dechiffree par IntegrationsHelper::getIntegrationConfiguration().
        return trim((string) ($configuration->getApiKeys()[$field] ?? ''));
    }

    /**
     * Modele pour un fournisseur donne : celui renseigne en configuration, ou
     * a defaut la valeur de repli du fournisseur.
     */
    public function getModel(string $provider): string
    {
        $model = trim((string) ($this->getFeatureSettings()[$provider.'_model'] ?? ''));

        return '' !== $model ? $model : (self::DEFAULT_MODELS[$provider] ?? self::DEFAULT_MODELS[self::PROVIDER_ANTHROPIC]);
    }

    /**
     * Le plugin doit etre actif dans sa fiche ET disposer d'au moins une cle API.
     */
    public function isConfigured(): bool
    {
        return $this->isPublished() && [] !== $this->getConfiguredProviders();
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

    /**
     * Plafond de tokens par utilisateur et par jour. 0 = pas de plafond.
     */
    public function getDailyTokenQuota(): int
    {
        return max(0, (int) ($this->getFeatureSettings()['daily_token_quota'] ?? 0));
    }

    /**
     * Le streaming se desactive si l'hebergement bufferise les reponses
     * (certains proxies et configurations PHP-FPM), auquel cas la reponse
     * arriverait d'un bloc a la fin, en plus lent.
     */
    public function isStreamingEnabled(): bool
    {
        return (bool) ($this->getFeatureSettings()['streaming'] ?? true);
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
