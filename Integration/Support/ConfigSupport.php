<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Integration\Support;

use Mautic\IntegrationsBundle\DTO\Note;
use Mautic\IntegrationsBundle\Integration\DefaultConfigFormTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormAuthInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormFeatureSettingsInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormInterface;
use Mautic\IntegrationsBundle\Integration\Interfaces\ConfigFormNotesInterface;
use MauticPlugin\WittyBundle\Form\Type\AuthType;
use MauticPlugin\WittyBundle\Form\Type\FeatureSettingsType;
use MauticPlugin\WittyBundle\Integration\WittyIntegration;

/**
 * Declare le formulaire affiche dans la fiche du plugin :
 *
 * - ConfigFormInterface           : rend la fiche editable (onglets + boutons)
 * - ConfigFormAuthInterface       : onglet "Details" -> cle API (chiffree par Mautic)
 * - ConfigFormFeatureSettingsInterface : onglet "Fonctionnalites" -> reste des reglages
 *
 * Ce fichier ne doit pas s'appeler *Integration.php, sinon IntegrationHelper le
 * prendrait pour une seconde integration.
 */
class ConfigSupport extends WittyIntegration implements ConfigFormInterface, ConfigFormAuthInterface, ConfigFormFeatureSettingsInterface, ConfigFormNotesInterface
{
    use DefaultConfigFormTrait;

    public function getAuthConfigFormName(): string
    {
        return AuthType::class;
    }

    public function getFeatureSettingsConfigFormName(): string
    {
        return FeatureSettingsType::class;
    }

    public function getAuthorizationNote(): ?Note
    {
        return new Note('mautic.witty.config.auth.note', Note::TYPE_INFO);
    }

    public function getFeaturesNote(): ?Note
    {
        return null;
    }

    public function getFieldMappingNote(): ?Note
    {
        return null;
    }
}
