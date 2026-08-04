<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\Dto\ToolCall;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Usage;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class GeminiProvider extends AbstractHttpProvider
{
    private const ENDPOINT        = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    private const ENDPOINT_STREAM = 'https://generativelanguage.googleapis.com/v1beta/models/%s:streamGenerateContent?alt=sse';
    private const MODELS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/models';

    public function getKey(): string
    {
        return WittyConfig::PROVIDER_GEMINI;
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    public function listModels(string $apiKey): array
    {
        $models    = [];
        $pageToken = null;

        // Le catalogue Gemini inclut aussi les modeles d'embedding/image : on ne
        // garde que ceux qui supportent generateContent, seul appel utilise ici.
        for ($page = 0; $page < 10; ++$page) {
            $query = ['pageSize' => 100];

            if (null !== $pageToken) {
                $query['pageToken'] = $pageToken;
            }

            $data = $this->get(self::MODELS_ENDPOINT, ['x-goog-api-key' => $apiKey], $query);

            foreach ((array) ($data['models'] ?? []) as $model) {
                $methods = (array) ($model['supportedGenerationMethods'] ?? []);

                if (!in_array('generateContent', $methods, true)) {
                    continue;
                }

                $name = (string) ($model['name'] ?? '');
                $id   = str_starts_with($name, 'models/') ? substr($name, 7) : $name;

                if ('' === $id) {
                    continue;
                }

                $models[] = [
                    'id'    => $id,
                    'label' => (string) ($model['displayName'] ?? $id),
                ];
            }

            $pageToken = $data['nextPageToken'] ?? null;

            if (null === $pageToken || '' === $pageToken) {
                break;
            }
        }

        return $models;
    }

    public function chat(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey): LlmResult
    {
        $data = $this->post(
            sprintf(self::ENDPOINT, $model),
            $this->payload($messages, $tools, $systemPrompt),
            ['x-goog-api-key' => $apiKey],
        );

        $text      = '';
        $toolCalls = [];
        $index     = 0;

        foreach ($data['candidates'][0]['content']['parts'] ?? [] as $part) {
            if (isset($part['text'])) {
                $text .= $part['text'];
            }

            if (isset($part['functionCall'])) {
                $toolCalls[] = $this->toolCallFromPart($part['functionCall'], $index++);
            }
        }

        return new LlmResult(
            '' !== $text ? $text : null,
            $toolCalls,
            Usage::fromRaw((array) ($data['usageMetadata'] ?? [])),
        );
    }

    /**
     * Gemini renvoie une suite de reponses completes : chaque evenement contient
     * des `parts` a concatener, et le dernier porte l'usage cumule.
     */
    public function stream(array $messages, array $tools, string $systemPrompt, string $model, string $apiKey, callable $onText): LlmResult
    {
        $text      = '';
        $toolCalls = [];
        $index     = 0;
        $usage     = [];

        $this->streamPost(
            sprintf(self::ENDPOINT_STREAM, $model),
            $this->payload($messages, $tools, $systemPrompt),
            ['x-goog-api-key' => $apiKey],
            function (array $event) use (&$text, &$toolCalls, &$index, &$usage, $onText): void {
                if (isset($event['usageMetadata']) && is_array($event['usageMetadata'])) {
                    $usage = $event['usageMetadata'];
                }

                foreach ($event['candidates'][0]['content']['parts'] ?? [] as $part) {
                    if (isset($part['text']) && '' !== $part['text']) {
                        $text .= $part['text'];
                        $onText((string) $part['text']);
                    }

                    if (isset($part['functionCall'])) {
                        $toolCalls[] = $this->toolCallFromPart($part['functionCall'], $index++);
                    }
                }
            },
        );

        return new LlmResult('' !== $text ? $text : null, $toolCalls, Usage::fromRaw($usage));
    }

    /**
     * @param array<string, mixed> $functionCall
     */
    private function toolCallFromPart(array $functionCall, int $index): ToolCall
    {
        // Gemini ne fournit pas d'identifiant d'appel : on en genere un
        // deterministe pour pouvoir apparier la reponse.
        return new ToolCall(
            sprintf('gemini_%d_%s', $index, (string) ($functionCall['name'] ?? '')),
            (string) ($functionCall['name'] ?? ''),
            (array) ($functionCall['args'] ?? []),
        );
    }

    /**
     * @param Message[]                                                                  $messages
     * @param array<int, array{name: string, description: string, schema: array<mixed>}> $tools
     *
     * @return array<string, mixed>
     */
    private function payload(array $messages, array $tools, string $systemPrompt): array
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

        return $payload;
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
