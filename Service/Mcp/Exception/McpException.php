<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Mcp\Exception;

/**
 * Erreur de protocole ou de transport avec un serveur MCP distant (init,
 * tools/list, tools/call). Ne concerne jamais un outil Mautic local — voir
 * ToolRegistry pour ceux-la.
 */
class McpException extends \RuntimeException
{
}
