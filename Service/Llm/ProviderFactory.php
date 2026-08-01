<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class ProviderFactory
{
    /** @var array<string, LlmProviderInterface> */
    private array $providers = [];

    public function __construct(
        AnthropicProvider $anthropic,
        OpenAiProvider $openAi,
        GeminiProvider $gemini,
        private WittyConfig $config,
    ) {
        foreach ([$anthropic, $openAi, $gemini] as $provider) {
            $this->providers[$provider->getKey()] = $provider;
        }
    }

    public function get(?string $key = null): LlmProviderInterface
    {
        $key ??= $this->config->getProvider();

        if (!isset($this->providers[$key])) {
            throw new LlmException(sprintf('Fournisseur IA inconnu : %s', $key));
        }

        return $this->providers[$key];
    }
}
