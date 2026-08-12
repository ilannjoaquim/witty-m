<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\McpBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;
use MauticPlugin\WittyBundle\Service\Tool\Tools\StartBulkMcpSearchTool;
use PHPUnit\Framework\TestCase;

/**
 * Ce que ce test couvre : le refus si le serveur MCP demande n'est pas
 * configure (jamais un job cree pour rien), et le fait que les champs requis
 * par McpBulkSearchJobHandler pour paginer (page_argument, items_field...)
 * sont bien tous obligatoires ici plutot que devines plus tard.
 */
class StartBulkMcpSearchToolTest extends TestCase
{
    public function testInvalidNamespaceIsRejected(): void
    {
        $output = $this->tool([])->execute([
            'namespace' => 'bogus', 'tool_name' => 'x', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 10,
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testMissingRequiredFieldsAreRejected(): void
    {
        $output = $this->tool([])->execute(['namespace' => 'prospeo', 'target_count' => 10]);

        $this->assertSame('error', $output['status']);
    }

    public function testUnconfiguredClientIsRejectedWithoutCreatingAJob(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->never())->method('persist');

        $output = $this->tool([$client], $em)->execute([
            'namespace' => 'prospeo', 'tool_name' => 'search_person', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 10,
        ]);

        $this->assertSame('error', $output['status']);
    }

    public function testValidRequestCreatesAJobOfTheRightType(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(true);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->once())->method('persist')->with($this->callback(
            static fn (WittyBackgroundJob $job): bool => McpBulkSearchJobHandler::TYPE === $job->getType()
                && 'prospeo' === $job->getParams()['namespace']
                && 'search_person' === $job->getParams()['tool_name'],
        ));

        $output = $this->tool([$client], $em)->execute([
            'namespace' => 'prospeo', 'tool_name' => 'search_person', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 200,
        ]);

        $this->assertSame('ok', $output['status']);
    }

    /**
     * @param McpClientInterface[] $clients
     */
    private function tool(array $clients, ?EntityManagerInterface $em = null): StartBulkMcpSearchTool
    {
        $em ??= $this->createMock(EntityManagerInterface::class);

        $userHelper = $this->createMock(UserHelper::class);
        $userHelper->method('getUser')->willReturn(new User());

        return new StartBulkMcpSearchTool($clients, $em, $userHelper);
    }
}
