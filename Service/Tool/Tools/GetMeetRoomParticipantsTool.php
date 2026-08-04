<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

class GetMeetRoomParticipantsTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'get_meet_room_participants';
    }

    public function getDescription(): string
    {
        return 'Liste les participants actuellement connectes a une salle plugNmeet active (nom, role).';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'room_id' => ['type' => 'string', 'description' => 'Identifiant de la salle.'],
        ], ['room_id']);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));

        if ('' === $roomId) {
            return ['status' => 'error', 'error' => 'room_id est obligatoire.'];
        }

        try {
            $data = $this->client->getActiveRoomInfo($roomId);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $participants = [];

        foreach ((array) ($data['room']['participants_info'] ?? []) as $participant) {
            $participants[] = [
                'name'     => (string) ($participant['name'] ?? ''),
                'is_admin' => (bool) ($participant['is_admin'] ?? false),
            ];
        }

        return $this->ok([
            'room_id'      => $roomId,
            'title'        => (string) ($data['room']['room_info']['room_title'] ?? $roomId),
            'count'        => count($participants),
            'participants' => $participants,
        ]);
    }
}
