<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\AnthropicProvider;
use MauticPlugin\WittyBundle\Service\Llm\Dto\LlmResult;
use MauticPlugin\WittyBundle\Service\Llm\Dto\Message;
use MauticPlugin\WittyBundle\Service\Llm\GeminiProvider;
use MauticPlugin\WittyBundle\Service\Llm\LlmProviderInterface;
use MauticPlugin\WittyBundle\Service\Llm\OpenAiProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Le decodage SSE est le point le plus fragile de la couche LLM : trois formats
 * incompatibles, des trames coupees a n'importe quel endroit par le transport,
 * et des arguments d'outils livres en JSON partiel.
 *
 * Les flux rejoues ici sont volontairement decoupes de facon hostile : trame
 * scindee entre deux chunks HTTP, deux trames dans le meme chunk, JSON d'outil
 * arrivant en morceaux.
 */
class StreamParsingTest extends TestCase
{
    public function testAnthropicStreamRebuildsTextToolCallsAndUsage(): void
    {
        $payload = static fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR);

        $textDelta = $payload([
            'type'  => 'content_block_delta',
            'index' => 0,
            'delta' => ['type' => 'text_delta', 'text' => 'jour !'],
        ]);

        $chunks = [
            "event: message_start\ndata: ".$payload(['type' => 'message_start', 'message' => ['usage' => ['input_tokens' => 120]]])."\n\n",
            "event: content_block_start\ndata: ".$payload(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text']])."\n\n",
            "event: content_block_delta\ndata: ".$payload(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Bon']])."\n\n",
            // Une trame coupee en deux morceaux HTTP.
            "event: content_block_delta\ndata: ".substr($textDelta, 0, 40),
            substr($textDelta, 40)."\n\n",
            "event: content_block_start\ndata: ".$payload(['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'list_entities']])."\n\n",
            // Arguments livres en JSON partiel.
            "event: content_block_delta\ndata: ".$payload(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"entity":']])."\n\n",
            "event: content_block_delta\ndata: ".$payload(['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"segments"}']])."\n\n",
            "event: message_delta\ndata: ".$payload(['type' => 'message_delta', 'usage' => ['output_tokens' => 34]])."\n\n",
        ];

        [$deltas, $result] = $this->stream(new AnthropicProvider($this->client($chunks)));

        $this->assertSame(['Bon', 'jour !'], $deltas);
        $this->assertSame('Bonjour !', $result->text);
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('toolu_1', $result->toolCalls[0]->id);
        $this->assertSame(['entity' => 'segments'], $result->toolCalls[0]->arguments);
        $this->assertSame(120, $result->usage->promptTokens);
        $this->assertSame(34, $result->usage->completionTokens);
    }

    public function testOpenAiStreamRebuildsToolCallsSplitAcrossFrames(): void
    {
        $frame = static fn (array $delta): string => 'data: '.json_encode(['choices' => [['delta' => $delta]]], JSON_THROW_ON_ERROR)."\n\n";

        $chunks = [
            $frame(['content' => 'Voici ']),
            // Deux trames dans le meme chunk HTTP.
            $frame(['content' => 'la liste'])
                .$frame(['tool_calls' => [['index' => 0, 'id' => 'call_9', 'function' => ['name' => 'list_entities', 'arguments' => '{"ent']]]]),
            $frame(['tool_calls' => [['index' => 0, 'function' => ['arguments' => 'ity":"emails"}']]]]),
            'data: '.json_encode(['choices' => [], 'usage' => ['prompt_tokens' => 88, 'completion_tokens' => 12]], JSON_THROW_ON_ERROR)."\n\n",
            "data: [DONE]\n\n",
        ];

        [$deltas, $result] = $this->stream(new OpenAiProvider($this->client($chunks)));

        $this->assertSame(['Voici ', 'la liste'], $deltas);
        $this->assertSame('Voici la liste', $result->text);
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame('call_9', $result->toolCalls[0]->id);
        $this->assertSame(['entity' => 'emails'], $result->toolCalls[0]->arguments);
        $this->assertSame(88, $result->usage->promptTokens);
        $this->assertSame(12, $result->usage->completionTokens);
    }

    public function testGeminiStreamRebuildsTextAndFunctionCall(): void
    {
        $chunks = [
            'data: '.json_encode(['candidates' => [['content' => ['parts' => [['text' => 'Salut']]]]]], JSON_THROW_ON_ERROR)."\n\n",
            'data: '.json_encode([
                'candidates' => [[
                    'content' => ['parts' => [
                        ['text' => ' toi'],
                        ['functionCall' => ['name' => 'list_entities', 'args' => ['entity' => 'campaigns']]],
                    ]],
                ]],
            ], JSON_THROW_ON_ERROR)."\n\n",
            'data: '.json_encode(['usageMetadata' => ['promptTokenCount' => 55, 'candidatesTokenCount' => 9]], JSON_THROW_ON_ERROR)."\n\n",
        ];

        [$deltas, $result] = $this->stream(new GeminiProvider($this->client($chunks)));

        $this->assertSame(['Salut', ' toi'], $deltas);
        $this->assertSame('Salut toi', $result->text);
        $this->assertCount(1, $result->toolCalls);
        $this->assertSame(['entity' => 'campaigns'], $result->toolCalls[0]->arguments);
        $this->assertSame(55, $result->usage->promptTokens);
        $this->assertSame(9, $result->usage->completionTokens);
    }

    /**
     * @param array<int, string> $chunks
     */
    private function client(array $chunks): MockHttpClient
    {
        return new MockHttpClient(
            new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']])
        );
    }

    /**
     * @return array{0: array<int, string>, 1: LlmResult}
     */
    private function stream(LlmProviderInterface $provider): array
    {
        $deltas = [];

        $result = $provider->stream(
            [Message::user('bonjour')],
            [['name' => 'list_entities', 'description' => 'liste', 'schema' => ['type' => 'object', 'properties' => []]]],
            'prompt systeme',
            'modele-test',
            'cle-test',
            static function (string $chunk) use (&$deltas): void {
                $deltas[] = $chunk;
            },
        );

        return [$deltas, $result];
    }
}
