<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Mcp;

/**
 * Client d'un serveur MCP (Model Context Protocol) distant.
 *
 * Contrairement aux outils de Service/Tool/Tools/ (un fichier = une capacite
 * fixe, connue a la compilation), un serveur MCP expose sa propre liste
 * d'outils, decouverte en direct via tools/list : on ne code pas leurs noms
 * ou schemas en dur, on les relaie tels quels au modele. ToolRegistry
 * transforme chaque outil distant en ToolInterface (McpTool) a la volee.
 *
 * Une classe = un serveur MCP. Ajouter un deuxieme fournisseur MCP plus tard
 * = une nouvelle classe taguee 'witty.mcp_client', sur le meme principe que
 * 'witty.tool'.
 */
interface McpClientInterface
{
    /**
     * Prefixe ajoute au nom de chaque outil distant pour l'exposer au modele
     * (ex. 'brightdata' => 'search_engine' devient 'brightdata_search_engine'),
     * et evite toute collision avec les outils Mautic locaux ou un autre
     * serveur MCP.
     */
    public function getNamespace(): string;

    /**
     * false si aucune cle API n'est renseignee : le registre ignore alors ce
     * client, sans tenter le moindre appel reseau.
     */
    public function isConfigured(): bool;

    /**
     * @return array<int, array{name: string, description: string, schema: array<mixed>}>
     *
     * @throws Exception\McpException
     */
    public function listTools(): array;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> resultat serialisable renvoye au modele
     *
     * @throws Exception\McpException
     */
    public function callTool(string $name, array $arguments): array;
}
