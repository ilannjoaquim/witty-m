<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use MauticPlugin\WittyBundle\Integration\WittyIntegration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Empeche la perte silencieuse des cles API deja enregistrees quand on n'en
 * modifie qu'une seule.
 *
 * Les champs de l'onglet Details sont des PasswordType : Symfony les affiche
 * toujours vides a l'ouverture du formulaire (PasswordType::buildView() force
 * la valeur a '' des qu'il ne s'agit pas d'une resoumission — always_empty ne
 * change rien a ce comportement pour un GET, seulement pour un POST invalide
 * qui se re-affiche). Consequence : un champ non retouche repart vide dans la
 * soumission. Sans ce souscripteur, valider le formulaire pour n'ajouter que
 * la cle Bright Data effacerait Anthropic, OpenAI, plugNmeet, etc.
 *
 * ConfigController::submitForm() dispatche INTEGRATION_API_KEYS_BEFORE_SAVE
 * juste apres avoir lie le formulaire (KeysSaveEvent porte donc les valeurs
 * soumises ET les valeurs precedentes), avant chiffrement et persistance : on
 * y restaure toute cle soumise vide a partir de sa valeur precedente.
 */
class ConfigKeysSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            IntegrationEvents::INTEGRATION_API_KEYS_BEFORE_SAVE => 'onKeysBeforeSave',
        ];
    }

    public function onKeysBeforeSave(KeysSaveEvent $event): void
    {
        // Ne concerne que l'integration Witty : ce comportement ne doit pas
        // s'appliquer aux cles d'autres plugins qui ecoutent le meme evenement.
        if (WittyIntegration::NAME !== $event->getIntegrationConfiguration()->getName()) {
            return;
        }

        $old = $event->getOldKeys();
        $new = $event->getNewKeys();

        foreach ($old as $field => $previousValue) {
            $submitted = (string) ($new[$field] ?? '');

            if ('' === trim($submitted) && '' !== trim((string) $previousValue)) {
                $new[$field] = $previousValue;
            }
        }

        $event->getIntegrationConfiguration()->setApiKeys($new);
    }
}
