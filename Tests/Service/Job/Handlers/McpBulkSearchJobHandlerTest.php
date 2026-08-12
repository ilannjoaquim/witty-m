<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job\Handlers;

use Doctrine\ORM\EntityManagerInterface;
use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\Handlers\McpBulkSearchJobHandler;
use MauticPlugin\WittyBundle\Service\Mcp\McpClientInterface;
use PHPUnit\Framework\TestCase;

/**
 * Un seul handler pour n'importe quel outil MCP (Prospeo, data.gouv.fr...) :
 * ce qui merite un test dedie est justement la generalite — resolution du
 * client par namespace, decodage du texte JSON renvoye par callTool() (cf.
 * McpClientInterface, le "result" est une chaine, pas un tableau deja
 * structure), respect de page_argument/page_step fournis par l'appelant
 * plutot que devines, et le fait qu'une reponse non exploitable (JSON
 * invalide, champ items_field absent) echoue proprement au lieu de planter.
 */
class McpBulkSearchJobHandlerTest extends TestCase
{
    public function testUnknownNamespaceFailsTheJob(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'nope', 'tool_name' => 'x', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 10,
        ]);

        (new McpBulkSearchJobHandler([], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
        $this->assertStringContainsString('nope', (string) $job->getErrorMessage());
    }

    public function testUnconfiguredClientFailsTheJob(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(false);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'prospeo', 'tool_name' => 'search_person', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 10,
        ]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testHappyPathDecodesJsonTextAndPaginates(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(true);
        $client->expects($this->once())->method('callTool')->with(
            'search_person',
            $this->callback(static fn (array $args): bool => 'CEO' === $args['title'] && 1 === $args['page']),
        )->willReturn(['status' => 'ok', 'result' => json_encode(['results' => [['id' => 'p1'], ['id' => 'p2']]])]);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects($this->exactly(2))->method('persist');

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'prospeo', 'tool_name' => 'search_person', 'arguments' => ['title' => 'CEO'],
            'page_argument' => 'page', 'page_start' => 1, 'page_step' => 1, 'target_count' => 50, 'items_field' => 'results',
        ]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_QUEUED, $job->getStatus());
        $this->assertSame(2, $job->getResumeCursor()['page']);
        $this->assertSame(2, $job->getResumeCursor()['collected']);
    }

    public function testMcpErrorStatusFailsTheJob(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(true);
        $client->method('callTool')->willReturn(['status' => 'error', 'result' => 'invalid criteria']);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'prospeo', 'tool_name' => 'x', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 50,
        ]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
        $this->assertStringContainsString('invalid criteria', (string) $job->getErrorMessage());
    }

    public function testNonJsonResultFailsGracefully(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(true);
        $client->method('callTool')->willReturn(['status' => 'ok', 'result' => 'not json at all']);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'prospeo', 'tool_name' => 'x', 'page_argument' => 'page', 'items_field' => 'results', 'target_count' => 50,
        ]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_FAILED, $job->getStatus());
    }

    public function testEmptyItemsCompletesTheJob(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('datagouv');
        $client->method('isConfigured')->willReturn(true);
        $client->method('callTool')->willReturn(['status' => 'ok', 'result' => json_encode(['data' => []])]);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'datagouv', 'tool_name' => 'query_resource_data', 'page_argument' => 'offset', 'items_field' => 'data', 'target_count' => 1000,
        ]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);

        $this->assertSame(WittyBackgroundJob::STATUS_COMPLETED, $job->getStatus());
    }

    public function testResumesFromStoredCursorRatherThanPageStart(): void
    {
        $client = $this->createMock(McpClientInterface::class);
        $client->method('getNamespace')->willReturn('prospeo');
        $client->method('isConfigured')->willReturn(true);
        $client->expects($this->once())->method('callTool')->with(
            'x',
            $this->callback(static fn (array $args): bool => 7 === $args['page']),
        )->willReturn(['status' => 'ok', 'result' => json_encode(['results' => []])]);

        $em = $this->createMock(EntityManagerInterface::class);

        $job = (new WittyBackgroundJob())->setParams([
            'namespace' => 'prospeo', 'tool_name' => 'x', 'page_argument' => 'page', 'page_start' => 1, 'items_field' => 'results', 'target_count' => 50,
        ]);
        $job->setResumeCursor(['page' => 7, 'collected' => 30]);

        (new McpBulkSearchJobHandler([$client], $em))->processChunk($job);
    }
}
