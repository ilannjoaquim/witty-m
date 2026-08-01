<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Integration;

use Mautic\IntegrationsBundle\Integration\BasicIntegration;
use Mautic\IntegrationsBundle\Integration\ConfigurationTrait;
use Mautic\IntegrationsBundle\Integration\Interfaces\BasicInterface;

/**
 * Sans cette classe, Mautic considere WittyBundle comme un simple bundle : la
 * fiche du plugin n'affiche que la description, sans formulaire ni boutons.
 *
 * Le nom du fichier est significatif : IntegrationHelper scanne Integration/
 * a la recherche de *Integration.php et deduit le nom de l'integration du nom
 * du fichier (WittyIntegration.php => "Witty"). Le service doit par ailleurs
 * etre expose sous la cle mautic.integration.witty (voir Config/services.php).
 */
class WittyIntegration extends BasicIntegration implements BasicInterface
{
    use ConfigurationTrait;

    public const NAME = 'Witty';

    public const DISPLAY_NAME = 'Witty';

    public function getName(): string
    {
        return self::NAME;
    }

    public function getDisplayName(): string
    {
        return self::DISPLAY_NAME;
    }

    public function getIcon(): string
    {
        return 'plugins/WittyBundle/Assets/img/icon.png';
    }
}
