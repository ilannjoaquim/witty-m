<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * plugNmeet renvoie une erreur (pas juste une liste vide) quand aucune salle
 * n'est active : on la traite comme un etat normal, pas comme un echec.
 */
class ListActiveRoomsTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'list_active_meet_rooms';
    }

    public function getDescription(): string
    {
        return 'Liste les salles plugNmeet actuellement actives (titre, identifiant, nombre de participants).';
    }

    public function getSchema(): array
    {
        return $this->schema([]);
    }

    public function execute(array $arguments): array
    {
        try {
            $data = $this->client->getActiveRoomsInfo();
        } catch (PlugNmeetException $e) {
            return $this->ok(['count' => 0, 'rooms' => [], 'note' => $e->getMessage()]);
        }

        $rooms = [];

        foreach ((array) ($data['rooms'] ?? []) as $entry) {
            $info = (array) ($entry['room_info'] ?? []);

            if ('' === (string) ($info['room_id'] ?? '')) {
                continue;
            }

            $rooms[] = [
                'room_id'      => (string) $info['room_id'],
                'title'        => (string) ($info['room_title'] ?? $info['room_id']),
                'participants' => (int) ($info['joined_participants'] ?? 0),
            ];
        }

        return $this->ok(['count' => count($rooms), 'rooms' => $rooms]);
    }
}
