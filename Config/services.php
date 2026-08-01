<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\WittyBundle\Integration\WittyIntegration;
use MauticPlugin\WittyBundle\Service\Tool\ToolInterface;
use MauticPlugin\WittyBundle\Service\Tool\ToolRegistry;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return function (ContainerConfigurator $configurator): void {
    $services = $configurator->services()
        ->defaults()
        ->autowire()
        ->autoconfigure()
        ->public();

    // Toute classe implementant ToolInterface devient automatiquement une
    // capacite de l'agent : ajouter un outil = deposer un fichier.
    $services->instanceof(ToolInterface::class)->tag('witty.tool');

    $excludes = [
        'Service/Llm/Dto',
    ];

    $services->load('MauticPlugin\\WittyBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    $services->get(ToolRegistry::class)->arg('$tools', tagged_iterator('witty.tool'));

    // Cle de service imposee par IntegrationHelper : mautic.integration.<nom en
    // minuscules>. Sans cet alias, l'integration n'est pas instanciee et le
    // plugin retombe sur la fiche descriptive sans formulaire.
    $services->alias('mautic.integration.witty', WittyIntegration::class)->public();
};
