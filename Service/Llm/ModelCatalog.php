<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Llm;

use Mautic\CoreBundle\Helper\PathsHelper;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

/**
 * Catalogue des modeles disponibles par fournisseur : un appel a l'API de
 * chacun (couteux et sujet a panne/quota), mis en cache une heure, avec repli
 * sur le seul modele configure par defaut si l'appel echoue. Le dropdown du
 * chat ne doit jamais se retrouver vide.
 */
class ModelCatalog
{
    private const CACHE_TTL = 3600;

    private FilesystemAdapter $cache;

    public function __construct(
        private ProviderFactory $providerFactory,
        private WittyConfig $config,
        private LoggerInterface $logger,
        PathsHelper $pathsHelper,
    ) {
        $this->cache = new FilesystemAdapter('witty.models', self::CACHE_TTL, $pathsHelper->getCachePath());
    }

    /**
     * @return array<int, array{id: string, label: string}>
     */
    public function getModels(string $provider): array
    {
        $apiKey  = $this->config->getApiKey($provider);
        $default = $this->config->getModel($provider);

        if ('' === $apiKey) {
            return '' !== $default ? [['id' => $default, 'label' => $default]] : [];
        }

        $item = null;

        try {
            $item = $this->cache->getItem($this->cacheKey($provider, $apiKey));
            $cached = $item->get();

            if ($item->isHit() && is_array($cached)) {
                return $cached;
            }
        } catch (\Throwable) {
            // Cache indisponible (permissions, disque plein...) : on degrade
            // en appel direct sans mise en cache.
            $item = null;
        }

        try {
            $models = $this->providerFactory->get($provider)->listModels($apiKey);
        } catch (\Throwable $e) {
            $this->logger->warning('Witty: impossible de recuperer les modeles disponibles.', [
                'provider'  => $provider,
                'exception' => $e->getMessage(),
            ]);

            return '' !== $default ? [['id' => $default, 'label' => $default]] : [];
        }

        $ids = array_column($models, 'id');

        if ('' !== $default && !in_array($default, $ids, true)) {
            array_unshift($models, ['id' => $default, 'label' => $default]);
        }

        if (null !== $item) {
            $item->set($models);
            $item->expiresAfter(self::CACHE_TTL);

            try {
                $this->cache->save($item);
            } catch (\Throwable) {
                // Tant pis pour le cache, le resultat est deja bon a renvoyer.
            }
        }

        return $models;
    }

    /**
     * Distincte par cle API : un changement de cle doit rafraichir le catalogue.
     */
    private function cacheKey(string $provider, string $apiKey): string
    {
        return sprintf('models_%s_%s', $provider, substr(hash('sha256', $apiKey), 0, 16));
    }
}
