<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Llm;

use MauticPlugin\WittyBundle\Service\Llm\AnthropicProvider;
use MauticPlugin\WittyBundle\Service\Llm\DeepSeekProvider;
use MauticPlugin\WittyBundle\Service\Llm\GeminiProvider;
use MauticPlugin\WittyBundle\Service\Llm\OpenAiProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Chaque fournisseur pagine et filtre son catalogue differemment : Anthropic
 * par after_id/has_more, Gemini par pageToken en ne gardant que les modeles
 * supportant generateContent, OpenAI sans pagination mais avec un catalogue
 * bruite (audio, embeddings, moderation...) qu'il faut filtrer cote client.
 */
class ModelListingTest extends TestCase
{
    public function testAnthropicListModelsPaginatesUntilHasMoreIsFalse(): void
    {
        $page1 = json_encode([
            'data' => [
                ['id' => 'claude-opus-5', 'display_name' => 'Claude Opus 5'],
                ['id' => 'claude-sonnet-5', 'display_name' => 'Claude Sonnet 5'],
            ],
            'has_more' => true,
            'last_id'  => 'claude-sonnet-5',
        ], JSON_THROW_ON_ERROR);

        $page2 = json_encode([
            'data' => [
                ['id' => 'claude-haiku-4-5', 'display_name' => 'Claude Haiku 4.5'],
            ],
            'has_more' => false,
        ], JSON_THROW_ON_ERROR);

        $provider = new AnthropicProvider(new MockHttpClient([
            new MockResponse($page1),
            new MockResponse($page2),
        ]));

        $this->assertSame([
            ['id' => 'claude-opus-5', 'label' => 'Claude Opus 5'],
            ['id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5'],
            ['id' => 'claude-haiku-4-5', 'label' => 'Claude Haiku 4.5'],
        ], $provider->listModels('cle-test'));
    }

    public function testOpenAiListModelsExcludesNonChatFamiliesAndSorts(): void
    {
        $body = json_encode([
            'data' => [
                ['id' => 'gpt-4o'],
                ['id' => 'text-embedding-3-large'],
                ['id' => 'whisper-1'],
                ['id' => 'gpt-4o-mini'],
                ['id' => 'dall-e-3'],
                ['id' => 'omni-moderation-latest'],
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = new OpenAiProvider(new MockHttpClient(new MockResponse($body)));

        $this->assertSame([
            ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
            ['id' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini'],
        ], $provider->listModels('cle-test'));
    }

    /**
     * DeepSeek expose une API "chat/completions" compatible OpenAI :
     * DeepSeekProvider herite de OpenAiProvider et n'en redefinit que les
     * endpoints (cf. Service/Llm/DeepSeekProvider.php). Ce test verifie que
     * l'appel HTTP part bien vers l'endpoint DeepSeek et non celui d'OpenAI.
     */
    public function testDeepSeekListModelsCallsDeepSeekEndpoint(): void
    {
        $body = json_encode([
            'object' => 'list',
            'data'   => [
                ['id' => 'deepseek-chat', 'object' => 'model', 'owned_by' => 'deepseek'],
                ['id' => 'deepseek-reasoner', 'object' => 'model', 'owned_by' => 'deepseek'],
            ],
        ], JSON_THROW_ON_ERROR);

        $capturedUrl = null;
        $httpClient  = new MockHttpClient(function (string $method, string $url) use (&$capturedUrl, $body): MockResponse {
            $capturedUrl = $url;

            return new MockResponse($body);
        });

        $provider = new DeepSeekProvider($httpClient);

        $this->assertSame([
            ['id' => 'deepseek-chat', 'label' => 'deepseek-chat'],
            ['id' => 'deepseek-reasoner', 'label' => 'deepseek-reasoner'],
        ], $provider->listModels('cle-test'));

        $this->assertSame('https://api.deepseek.com/models', $capturedUrl);
    }

    public function testGeminiListModelsFiltersByGenerateContentAndStripsPrefix(): void
    {
        $page1 = json_encode([
            'models' => [
                ['name' => 'models/gemini-2.5-flash', 'displayName' => 'Gemini 2.5 Flash', 'supportedGenerationMethods' => ['generateContent']],
                ['name' => 'models/embedding-001', 'displayName' => 'Embedding', 'supportedGenerationMethods' => ['embedContent']],
            ],
            'nextPageToken' => 'page2',
        ], JSON_THROW_ON_ERROR);

        $page2 = json_encode([
            'models' => [
                ['name' => 'models/gemini-2.5-pro', 'displayName' => 'Gemini 2.5 Pro', 'supportedGenerationMethods' => ['generateContent']],
            ],
        ], JSON_THROW_ON_ERROR);

        $provider = new GeminiProvider(new MockHttpClient([
            new MockResponse($page1),
            new MockResponse($page2),
        ]));

        $this->assertSame([
            ['id' => 'gemini-2.5-flash', 'label' => 'Gemini 2.5 Flash'],
            ['id' => 'gemini-2.5-pro', 'label' => 'Gemini 2.5 Pro'],
        ], $provider->listModels('cle-test'));
    }
}
