<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

class ListPastMeetRoomsTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'list_past_meet_rooms';
    }

    public function getDescription(): string
    {
        return 'Liste l historique des salles plugNmeet terminees (titre, identifiant, debut, fin).';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'limit' => ['type' => 'integer', 'description' => 'Defaut 20, max 100.'],
        ]);
    }

    public function execute(array $arguments): array
    {
        $limit = max(1, min(100, (int) ($arguments['limit'] ?? 20)));

        try {
            $data = $this->client->fetchPastRooms([], 0, $limit);
        } catch (PlugNmeetException $e) {
            return $this->ok(['count' => 0, 'rooms' => [], 'note' => $e->getMessage()]);
        }

        $rooms = [];

        foreach ((array) ($data['result']['rooms_list'] ?? []) as $room) {
            $rooms[] = [
                'room_id' => (string) ($room['room_id'] ?? ''),
                'title'   => (string) ($room['room_title'] ?? ''),
                'started' => (string) ($room['created'] ?? ''),
                'ended'   => (string) ($room['ended'] ?? ''),
            ];
        }

        return $this->ok([
            'total' => (int) ($data['result']['total_rooms'] ?? count($rooms)),
            'count' => count($rooms),
            'rooms' => $rooms,
        ]);
    }
}
