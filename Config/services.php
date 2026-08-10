<?php

declare(strict_types=1);

use Mautic\CoreBundle\DependencyInjection\MauticCoreExtension;
use MauticPlugin\WittyBundle\Integration\WittyIntegration;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;
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

    // Meme principe pour un serveur MCP distant (Bright Data...) : sa liste
    // d'outils n'est pas connue a la compilation, ToolRegistry l'interroge en
    // direct (tools/list) et l'expose au modele sous forme de McpTool.
    $services->instanceof(McpClientInterface::class)->tag('witty.mcp_client');

    // Objets de valeur : construits a la main, jamais injectes. Les laisser dans
    // l'autowiring ferait echouer la compilation du conteneur sur leurs
    // arguments scalaires. McpTool implemente ToolInterface (donc serait tague
    // 'witty.tool' par la regle ci-dessus) mais est construit a la volee par
    // ToolRegistry a partir de tools/list, jamais par le conteneur.
    $excludes = [
        'Service/Llm/Dto',
        'Service/Tool/McpTool.php',
    ];

    $services->load('MauticPlugin\\WittyBundle\\', '../')
        ->exclude('../{'.implode(',', array_merge(MauticCoreExtension::DEFAULT_EXCLUDES, $excludes)).'}');

    // Entity/ est exclu du chargement ci-dessus ; les repositories doivent tout
    // de meme etre des services, sinon Doctrine ne sait pas les instancier
    // (ils heritent de ServiceEntityRepository).
    $services->load('MauticPlugin\\WittyBundle\\Entity\\', '../Entity/*Repository.php');

    $services->get(ToolRegistry::class)
        ->arg('$tools', tagged_iterator('witty.tool'))
        ->arg('$mcpClients', tagged_iterator('witty.mcp_client'));

    // Cle de service imposee par IntegrationHelper : mautic.integration.<nom en
    // minuscules>. Sans cet alias, l'integration n'est pas instanciee et le
    // plugin retombe sur la fiche descriptive sans formulaire.
    $services->alias('mautic.integration.witty', WittyIntegration::class)->public();
};
