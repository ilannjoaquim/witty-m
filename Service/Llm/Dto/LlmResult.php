<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm\Dto;

final class LlmResult
{
    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls = [],
        public readonly Usage $usage = new Usage(),
    ) {
    }

    public function hasToolCalls(): bool
    {
        return [] !== $this->toolCalls;
    }
}
