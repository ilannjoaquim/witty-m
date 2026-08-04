<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

class DeleteMeetRecordingTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'delete_meet_recording';
    }

    public function getDescription(): string
    {
        return 'Supprime definitivement un enregistrement ou un artefact plugNmeet. Irreversible.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'meet_recording';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type' => ['type' => 'string', 'enum' => ['recording', 'artifact']],
            'id'   => ['type' => 'string', 'description' => 'record_id ou artifact_id selon le type.'],
        ], ['type', 'id']);
    }

    public function execute(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $id   = trim((string) ($arguments['id'] ?? ''));

        if (!in_array($type, ['recording', 'artifact'], true) || '' === $id) {
            return ['status' => 'error', 'error' => 'type (recording ou artifact) et id sont obligatoires.'];
        }

        // Pas de rattrapage possible : confirmation exigee meme si le mode
        // global est desactive, comme delete_entity.
        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => $type,
                'id'            => $id,
                'irreversible'  => true,
                'avertissement' => 'Cette suppression est definitive.',
            ]);
        }

        try {
            if ('recording' === $type) {
                $this->client->deleteRecording($id);
            } else {
                $this->client->deleteArtifact($id);
            }
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok(['type' => $type, 'id' => $id, 'deleted' => true]);
    }
}
