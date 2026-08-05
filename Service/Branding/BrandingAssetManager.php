<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Branding;

use Mautic\CoreBundle\Helper\PathsHelper;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Deplace les fichiers de marque (logo, favicon) importes depuis la fiche du
 * plugin (cf. Form/Type/FeatureSettingsType.php) vers media/images/ :
 *
 * - favicon.ico : chemin fixe attendu par AssetsHelper::getOverridableUrl(),
 *   deja appele par head.html.twig (core) — un fichier present a cet endroit
 *   suffit, aucun autre changement necessaire cote Mautic.
 * - witty_custom_logo.{ext} : pas de mecanisme d'override natif pour le logo
 *   (le gabarit core l'inline en SVG brut via `source()`), pris en charge par
 *   un remplacement visuel en CSS, cf. EventListener/BrandingSubscriber.php.
 */
class BrandingAssetManager
{
    private const LOGO_EXTENSIONS = ['png', 'jpg', 'jpeg', 'svg', 'webp'];

    private const FAVICON_EXTENSIONS = ['ico', 'png'];

    private const FAVICON_FILENAME = 'favicon.ico';

    public function __construct(private PathsHelper $pathsHelper)
    {
    }

    /**
     * @return string|null Nom de fichier stocke (a persister en feature_settings), null si extension refusee.
     */
    public function storeLogo(UploadedFile $file): ?string
    {
        $extension = $this->extensionOf($file);

        if (!in_array($extension, self::LOGO_EXTENSIONS, true)) {
            return null;
        }

        $this->removeExistingLogo();

        $filename = 'witty_custom_logo.'.$extension;
        $file->move($this->imagesDir(), $filename);

        return $filename;
    }

    /**
     * Toujours enregistre sous favicon.ico quelle que soit l'extension
     * d'origine (le chemin est fixe cote Mautic) : les navigateurs
     * determinent le vrai type au contenu, pas a l'extension, un PNG servi
     * sous ce nom s'affiche correctement dans l'immense majorite des cas.
     *
     * @return bool false si extension refusee, rien enregistre.
     */
    public function storeFavicon(UploadedFile $file): bool
    {
        if (!in_array($this->extensionOf($file), self::FAVICON_EXTENSIONS, true)) {
            return false;
        }

        $file->move($this->imagesDir(), self::FAVICON_FILENAME);

        return true;
    }

    private function extensionOf(UploadedFile $file): string
    {
        return strtolower($file->getClientOriginalExtension() ?: (string) $file->guessExtension());
    }

    private function removeExistingLogo(): void
    {
        foreach (self::LOGO_EXTENSIONS as $extension) {
            $path = $this->imagesDir().'/witty_custom_logo.'.$extension;

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    private function imagesDir(): string
    {
        $dir = rtrim($this->pathsHelper->getMediaPath(), '/').'/images';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        return $dir;
    }
}
