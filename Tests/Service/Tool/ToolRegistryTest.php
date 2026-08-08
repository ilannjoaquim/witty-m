<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool;

use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use MauticPlugin\WittyBundle\Service\Audit\AuditLogger;
use MauticPlugin\WittyBundle\Service\Mcp\Exception\McpException;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\ToolInterface;
use MauticPlugin\WittyBundle\Service\Tool\ToolRegistry;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Un serveur MCP distant (Bright Data...) n'a pas de liste d'outils connue a
 * la compilation, contrairement aux classes de Service/Tool/Tools/ : le
 * registre doit la decouvrir en direct (tools/list), la fusionner avec les
 * outils locaux sous un nom namespace, et ne jamais planter dessus si le
 * serveur est indisponible ou mal configure.
 */
class ToolRegistryTest extends TestCase
{
    public function testDefinitionsIncludeNamespacedMcpToolsAlongsideLocalOnes(): void
    {
        $mcpClient = $this->mcpClient(true, [
            ['name' => 'search_engine', 'description' => 'Recherche web', 'schema' => ['type' => 'object', 'properties' => []]],
        ]);

        $registry = $this->registry([$this->localTool()], [$mcpClient]);

        $names = array_column($registry->getDefinitions(), 'name');

        $this->assertContains('ping', $names);
        $this->assertContains('brightdata_search_engine', $names, 'Le nom distant doit etre prefixe du namespace du client MCP.');
    }

    public function testUnconfiguredMcpClientIsNeverQueried(): void
    {
        $mcpClient = $this->createMock(McpClientInterface::class);
        $mcpClient->method('isConfigured')->willReturn(false);
        $mcpClient->expects($this->never())->method('listTools');

        $registry = $this->registry([$this->localTool()], [$mcpClient]);

        $names = array_column($registry->getDefinitions(), 'name');

        $this->assertSame(['ping'], $names);
    }

    public function testMcpDiscoveryFailureIsSwallowedAndLocalToolsStillWork(): void
    {
        $mcpClient = $this->createMock(McpClientInterface::class);
        $mcpClient->method('isConfigured')->willReturn(true);
        $mcpClient->method('listTools')->willThrowException(new McpException('Bright Data indisponible'));

        $registry = $this->registry([$this->localTool()], [$mcpClient]);

        $names = array_column($registry->getDefinitions(), 'name');

        $this->assertSame(['ping'], $names, 'Un serveur MCP en panne ne doit pas empecher les outils Mautic locaux d apparaitre.');
    }

    public function testExecuteStripsTheNamespaceBeforeCallingTheRemoteTool(): void
    {
        $mcpClient = $this->mcpClient(true, [
            ['name' => 'search_engine', 'description' => 'Recherche web', 'schema' => ['type' => 'object', 'properties' => []]],
        ]);
        $mcpClient->expects($this->once())
            ->method('callTool')
            ->with('search_engine', ['query' => 'mautic'])
            ->willReturn(['status' => 'ok', 'result' => 'reponse']);

        $registry = $this->registry([], [$mcpClient]);

        $output = $registry->execute('brightdata_search_engine', ['query' => 'mautic']);

        $this->assertSame(['status' => 'ok', 'result' => 'reponse'], $output);
    }

    public function testMcpToolsAreDiscoveredOnlyOncePerRegistryInstance(): void
    {
        $mcpClient = $this->mcpClient(true, [
            ['name' => 'search_engine', 'description' => 'Recherche web', 'schema' => ['type' => 'object', 'properties' => []]],
        ]);
        $mcpClient->method('callTool')->willReturn(['status' => 'ok']);
        $mcpClient->expects($this->once())->method('listTools');

        $registry = $this->registry([], [$mcpClient]);

        // getDefinitions() en debut de tour, puis execute() plusieurs fois dans
        // la meme boucle d'iterations (voir AgentRunner::run()) : un seul
        // tools/list doit suffire pour tout le tour.
        $registry->getDefinitions();
        $registry->getDefinitions();
        $registry->execute('brightdata_search_engine', []);
    }

    public function testUnknownToolNameReturnsAnErrorEvenAfterMcpMerge(): void
    {
        $mcpClient = $this->mcpClient(true, []);

        $registry = $this->registry([], [$mcpClient]);

        $output = $registry->execute('does_not_exist', []);

        $this->assertSame('error', $output['status']);
    }

    private function localTool(): ToolInterface
    {
        return new class extends AbstractTool {
            public function getName(): string
            {
                return 'ping';
            }

            public function getDescription(): string
            {
                return 'Ping.';
            }

            public function getSchema(): array
            {
                return $this->schema([]);
            }

            public function execute(array $arguments): array
            {
                return $this->ok([]);
            }
        };
    }

    /**
     * @param array<int, array{name: string, description: string, schema: array<mixed>}> $tools
     */
    private function mcpClient(bool $configured, array $tools): McpClientInterface
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('isConfigured')->willReturn($configured);
        $client->method('getNamespace')->willReturn('brightdata');
        $client->method('listTools')->willReturn($tools);

        return $client;
    }

    /**
     * @param array<int, ToolInterface>      $tools
     * @param array<int, McpClientInterface> $mcpClients
     */
    private function registry(array $tools, array $mcpClients): ToolRegistry
    {
        $security = $this->createMock(CorePermissions::class);
        $security->method('isGranted')->willReturn(true);

        $config = $this->createMock(WittyConfig::class);
        $config->method('requiresConfirmation')->willReturn(false);

        $auditLogger = $this->createMock(AuditLogger::class);

        return new ToolRegistry($tools, $security, $config, $auditLogger, new NullLogger(), $mcpClients);
    }
}
