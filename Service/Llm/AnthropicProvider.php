<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class AnthropicProvider extends AbstractHttpProvider
{
    private const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION  = '2023-06-01';

    public function getKey(): string
    {
        return WittyConfig::PROVIDER_ANTHROPIC;
    }

    public function chat(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey): LlmResult
    {
        $payload = [
            'model'      => $model,
            'max_tokens' => 4096,
            'system'     => $systemPrompt,
            'messages'   => $this->formatMessages($messages),
        ];

        if ([] !== $tools) {
            $payload['tools'] = array_map(fn (array $tool): array => [
                'name'         => $tool['name'],
                'description'  => $tool['description'],
                'input_schema' => $this->sanitizeSchema($tool['schema']),
            ], $tools);
        }

        $data = $this->post(self::ENDPOINT, $payload, [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::VERSION,
        ]);

        $text      = '';
        $toolCalls = [];

        foreach ($data['content'] ?? [] as $block) {
            if ('text' === ($block['type'] ?? null)) {
                $text .= $block['text'];
            } elseif ('tool_use' === ($block['type'] ?? null)) {
                $toolCalls[] = new ToolCall(
                    (string) $block['id'],
                    (string) $block['name'],
                    (array) ($block['input'] ?? []),
                );
            }
        }

        return new LlmResult('' !== $text ? $text : null, $toolCalls, (array) ($data['usage'] ?? []));
    }

    /**
     * Anthropic attend les resultats d'outils dans des messages "user"
     * contenant des blocs tool_result, regroupes quand ils se suivent.
     *
     * @param Message[] $messages
     *
     * @return array<int, array<string, mixed>>
     */
    private function formatMessages(array $messages): array
    {
        $formatted     = [];
        $pendingResult = [];

        $flush = static function () use (&$pendingResult, &$formatted): void {
            if ([] !== $pendingResult) {
                $formatted[]   = ['role' => 'user', 'content' => $pendingResult];
                $pendingResult = [];
            }
        };

        foreach ($messages as $message) {
            if (Message::ROLE_TOOL === $message->role) {
                $pendingResult[] = [
                    'type'        => 'tool_result',
                    'tool_use_id' => $message->toolCallId,
                    'content'     => $message->content ?? '',
                ];
                continue;
            }

            $flush();

            if (Message::ROLE_USER === $message->role) {
                $formatted[] = ['role' => 'user', 'content' => $message->content ?? ''];
                continue;
            }

            $blocks = [];

            if (null !== $message->content && '' !== $message->content) {
                $blocks[] = ['type' => 'text', 'text' => $message->content];
            }

            foreach ($message->toolCalls as $call) {
                $blocks[] = [
                    'type'  => 'tool_use',
                    'id'    => $call->id,
                    'name'  => $call->name,
                    'input' => (object) $call->arguments,
                ];
            }

            if ([] !== $blocks) {
                $formatted[] = ['role' => 'assistant', 'content' => $blocks];
            }
        }

        $flush();

        return $formatted;
    }
}
