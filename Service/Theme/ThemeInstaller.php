<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Theme;

use Mautic\CoreBundle\Helper\PathsHelper;
use Psr\Log\LoggerInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Installe les themes livres par le plugin dans le dossier themes/ de Mautic.
 *
 * ThemeHelper ne scanne que ce dossier : un theme reste invisible tant qu'il
 * dort dans le plugin. La copie a lieu a l'installation et a chaque mise a jour
 * du plugin, et peut etre rejouee a la main avec `witty:themes:install`.
 */
class ThemeInstaller
{
    public function __construct(
        private PathsHelper $pathsHelper,
        private Filesystem $filesystem,
        private LoggerInterface $logger,
        private ?string $sourceDirectory = null,
    ) {
        $this->sourceDirectory ??= \dirname(__DIR__, 2).'/Themes';
    }

    /**
     * @return array<string, string> theme => 'installe' | 'mis a jour' | 'conserve'
     */
    public function install(bool $overwrite = true): array
    {
        $report = [];

        if (!is_dir((string) $this->sourceDirectory)) {
            return $report;
        }

        $destinationRoot = $this->pathsHelper->getSystemPath('themes', true);

        foreach ((array) glob($this->sourceDirectory.'/*', GLOB_ONLYDIR) as $source) {
            $name        = basename((string) $source);
            $destination = $destinationRoot.'/'.$name;
            $exists      = $this->filesystem->exists($destination);

            if ($exists && !$overwrite) {
                $report[$name] = 'conserve';
                continue;
            }

            try {
                // delete: true retire les fichiers absents de la source, sinon
                // un fichier supprime du theme survivrait indefiniment.
                $this->filesystem->mirror((string) $source, $destination, null, ['override' => true, 'delete' => true]);
                $report[$name] = $exists ? 'mis a jour' : 'installe';
            } catch (\Throwable $e) {
                $this->logger->error('Witty : installation du theme impossible', ['theme' => $name, 'exception' => $e]);
                $report[$name] = 'echec : '.$e->getMessage();
            }
        }

        return $report;
    }

    /**
     * @return array<int, string>
     */
    public function getShippedThemes(): array
    {
        if (!is_dir((string) $this->sourceDirectory)) {
            return [];
        }

        return array_map('basename', (array) glob($this->sourceDirectory.'/*', GLOB_ONLYDIR));
    }
}
