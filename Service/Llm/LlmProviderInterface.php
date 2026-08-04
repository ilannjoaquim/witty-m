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

    /**
     * Meme appel, en SSE : $onText recoit les fragments de texte au fil de l'eau.
     * Le resultat complet (texte reconstitue, appels d'outils, usage) est tout de
     * meme renvoye a la fin, pour que la boucle de l'agent n'ait pas a distinguer
     * les deux modes.
     *
     * @param Message[]                                                                  $messages
     * @param array<int, array{name: string, description: string, schema: array<mixed>}> $tools
     * @param callable(string): void                                                     $onText
     */
    public function stream(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey, callable $onText): LlmResult;

    /**
     * Modeles de chat disponibles pour cette cle API, tels que renvoyes par le
     * fournisseur (pas de liste codee en dur : les catalogues evoluent trop
     * vite pour ca).
     *
     * @return array<int, array{id: string, label: string}>
     *
     * @throws \MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException
     */
    public function listModels(string $apiKey): array;
}
