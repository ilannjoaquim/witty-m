<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\EncryptionHelper;

/**
 * Acces centralise a la configuration du plugin.
 */
class WittyConfig
{
    /** Marqueur permettant de savoir si la valeur stockee est deja chiffree. */
    public const ENC_PREFIX = 'witty_enc::';

    public const PROVIDER_ANTHROPIC = 'anthropic';
    public const PROVIDER_OPENAI    = 'openai';
    public const PROVIDER_GEMINI    = 'gemini';

    /**
     * ATTENTION : les identifiants de modeles evoluent vite chez les trois
     * fournisseurs. Ce sont uniquement des valeurs de repli ; le modele exact
     * se renseigne dans Parametres > Configuration > Witty.
     */
    private const DEFAULT_MODELS = [
        self::PROVIDER_ANTHROPIC => 'claude-sonnet-5',
        self::PROVIDER_OPENAI    => 'gpt-4o',
        self::PROVIDER_GEMINI    => 'gemini-2.5-flash',
    ];

    public function __construct(
        private CoreParametersHelper $parameters,
        private EncryptionHelper $encryptionHelper,
    ) {
    }

    public function getProvider(): string
    {
        $provider = (string) $this->parameters->get('witty_provider');

        return array_key_exists($provider, self::DEFAULT_MODELS) ? $provider : self::PROVIDER_ANTHROPIC;
    }

    public function getModel(): string
    {
        $model = trim((string) $this->parameters->get('witty_model'));

        return '' !== $model ? $model : self::DEFAULT_MODELS[$this->getProvider()];
    }

    public function getApiKey(): string
    {
        $stored = (string) $this->parameters->get('witty_api_key');

        if ('' === $stored) {
            return '';
        }

        if (str_starts_with($stored, self::ENC_PREFIX)) {
            $decrypted = $this->encryptionHelper->decrypt(substr($stored, strlen(self::ENC_PREFIX)));

            return is_string($decrypted) ? $decrypted : '';
        }

        // Cle saisie avant la mise en place du chiffrement : on la renvoie telle quelle.
        return $stored;
    }

    public function isConfigured(): bool
    {
        return '' !== $this->getApiKey();
    }

    public function getMaxIterations(): int
    {
        $max = (int) $this->parameters->get('witty_max_iterations');

        return max(1, min(20, 0 === $max ? 8 : $max));
    }

    public function requiresConfirmation(): bool
    {
        return (bool) $this->parameters->get('witty_require_confirmation');
    }
}
