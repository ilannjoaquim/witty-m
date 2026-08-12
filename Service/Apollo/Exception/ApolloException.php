<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Apollo\Exception;

/**
 * Erreur de transport ou reponse d'erreur de l'API Apollo (people/organizations
 * enrichment). Meme role que Service/Llm/Exception/LlmException.php et
 * Service/Mcp/Exception/McpException.php pour leurs integrations respectives.
 */
class ApolloException extends \RuntimeException
{
}
