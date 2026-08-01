<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class GeminiProvider extends AbstractHttpProvider
{
    private const ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';

    public function getKey(): string
    {
        return WittyConfig::PROVIDER_GEMINI;
    }

    public function chat(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey): LlmResult
    {
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents'          => $this->formatMessages($messages),
        ];

        if ([] !== $tools) {
            $payload['tools'] = [[
                'functionDeclarations' => array_map(fn (array $tool): array => [
                    'name'        => $tool['name'],
                    'description' => $tool['description'],
                    // Gemini rejette additionalProperties et les mots-cles JSON Schema exotiques.
                    'parameters'  => $this->sanitizeSchema($tool['schema'], true),
                ], $tools),
            ]];
        }

        $data = $this->post(sprintf(self::ENDPOINT, $model), $payload, [
            'x-goog-api-key' => $apiKey,
        ]);

        $text      = '';
        $toolCalls = [];
        $index     = 0;

        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                // Gemini ne fournit pas d'identifiant d'appel : on en genere un
                // deterministe pour pouvoir apparier la reponse.
                $toolCalls[] = new ToolCall(
                    sprintf('gemini_%d_%s', $index++, $part['functionCall']['name']),
                    (string) $part['functionCall']['name'],
                    (array) ($part['functionCall']['args'] ?? []),
                );
            }
        }

        return new LlmResult(
            '' !== $text ? $text : null,
            $toolCalls,
            (array) ($data['usageMetadata'] ?? []),
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
                $decoded = json_decode($message->content ?? '{}', true);

                $formatted[] = [
                    'role'  => 'user',
                    'parts' => [[
                        'functionResponse' => [
                            'name'     => $message->toolName,
                            'response' => is_array($decoded) ? $decoded : ['result' => $message->content],
                        ],
                    ]],
                ];
                continue;
            }

            if (Message::ROLE_USER === $message->role) {
                $formatted[] = ['role' => 'user', 'parts' => [['text' => $message->content ?? '']]];
                continue;
            }

            $parts = [];

            if (null !== $message->content && '' !== $message->content) {
                $parts[] = ['text' => $message->content];
            }

            foreach ($message->toolCalls as $call) {
                $parts[] = ['functionCall' => ['name' => $call->name, 'args' => (object) $call->arguments]];
            }

            if ([] !== $parts) {
                $formatted[] = ['role' => 'model', 'parts' => $parts];
            }
        }

        return $formatted;
    }
}
