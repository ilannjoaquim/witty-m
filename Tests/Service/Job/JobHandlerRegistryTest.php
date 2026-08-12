<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\Service\Job;

use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerInterface;
use MauticPlugin\WittyBundle\Service\Job\JobHandlerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * Meme role que ToolRegistry pour les outils : indexer par type, retourner
 * null (jamais une exception) sur un type inconnu, pour que
 * Command/ProcessBackgroundJobsCommand.php puisse gerer proprement un job
 * dont le handler a disparu (plugin desinstalle, regression) plutot que de
 * planter.
 */
class JobHandlerRegistryTest extends TestCase
{
    public function testResolvesHandlerByType(): void
    {
        $handlerA = $this->handler('type_a');
        $handlerB = $this->handler('type_b');

        $registry = new JobHandlerRegistry([$handlerA, $handlerB]);

        $this->assertSame($handlerA, $registry->get('type_a'));
        $this->assertSame($handlerB, $registry->get('type_b'));
    }

    public function testUnknownTypeReturnsNullRatherThanThrowing(): void
    {
        $registry = new JobHandlerRegistry([$this->handler('type_a')]);

        $this->assertNull($registry->get('unknown'));
    }

    public function testTypesListsAllRegisteredHandlers(): void
    {
        $registry = new JobHandlerRegistry([$this->handler('type_a'), $this->handler('type_b')]);

        $this->assertSame(['type_a', 'type_b'], $registry->types());
    }

    private function handler(string $type): JobHandlerInterface
    {
        return new class($type) implements JobHandlerInterface {
            public function __construct(private string $type) {}
            public function getType(): string { return $this->type; }
            public function processChunk(WittyBackgroundJob $job): void {}
        };
    }
}
