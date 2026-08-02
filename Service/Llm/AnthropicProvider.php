<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Usage;
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
        $data = $this->post(self::ENDPOINT, $this->payload($messages, $tools, $systemPrompt, $model), $this->headers($apiKey));

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

        return new LlmResult('' !== $text ? $text : null, $toolCalls, Usage::fromRaw((array) ($data['usage'] ?? [])));
    }

    /**
     * Le flux Anthropic est une suite de blocs indexes : le texte arrive en
     * `text_delta`, les arguments d'outil en `input_json_delta` (JSON partiel a
     * concatener avant decodage).
     */
    public function stream(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey, callable $onText): LlmResult
    {
        $payload           = $this->payload($messages, $tools, $systemPrompt, $model);
        $payload['stream'] = true;

        $text   = '';
        $blocks = [];
        $usage  = ['input_tokens' => 0, 'output_tokens' => 0];

        $this->streamPost(self::ENDPOINT, $payload, $this->headers($apiKey), function (array $event) use (&$text, &$blocks, &$usage, $onText): void {
            $index = (int) ($event['index'] ?? 0);

            switch ($event['type'] ?? '') {
                case 'message_start':
                    $usage['input_tokens'] = (int) ($event['message']['usage']['input_tokens'] ?? 0);
                    break;

                case 'content_block_start':
                    $block = $event['content_block'] ?? [];

                    if ('tool_use' === ($block['type'] ?? '')) {
                        $blocks[$index] = [
                            'id'   => (string) ($block['id'] ?? ''),
                            'name' => (string) ($block['name'] ?? ''),
                            'json' => '',
                        ];
                    }
                    break;

                case 'content_block_delta':
                    $delta = $event['delta'] ?? [];

                    if ('text_delta' === ($delta['type'] ?? '')) {
                        $chunk = (string) ($delta['text'] ?? '');
                        $text .= $chunk;
                        $onText($chunk);
                    } elseif ('input_json_delta' === ($delta['type'] ?? '') && isset($blocks[$index])) {
                        $blocks[$index]['json'] .= (string) ($delta['partial_json'] ?? '');
                    }
                    break;

                case 'message_delta':
                    $usage['output_tokens'] = (int) ($event['usage']['output_tokens'] ?? $usage['output_tokens']);
                    break;
            }
        });

        $toolCalls = [];

        foreach ($blocks as $block) {
            $arguments = json_decode('' !== $block['json'] ? $block['json'] : '{}', true);

            $toolCalls[] = new ToolCall($block['id'], $block['name'], is_array($arguments) ? $arguments : []);
        }

        return new LlmResult('' !== $text ? $text : null, $toolCalls, Usage::fromRaw($usage));
    }

    /**
     * @param Message[]                                                                  $messages
     * @param array<int, array{name: string, description: string, schema: array<mixed>}> $tools
     *
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $tools, string $systemPrompt, string $model): array
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

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        return [
            'x-api-key'         => $apiKey,
            'anthropic-version' => self::VERSION,
        ];
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
