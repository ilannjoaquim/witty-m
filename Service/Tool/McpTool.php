<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;

/**
 * Adaptateur ToolInterface pour un outil decouvert en direct sur un serveur
 * MCP distant (voir McpClientInterface). Contrairement aux classes de
 * Service/Tool/Tools/, une instance n'est jamais taguee 'witty.tool' : elle
 * est construite a la volee par ToolRegistry a partir de tools/list.
 *
 * Aucune permission Mautic ni notion d'ecriture en base : ces outils ne
 * touchent jamais Mautic, seulement le web via l'infrastructure du
 * fournisseur MCP. Le comportement par defaut d'AbstractTool convient donc
 * tel quel.
 */
final class McpTool extends AbstractTool
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        private McpClientInterface $client,
        private string $remoteName,
        private string $description,
        private array $schema,
    ) {
    }

    public function getName(): string
    {
        return $this->client->getNamespace().'_'.$this->remoteName;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function execute(array $arguments): array
    {
        return $this->client->callTool($this->remoteName, $arguments);
    }
}
