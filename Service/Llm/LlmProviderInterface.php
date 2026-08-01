<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;

interface LlmProviderInterface
{
    /**
     * Identifiant du provider, tel que stocke en configuration.
     */
    public function getKey(): string;

    /**
     * @param Message[]                                                                   $messages
     * @param array<int, array{name: string, description: string, schema: array<mixed>}>  $tools
     */
    public function chat(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey): LlmResult;
}
