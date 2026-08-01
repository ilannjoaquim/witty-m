<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm\Dto;

/**
 * Message de transcript interne. Chaque provider traduit ce format vers le sien.
 *
 * roles : user | assistant | tool
 */
final class Message
{
    public const ROLE_USER      = 'user';
    public const ROLE_ASSISTANT = 'assistant';
    public const ROLE_TOOL      = 'tool';

    /**
     * @param ToolCall[] $toolCalls
     */
    public function __construct(
        public readonly string $role,
        public readonly ?string $content = null,
        public readonly array $toolCalls = [],
        public readonly ?string $toolCallId = null,
        public readonly ?string $toolName = null,
    ) {
    }

    public static function user(string $content): self
    {
        return new self(self::ROLE_USER, $content);
    }

    /**
     * @param ToolCall[] $toolCalls
     */
    public static function assistant(?string $content, array $toolCalls = []): self
    {
        return new self(self::ROLE_ASSISTANT, $content, $toolCalls);
    }

    /**
     * @param array<string, mixed> $result
     */
    public static function toolResult(ToolCall $call, array $result): self
    {
        return new self(
            self::ROLE_TOOL,
            json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            [],
            $call->id,
            $call->name,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'role'       => $this->role,
            'content'    => $this->content,
            'toolCalls'  => array_map(static fn (ToolCall $c): array => $c->toArray(), $this->toolCalls),
            'toolCallId' => $this->toolCallId,
            'toolName'   => $this->toolName,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['role'] ?? self::ROLE_USER),
            isset($data['content']) ? (string) $data['content'] : null,
            array_map(
                static fn (array $c): ToolCall => ToolCall::fromArray($c),
                (array) ($data['toolCalls'] ?? []),
            ),
            isset($data['toolCallId']) ? (string) $data['toolCallId'] : null,
            isset($data['toolName']) ? (string) $data['toolName'] : null,
        );
    }
}
