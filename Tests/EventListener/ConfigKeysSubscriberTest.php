<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\EventListener;

use Mautic\IntegrationsBundle\Event\KeysSaveEvent;
use Mautic\IntegrationsBundle\IntegrationEvents;
use Mautic\PluginBundle\Entity\Integration;
use MauticPlugin\WittyBundle\EventListener\ConfigKeysSubscriber;
use PHPUnit\Framework\TestCase;

/**
 * Les champs de cle API sont des PasswordType : Symfony les affiche toujours
 * vides a l'ouverture du formulaire, donc tout champ non retouche repart vide
 * dans la soumission. Sans ce souscripteur, sauvegarder pour n'ajouter qu'une
 * seule cle (ex. Bright Data) effacerait silencieusement toutes les autres —
 * c'est exactement le bug rapporte.
 */
class ConfigKeysSubscriberTest extends TestCase
{
    public function testBlankSubmittedKeysAreRestoredFromPreviousValues(): void
    {
        // L'utilisateur n'a touche que le champ Bright Data ; les autres,
        // affiches vides par le navigateur, repartent vides dans le POST.
        $event = $this->event('Witty', [
            'anthropic_api_key'  => 'sk-ant-old',
            'brightdata_api_key' => '',
        ], [
            'anthropic_api_key'  => '',
            'brightdata_api_key' => 'bd-token-new',
        ]);

        (new ConfigKeysSubscriber())->onKeysBeforeSave($event);

        $this->assertSame([
            'anthropic_api_key'  => 'sk-ant-old',
            'brightdata_api_key' => 'bd-token-new',
        ], $event->getIntegrationConfiguration()->getApiKeys());
    }

    public function testFieldsWithNoPreviousValueStayEmptyWhenSubmittedEmpty(): void
    {
        $event = $this->event('Witty', [
            'openai_api_key' => '',
        ], [
            'openai_api_key' => '',
        ]);

        (new ConfigKeysSubscriber())->onKeysBeforeSave($event);

        $this->assertSame(['openai_api_key' => ''], $event->getIntegrationConfiguration()->getApiKeys());
    }

    public function testOtherIntegrationsAreLeftUntouched(): void
    {
        $event = $this->event('SomeOtherIntegration', [
            'api_key' => 'old-value',
        ], [
            'api_key' => '',
        ]);

        (new ConfigKeysSubscriber())->onKeysBeforeSave($event);

        $this->assertSame(['api_key' => ''], $event->getIntegrationConfiguration()->getApiKeys(), 'Ce souscripteur ne doit agir que sur l integration Witty.');
    }

    public function testIsSubscribedToTheKeysBeforeSaveEvent(): void
    {
        $events = ConfigKeysSubscriber::getSubscribedEvents();

        $this->assertArrayHasKey(IntegrationEvents::INTEGRATION_API_KEYS_BEFORE_SAVE, $events);
    }

    /**
     * @param array<string, string> $oldKeys
     * @param array<string, string> $submittedKeys
     */
    private function event(string $integrationName, array $oldKeys, array $submittedKeys): KeysSaveEvent
    {
        $integration = new Integration();
        $integration->setName($integrationName);
        // Etat apres liaison du formulaire (form->handleRequest()), avant sauvegarde.
        $integration->setApiKeys($submittedKeys);

        return new KeysSaveEvent($integration, $oldKeys);
    }
}
