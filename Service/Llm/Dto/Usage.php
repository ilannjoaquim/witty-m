<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm\Dto;

/**
 * Consommation de tokens normalisee : les trois fournisseurs nomment ces
 * compteurs differemment.
 *
 * | fournisseur | entree              | sortie                  |
 * |-------------|---------------------|-------------------------|
 * | Anthropic   | input_tokens        | output_tokens           |
 * | OpenAI      | prompt_tokens       | completion_tokens       |
 * | Gemini      | promptTokenCount    | candidatesTokenCount    |
 */
final class Usage
{
    public function __construct(
        public readonly int $promptTokens = 0,
        public readonly int $completionTokens = 0,
    ) {
    }

    /**
     * @param array<string, mixed> $raw
     */
    public static function fromRaw(array $raw): self
    {
        return new self(
            self::pick($raw, ['input_tokens', 'prompt_tokens', 'promptTokenCount']),
            self::pick($raw, ['output_tokens', 'completion_tokens', 'candidatesTokenCount']),
        );
    }

    public function total(): int
    {
        return $this->promptTokens + $this->completionTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->promptTokens + $other->promptTokens,
            $this->completionTokens + $other->completionTokens,
        );
    }

    /**
     * @return array{prompt: int, completion: int, total: int}
     */
    public function toArray(): array
    {
        return ['prompt' => $this->promptTokens, 'completion' => $this->completionTokens, 'total' => $this->total()];
    }

    /**
     * @param array<string, mixed> $raw
     * @param array<int, string>   $keys
     */
    private static function pick(array $raw, array $keys): int
    {
        foreach ($keys as $key) {
            if (isset($raw[$key]) && is_numeric($raw[$key])) {
                return (int) $raw[$key];
            }
        }

        return 0;
    }
}
