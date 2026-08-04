<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Llm;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\WittyBundle\Service\Llm\Exception\LlmException;
use MauticPlugin\WittyBundle\Service\Llm\LlmProviderInterface;
use MauticPlugin\WittyBundle\Service\Llm\ModelCatalog;
use MauticPlugin\WittyBundle\Service\Llm\ProviderFactory;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Le dropdown du chat ne doit jamais se retrouver vide : sans cle API on
 * propose quand meme le modele par defaut, un echec fournisseur degrade vers
 * ce meme repli, et un succes est mis en cache pour eviter un appel reseau a
 * chaque ouverture du chat.
 */
class ModelCatalogTest extends TestCase
{
    private string $cacheDir;

    protected function setUp(): void
    {
        $this->cacheDir = sys_get_temp_dir().'/witty-model-catalog-test-'.uniqid();
    }

    protected function tearDown(): void
    {
        if (is_dir($this->cacheDir)) {
            (new \Symfony\Component\Filesystem\Filesystem())->remove($this->cacheDir);
        }
    }

    public function testNoApiKeyReturnsOnlyTheDefaultModel(): void
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('getApiKey')->willReturn('');
        $config->method('getModel')->willReturn('gpt-4o');

        $factory = $this->createMock(ProviderFactory::class);
        $factory->expects($this->never())->method('get');

        $catalog = $this->catalog($factory, $config);

        $this->assertSame([['id' => 'gpt-4o', 'label' => 'gpt-4o']], $catalog->getModels(WittyConfig::PROVIDER_OPENAI));
    }

    public function testProviderFailureFallsBackToDefaultModel(): void
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('getApiKey')->willReturn('sk-test');
        $config->method('getModel')->willReturn('claude-sonnet-5');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->method('listModels')->willThrowException(new LlmException('Erreur fournisseur (HTTP 401) : cle invalide'));

        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('get')->willReturn($provider);

        $catalog = $this->catalog($factory, $config);

        $this->assertSame(
            [['id' => 'claude-sonnet-5', 'label' => 'claude-sonnet-5']],
            $catalog->getModels(WittyConfig::PROVIDER_ANTHROPIC),
        );
    }

    public function testSuccessfulFetchPrependsDefaultModelWhenMissingFromCatalogue(): void
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('getApiKey')->willReturn('sk-test');
        $config->method('getModel')->willReturn('claude-opus-5');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())->method('listModels')->willReturn([
            ['id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5'],
        ]);

        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('get')->willReturn($provider);

        $catalog = $this->catalog($factory, $config);

        $this->assertSame([
            ['id' => 'claude-opus-5', 'label' => 'claude-opus-5'],
            ['id' => 'claude-sonnet-5', 'label' => 'Claude Sonnet 5'],
        ], $catalog->getModels(WittyConfig::PROVIDER_ANTHROPIC));
    }

    public function testSecondCallWithinTtlIsServedFromCacheWithoutHittingTheProvider(): void
    {
        $config = $this->createMock(WittyConfig::class);
        $config->method('getApiKey')->willReturn('sk-test');
        $config->method('getModel')->willReturn('gpt-4o');

        $provider = $this->createMock(LlmProviderInterface::class);
        $provider->expects($this->once())->method('listModels')->willReturn([
            ['id' => 'gpt-4o', 'label' => 'gpt-4o'],
            ['id' => 'gpt-4o-mini', 'label' => 'gpt-4o-mini'],
        ]);

        $factory = $this->createMock(ProviderFactory::class);
        $factory->method('get')->willReturn($provider);

        $catalog = $this->catalog($factory, $config);

        $first  = $catalog->getModels(WittyConfig::PROVIDER_OPENAI);
        $second = $catalog->getModels(WittyConfig::PROVIDER_OPENAI);

        $this->assertSame($first, $second);
    }

    private function catalog(ProviderFactory $factory, WittyConfig $config): ModelCatalog
    {
        $paths = $this->createMock(PathsHelper::class);
        $paths->method('getCachePath')->willReturn($this->cacheDir);

        return new ModelCatalog($factory, $config, new NullLogger(), $paths);
    }
}
