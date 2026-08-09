<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Taxonomy\TaxonomyOptionsProvider;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Videoconference\RoomManager;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateMeetRoomTool extends AbstractTool
{
    public function __construct(
        private PlugNmeetClient $client,
        private RoomManager $rooms,
        private TaxonomyOptionsProvider $taxonomy,
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
            .'jusqu a fin explicite (end_meet_room), pas de fermeture automatique quand elle se vide. '
            .'category_id/project_ids/tag_ids classent la salle cote Mautic (voir list_entities(entity=category), '
            .'list_entities(entity=project), manage_tags(action=list) pour recuperer des identifiants existants) ; '
            .'modifiables ensuite avec update_meet_room.';
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
            'category_id' => ['type' => 'integer', 'description' => 'Categorie Mautic (bundle meet_room, voir create_category).'],
            'project_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Projets Mautic auxquels rattacher la salle.'],
            'tag_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Tags a poser sur la salle (identifiants existants, voir manage_tags action=list).'],
        ], ['room_id']);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));
        $title  = trim((string) ($arguments['title'] ?? ''));

        if ('' === $roomId) {
            return ['status' => 'error', 'error' => 'room_id est obligatoire.'];
        }

        $categoryId = (int) ($arguments['category_id'] ?? 0);
        $projectIds = array_map('intval', (array) ($arguments['project_ids'] ?? []));
        $tagIds     = array_map('intval', (array) ($arguments['tag_ids'] ?? []));

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'             => 'meet_room',
                'room_id'          => $roomId,
                'title'            => '' !== $title ? $title : $roomId,
                'listeners_locked' => (bool) ($arguments['listeners_locked'] ?? false),
                'category_id'      => $categoryId > 0 ? $categoryId : null,
                'project_ids'      => $projectIds,
                'tag_ids'          => $tagIds,
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

        // Sans cette entite, la salle n'existe que cote plugNmeet : ni
        // categorie, ni projet, ni tag, ni proprietaire cote Mautic. Meme
        // sequence que VideoconferenceController::roomsCreateAction() (creation
        // manuelle depuis l'interface).
        $room = new WittyRoom();
        $room->setRoomId($roomId);
        $room->setTitle('' !== $title ? $title : $roomId);
        $room->setCategory($this->taxonomy->resolveCategory($categoryId > 0 ? $categoryId : null));
        $room->setProjects($this->taxonomy->resolveProjects($projectIds));
        $room->setTags($this->taxonomy->resolveTags($tagIds));
        $this->rooms->save($room);

        return $this->ok([
            'room_id' => $roomId,
            'title'   => '' !== $title ? $title : $roomId,
            'url'     => '/s/witty/video/rooms',
        ]);
    }
}
