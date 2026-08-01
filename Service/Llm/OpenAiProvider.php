<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class OpenAiProvider extends AbstractHttpProvider
{
    private const ENDPOINT = 'https://api.openai.com/v1/chat/completions';

    public function getKey(): string
    {
        return WittyConfig::PROVIDER_OPENAI;
    }

    public function chat(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey): LlmResult
    {
        $payload = [
            'model'    => $model,
            'messages' => array_merge(
                [['role' => 'system', 'content' => $systemPrompt]],
                $this->formatMessages($messages),
            ),
        ];

        if ([] !== $tools) {
            $payload['tools'] = array_map(fn (array $tool): array => [
                'type'     => 'function',
                'function' => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'],
                    'parameters'  => $this->sanitizeSchema($tool['schema']),
                ],
            ], $tools);
        }

        $data = $this->post(self::ENDPOINT, $payload, [
            'Authorization' => 'Bearer '.$apiKey,
        ]);

        $choice    = $data['choices'][0]['message'] ?? [];
        $toolCalls = [];

        foreach ($choice['tool_calls'] ?? [] as $call) {
            $arguments = json_decode((string) ($call['function']['arguments'] ?? '{}'), true);

            $toolCalls[] = new ToolCall(
                (string) $call['id'],
                (string) $call['function']['name'],
                is_array($arguments) ? $arguments : [],
            );
        }

        $text = $choice['content'] ?? null;

        return new LlmResult(
            is_string($text) && '' !== $text ? $text : null,
            $toolCalls,
            (array) ($data['usage'] ?? []),
        );
    }

    /**
     * @param Message[] $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted = [];

        foreach ($messages as $message) {
            if (Message::ROLE_TOOL === $message->role) {
                $formatted[] = [
                    'role'         => 'tool',
                    'tool_call_id' => $message->toolCallId,
                    'content'      => $message->content ?? '',
                ];
                continue;
            }

            if (Message::ROLE_USER === $message->role) {
                $formatted[] = ['role' => 'user', 'content' => $message->content ?? ''];
                continue;
            }

            $entry = ['role' => 'assistant', 'content' => $message->content];

            if ([] !== $message->toolCalls) {
                $entry['tool_calls'] = array_map(static fn (ToolCall $call): array => [
                    'id'       => $call->id,
                    'type'     => 'function',
                    'function' => [
                        'name'      => $call->name,
                        'arguments' => json_encode($call->arguments, JSON_UNESCAPED_UNICODE) ?: '{}',
                    ],
                ], $message->toolCalls);
            }

            $formatted[] = $entry;
        }

        return $formatted;
    }
}
