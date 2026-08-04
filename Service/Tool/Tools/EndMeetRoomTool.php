<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Termine une salle active : deconnecte tout le monde immediatement.
 *
 * Suite logique cote presence : la reconciliation (witty:meet:reconcile-attendance)
 * n'a de donnees a comparer qu une fois l analyse plugNmeet generee apres la fin
 * de la salle, avec un delai variable, pas immediat.
 */
class EndMeetRoomTool extends AbstractTool
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function getName(): string
    {
        return 'end_meet_room';
    }

    public function getDescription(): string
    {
        return 'Termine une salle plugNmeet active, deconnectant immediatement tous les participants. Irreversible.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'meet_room';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'room_id' => ['type' => 'string', 'description' => 'Identifiant de la salle a terminer.'],
        ], ['room_id']);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));

        if ('' === $roomId) {
            return ['status' => 'error', 'error' => 'room_id est obligatoire.'];
        }

        // Suppression definitive de son point de vue (tout le monde deconnecte
        // sur le champ) : confirmation exigee meme si le mode global est
        // desactive, comme delete_entity.
        if (true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'          => 'meet_room',
                'room_id'       => $roomId,
                'irreversible'  => true,
                'avertissement' => 'Tous les participants seront deconnectes immediatement.',
            ]);
        }

        try {
            $this->client->endRoom($roomId);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok(['room_id' => $roomId, 'ended' => true]);
    }
}
