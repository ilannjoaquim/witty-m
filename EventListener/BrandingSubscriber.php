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
 * masque ce SVG et affiche l'image importee en fond a la place, uniquement
 * sur les pages admin (prefixe /s/) qui renvoient du HTML.
 *
 * navbar.html.twig rend DEUX blocs distincts, tous deux avec la classe
 * `.mautic-brand` : `.brand-logo--desktop` (logo--expanded.svg, visible sauf
 * en mobile) et `.brand-logo--mobile` (logo--minimized.svg, visible
 * uniquement en mobile). Chacun recoit sa propre regle, ciblee sur les DEUX
 * classes a la fois plutot que sur `.mautic-brand` seul : plus specifique,
 * donc moins susceptible d'etre repris par une regle Mautic plus precise
 * (ex. `.navbar-nav > .mautic-brand`), et `!important` sur chaque propriete
 * (pas seulement `display`) leve tout doute residuel de specificite.
 *
 * Le logo (large, format libre) va sur le bloc desktop ; le favicon
 * (necessairement carre) va sur le bloc mobile, plus adapte a cet
 * emplacement carre qu'un logo large etire/rogne. Les deux sont
 * independants : un favicon seul suffit a personnaliser le bloc mobile,
 * meme sans logo importe.
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
        $hasLogo    = $this->config->hasCustomLogo();
        $hasFavicon = $this->config->hasCustomFavicon();

        if (!$event->isMainRequest() || (!$hasLogo && !$hasFavicon)) {
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

        $rules = [];

        if ($hasLogo) {
            $logoUrl = $this->assetsHelper->getUrl(
                'media/images/'.$this->config->getCustomLogoFilename(),
                null,
                null,
                true
            );

            $rules[] = $this->overrideRule('.brand-logo--desktop.mautic-brand', $logoUrl, 'left center');
        }

        if ($hasFavicon) {
            $faviconUrl = $this->assetsHelper->getUrl('media/images/favicon.ico', null, null, true);

            $rules[] = $this->overrideRule('.brand-logo--mobile.mautic-brand', $faviconUrl, 'center');
        }

        $style = '<style id="witty-branding-override">'.implode('', $rules).'</style>';

        $response->setContent(str_replace('</head>', $style.'</head>', $content));
    }

    private function overrideRule(string $selector, string $imageUrl, string $position): string
    {
        return $selector.' svg{display:none!important}'
            .$selector.'{'
            .'background-image:url('.$imageUrl.')!important;'
            .'background-repeat:no-repeat!important;'
            .'background-position:'.$position.'!important;'
            .'background-size:contain!important'
            .'}';
    }
}
