<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Mautic\ConfigBundle\ConfigEvents;
use Mautic\ConfigBundle\Event\ConfigBuilderEvent;
use Mautic\ConfigBundle\Event\ConfigEvent;
use Mautic\CoreBundle\Helper\EncryptionHelper;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Ajoute l'onglet "Witty" dans Parametres > Configuration et chiffre la cle API
 * avant persistance dans app/config/local.php.
 */
class ConfigSubscriber implements EventSubscriberInterface
{
    public function __construct(private EncryptionHelper $encryptionHelper)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConfigEvents::CONFIG_ON_GENERATE => ['onConfigGenerate', 0],
            ConfigEvents::CONFIG_PRE_SAVE    => ['onConfigPreSave', 0],
        ];
    }

    public function onConfigGenerate(ConfigBuilderEvent $event): void
    {
        $event->addForm([
            'bundle'     => 'WittyBundle',
            'formAlias'  => 'wittyconfig',
            'parameters' => $event->getParametersFromConfig('WittyBundle'),
        ]);
    }

    public function onConfigPreSave(ConfigEvent $event): void
    {
        $data = $event->getConfig('wittyconfig');

        if (!is_array($data)) {
            return;
        }

        $key = (string) ($data['witty_api_key'] ?? '');

        if ('' !== $key && !str_starts_with($key, WittyConfig::ENC_PREFIX)) {
            $data['witty_api_key'] = WittyConfig::ENC_PREFIX.$this->encryptionHelper->encrypt($key);
            $event->setConfig($data, 'wittyconfig');
        }
    }
}
