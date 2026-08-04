<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateMeetRoomTool extends AbstractTool
{
    public function __construct(
        private PlugNmeetClient $client,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_meet_room';
    }

    public function getDescription(): string
    {
        return 'Cree une salle de visioconference plugNmeet. La salle reste ouverte indefiniment '
            .'jusqu a fin explicite (end_meet_room), pas de fermeture automatique quand elle se vide.';
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
            'room_id' => ['type' => 'string', 'description' => 'Identifiant unique de la salle (pas d espaces, ex. webinar-juin-2026).'],
            'title'   => ['type' => 'string', 'description' => 'Titre affiche aux participants.'],
            'listeners_locked' => [
                'type'        => 'boolean',
                'description' => 'Si true, seuls les admins/presentateurs ont micro et webcam ; tous les autres restent verrouilles pour toute la reunion. Defaut false.',
            ],
        ], ['room_id']);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));
        $title  = trim((string) ($arguments['title'] ?? ''));

        if ('' === $roomId) {
            return ['status' => 'error', 'error' => 'room_id est obligatoire.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'             => 'meet_room',
                'room_id'          => $roomId,
                'title'            => '' !== $title ? $title : $roomId,
                'listeners_locked' => (bool) ($arguments['listeners_locked'] ?? false),
            ]);
        }

        try {
            $this->client->createRoom($roomId, [
                'title'            => $title,
                'listeners_locked' => (bool) ($arguments['listeners_locked'] ?? false),
            ]);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'room_id' => $roomId,
            'title'   => '' !== $title ? $title : $roomId,
            'url'     => '/s/witty/video/rooms',
        ]);
    }
}
