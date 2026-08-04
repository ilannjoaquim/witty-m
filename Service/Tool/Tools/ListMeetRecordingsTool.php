<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Recordings et artefacts (analyses, exports...) sont deux catalogues distincts
 * cote plugNmeet ; on les expose separement pour ne pas melanger une video a
 * telecharger et un fichier d analyse JSON.
 */
class ListMeetRecordingsTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'list_meet_recordings';
    }

    public function getDescription(): string
    {
        return 'Liste les enregistrements video et/ou les artefacts (analyses, exports) des salles plugNmeet. '
            .'Utiliser convert_meet_recording_to_asset pour rendre un enregistrement partageable par email.';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type' => [
                'type'        => 'string',
                'enum'        => ['recordings', 'artifacts', 'both'],
                'description' => 'Defaut both.',
            ],
            'limit' => ['type' => 'integer', 'description' => 'Defaut 20, max 100.'],
        ]);
    }

    public function execute(array $arguments): array
    {
        $type  = (string) ($arguments['type'] ?? 'both');
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));

        $result = [];

        if ('recordings' === $type || 'both' === $type) {
            $result['recordings'] = $this->safeList(
                fn () => $this->client->fetchRecordings([], 0, $limit)['result']['recordings_list'] ?? [],
                ['record_id', 'room_id', 'creation_time', 'file_size'],
            );
        }

        if ('artifacts' === $type || 'both' === $type) {
            $result['artifacts'] = $this->safeList(
                fn () => $this->client->fetchArtifacts([], 0, $limit)['result']['artifacts_list'] ?? [],
                ['artifact_id', 'room_id', 'type', 'created'],
            );
        }

        return $this->ok($result);
    }

    /**
     * @param callable(): array<int, array<string, mixed>> $fetch
     * @param array<int, string>                           $keys
     *
     * @return array<int, array<string, mixed>>
     */
    private function safeList(callable $fetch, array $keys): array
    {
        try {
            $items = (array) $fetch();
        } catch (PlugNmeetException) {
            return [];
        }

        $filtered = [];

        foreach ($items as $item) {
            $entry = [];

            foreach ($keys as $key) {
                if (isset($item[$key])) {
                    $entry[$key] = $item[$key];
                }
            }

            $filtered[] = $entry;
        }

        return $filtered;
    }
}
