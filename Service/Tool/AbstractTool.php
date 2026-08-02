<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

abstract class AbstractTool implements ToolInterface
{
    public function isWriteOperation(): bool
    {
        return false;
    }

    public function getRequiredPermission(): ?string
    {
        return null;
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    /**
     * @param array<string, mixed> $properties
     * @param array<int, string>   $required
     *
     * @return array<string, mixed>
     */
    protected function schema(array $properties, array $required = []): array
    {
        if ($this->isWriteOperation()) {
            $properties['confirmed'] = [
                'type'        => 'boolean',
                'description' => "Mettre a true uniquement apres que l'utilisateur a explicitement valide l'apercu.",
            ];
        }

        return [
            'type'                 => 'object',
            'properties'           => $properties,
            'required'             => $required,
            'additionalProperties' => false,
        ];
    }

    /**
     * @param array<string, mixed> $preview
     *
     * @return array<string, mixed>
     */
    protected function confirmationRequired(array $preview): array
    {
        return [
            'status'  => 'confirmation_required',
            'message' => "Presente cet apercu a l'utilisateur et attends son accord, puis rappelle le meme outil avec confirmed=true.",
            'preview' => $preview,
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    protected function ok(array $data): array
    {
        return ['status' => 'ok'] + $data;
    }
}
