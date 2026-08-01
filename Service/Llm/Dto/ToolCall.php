<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm\Dto;

/**
 * Representation normalisee d'un appel d'outil, quel que soit le fournisseur.
 */
final class ToolCall
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'arguments' => $this->arguments];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? uniqid('call_', true)),
            (string) ($data['name'] ?? ''),
            (array) ($data['arguments'] ?? []),
        );
    }
}
