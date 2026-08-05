<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Mautic\CoreBundle\Twig\Helper\AssetsHelper;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Remplace visuellement le logo Mautic par celui importe dans la fiche du
 * plugin (Fonctionnalites > Affichage), quand il y en a un.
 *
 * Pas de mecanisme d'override natif pour le logo comme pour le favicon
 * (AssetsHelper::getOverridableUrl(), cf. BrandingAssetManager) : le gabarit
 * core (navbar.html.twig) inline le SVG directement via `source()`, il n'y a
 * pas de fichier a remplacer sur disque. On injecte donc un <style> qui
 * masque ce SVG et affiche le logo importe en image de fond a la place,
 * uniquement sur les pages admin (prefixe /s/) qui renvoient du HTML.
 */
class BrandingSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private WittyConfig $config,
        private AssetsHelper $assetsHelper,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelResponse(ResponseEvent $event): void
    {
        if (!$event->isMainRequest() || !$this->config->hasCustomLogo()) {
            return;
        }

        if (!str_starts_with($event->getRequest()->getPathInfo(), '/s/')) {
            return;
        }

        $response = $event->getResponse();

        if (!str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            return;
        }

        $content = $response->getContent();

        if (false === $content || !str_contains($content, '</head>')) {
            return;
        }

        $logoUrl = $this->assetsHelper->getUrl(
            'media/images/'.$this->config->getCustomLogoFilename(),
            null,
            null,
            true
        );

        $style = '<style id="witty-branding-override">'
            .'.mautic-brand svg{display:none!important}'
            .'.mautic-brand{background-image:url('.$logoUrl.');background-repeat:no-repeat;'
            .'background-position:left center;background-size:contain}'
            .'</style>';

        $response->setContent(str_replace('</head>', $style.'</head>', $content));
    }
}
