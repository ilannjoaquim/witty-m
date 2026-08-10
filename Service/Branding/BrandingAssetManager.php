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
 *   suffit pour les pages admin (prefixe /s/), aucun autre changement cote
 *   Mautic necessaire pour elles. Insuffisant pour tout le reste (landing
 *   pages, apercu web d'un email, page de desabonnement...) : ces gabarits
 *   n'incluent jamais head.html.twig et ne posent donc aucun <link
 *   rel="icon"> explicite. Le navigateur retombe alors sur la requete
 *   implicite /favicon.ico a la racine du site, servie par le fichier
 *   statique du document root de Mautic (PathsHelper::getRootPath()) —
 *   c'est ce fichier qu'on ecrase egalement, sans quoi tout ce qui est
 *   public affiche le favicon Mautic par defaut quel que soit l'import.
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
     * Ecrit a deux endroits : media/images/ (pages admin, via
     * getOverridableUrl()) et le document root de Mautic (tout le reste,
     * public — voir la docblock de la classe). `move()` deplace le fichier
     * uploade, donc une seule des deux copies peut se faire par simple
     * deplacement ; l'autre recopie le fichier deja en place sur disque.
     *
     * @return bool false si extension refusee, rien enregistre.
     */
    public function storeFavicon(UploadedFile $file): bool
    {
        if (!in_array($this->extensionOf($file), self::FAVICON_EXTENSIONS, true)) {
            return false;
        }

        $file->move($this->imagesDir(), self::FAVICON_FILENAME);

        $rootFavicon = rtrim($this->pathsHelper->getRootPath(), '/').'/'.self::FAVICON_FILENAME;
        copy($this->imagesDir().'/'.self::FAVICON_FILENAME, $rootFavicon);

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
