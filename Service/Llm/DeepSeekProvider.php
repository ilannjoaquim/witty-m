<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * DeepSeek expose une API "chat/completions" volontairement compatible avec
 * celle d'OpenAI (memes formats de requete/reponse, function calling inclus) :
 * on herite donc de OpenAiProvider plutot que de dupliquer ~150 lignes de
 * logique identique (payload, reconstruction des tool_calls en streaming,
 * formatage des messages...). Seuls les points de variation reels sont
 * redefinis ici : endpoints, cle du fournisseur.
 *
 * Catalogue de modeles reduit (deepseek-chat, deepseek-reasoner...) donc pas
 * besoin de filtrage par famille : listModels() herite tel quel de
 * OpenAiProvider (son filtre EXCLUDED_SUBSTRINGS ne matche aucun id DeepSeek).
 */
class DeepSeekProvider extends OpenAiProvider
{
    protected const ENDPOINT        = 'https://api.deepseek.com/chat/completions';
    protected const MODELS_ENDPOINT = 'https://api.deepseek.com/models';

    public function getKey(): string
    {
        return WittyConfig::PROVIDER_DEEPSEEK;
    }
}
