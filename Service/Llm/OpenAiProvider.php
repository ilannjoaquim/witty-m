<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Usage;
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
        $data = $this->post(self::ENDPOINT, $this->payload($messages, $tools, $systemPrompt, $model), $this->headers($apiKey));

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
            Usage::fromRaw((array) ($data['usage'] ?? [])),
        );
    }

    /**
     * Chez OpenAI, un appel d'outil se reconstitue par index : le nom arrive
     * dans le premier fragment, les arguments par morceaux dans les suivants.
     * `stream_options.include_usage` est necessaire pour obtenir les compteurs,
     * sinon le flux ne renvoie aucun usage.
     */
    public function stream(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey, callable $onText): LlmResult
    {
        $payload                  = $this->payload($messages, $tools, $systemPrompt, $model);
        $payload['stream']        = true;
        $payload['stream_options'] = ['include_usage' => true];

        $text    = '';
        $pending = [];
        $usage   = [];

        $this->streamPost(self::ENDPOINT, $payload, $this->headers($apiKey), function (array $event) use (&$text, &$pending, &$usage, $onText): void {
            if (isset($event['usage']) && is_array($event['usage'])) {
                $usage = $event['usage'];
            }

            $delta = $event['choices'][0]['delta'] ?? null;

            if (!is_array($delta)) {
                return;
            }

            if (isset($delta['content']) && is_string($delta['content']) && '' !== $delta['content']) {
                $text .= $delta['content'];
                $onText($delta['content']);
            }

            foreach ($delta['tool_calls'] ?? [] as $call) {
                $index = (int) ($call['index'] ?? 0);

                $pending[$index] ??= ['id' => '', 'name' => '', 'arguments' => ''];

                if (isset($call['id'])) {
                    $pending[$index]['id'] = (string) $call['id'];
                }

                if (isset($call['function']['name'])) {
                    $pending[$index]['name'] = (string) $call['function']['name'];
                }

                if (isset($call['function']['arguments'])) {
                    $pending[$index]['arguments'] .= (string) $call['function']['arguments'];
                }
            }
        });

        ksort($pending);

        $toolCalls = [];

        foreach ($pending as $call) {
            $arguments = json_decode('' !== $call['arguments'] ? $call['arguments'] : '{}', true);

            $toolCalls[] = new ToolCall(
                '' !== $call['id'] ? $call['id'] : uniqid('call_', true),
                $call['name'],
                is_array($arguments) ? $arguments : [],
            );
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

        return $payload;
    }

    /**
     * @return array<string, string>
     */
    private function headers(string $apiKey): array
    {
        return ['Authorization' => 'Bearer '.$apiKey];
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
