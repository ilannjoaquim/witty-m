<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

interface ToolInterface
{
    /** Nom expose au modele (snake_case). */
    public function getName(): string;

    public function getDescription(): string;

    /**
     * JSON Schema des arguments.
     *
     * @return array<string, mixed>
     */
    public function getSchema(): array;

    /** true si l'outil ecrit en base (declenche le flux de confirmation). */
    public function isWriteOperation(): bool;

    /** Permission Mautic requise, ex. 'lead:lists:create'. null = aucune. */
    public function getRequiredPermission(): ?string;

    /**
     * @param array<string, mixed> $arguments
     *
     * @return array<string, mixed> resultat serialisable renvoye au modele
     */
    public function execute(array $arguments): array;
}
