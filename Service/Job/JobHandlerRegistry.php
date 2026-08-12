<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job;

/**
 * Indexe tous les JobHandlerInterface taggues (cf. Config/services.php) par
 * leur type, pour que Command/ProcessBackgroundJobsCommand.php retrouve le
 * bon handler a partir de WittyBackgroundJob::getType() sans connaitre la
 * liste des types possibles a la compilation — meme role que ToolRegistry
 * pour les outils, en plus simple (pas de permissions/audit a filtrer ici,
 * un job de fond n'est jamais declenche directement par le modele).
 */
class JobHandlerRegistry
{
    /** @var array<string, JobHandlerInterface> */
    private array $byType = [];

    /**
     * @param iterable<JobHandlerInterface> $handlers
     */
    public function __construct(iterable $handlers)
    {
        foreach ($handlers as $handler) {
            $this->byType[$handler->getType()] = $handler;
        }
    }

    public function get(string $type): ?JobHandlerInterface
    {
        return $this->byType[$type] ?? null;
    }

    /**
     * @return string[]
     */
    public function types(): array
    {
        return array_keys($this->byType);
    }
}
