<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm\Dto;

final class LlmResult
{
    /**
     * @param ToolCall[]           $toolCalls
     * @param array<string, mixed> $usage
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
        public readonly array $usage = [],
    ) {
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->toolCalls;
    }
}
