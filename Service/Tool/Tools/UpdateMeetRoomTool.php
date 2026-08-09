<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Tag;
use Mautic\ProjectBundle\Entity\Project;
use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Service\Taxonomy\TaxonomyOptionsProvider;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Videoconference\RoomManager;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Modifie la categorie/les projets/les tags d'une salle plugNmeet existante.
 *
 * Trouve la WittyRoom (metadonnees Mautic) par room_id, ou en cree une si la
 * salle a ete ouverte avant que create_meet_room ne sache les renseigner (ou
 * directement cote plugNmeet, hors de l'agent) : sans cette entite, aucune
 * des trois classifications n'a d'endroit ou se rattacher.
 *
 * category_id/project_ids/tag_ids ne touchent que ce qui est explicitement
 * fourni : omettre un champ laisse la valeur actuelle intacte (contrairement
 * a le fournir vide, qui l'efface) — meme discipline que update_entity.
 */
class UpdateMeetRoomTool extends AbstractTool
{
    public function __construct(
        private RoomManager $rooms,
        private TaxonomyOptionsProvider $taxonomy,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_meet_room';
    }

    public function getDescription(): string
    {
        return "Modifie la categorie, les projets et/ou les tags d'une salle plugNmeet existante (room_id). "
            .'category_id=0 retire la categorie ; project_ids=[] ou tag_ids=[] retire tous les projets/tags. '
            .'Omettre un champ laisse sa valeur actuelle inchangee. project_ids et tag_ids remplacent '
            ."l'ensemble existant, ils ne s'ajoutent pas a la liste actuelle. "
            .'Voir list_entities(entity=category ou project) et manage_tags(action=list) pour les identifiants.';
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
            'room_id'     => ['type' => 'string', 'description' => 'Identifiant de la salle a modifier.'],
            'category_id' => ['type' => 'integer', 'description' => 'Nouvelle categorie (bundle meet_room). 0 pour retirer la categorie actuelle.'],
            'project_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => "Remplace l'ensemble des projets rattaches. [] pour tous les retirer."],
            'tag_ids'     => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => "Remplace l'ensemble des tags. [] pour tous les retirer."],
        ], ['room_id']);
    }

    public function execute(array $arguments): array
    {
        $roomId = trim((string) ($arguments['room_id'] ?? ''));

        if ('' === $roomId) {
            return ['status' => 'error', 'error' => 'room_id est obligatoire.'];
        }

        $hasCategory = array_key_exists('category_id', $arguments);
        $hasProjects = array_key_exists('project_ids', $arguments);
        $hasTags     = array_key_exists('tag_ids', $arguments);

        if (!$hasCategory && !$hasProjects && !$hasTags) {
            return ['status' => 'error', 'error' => 'Fournis au moins category_id, project_ids ou tag_ids.'];
        }

        $room = $this->rooms->findByRoomId($roomId);

        // La salle existe cote plugNmeet mais n'a jamais eu de metadonnees
        // Mautic (creee avant ce correctif, ou directement sur plugNmeet) :
        // on cree la ligne a la volee plutot que de renvoyer une erreur.
        if (null === $room) {
            $room = new WittyRoom();
            $room->setRoomId($roomId);
            $room->setTitle($roomId);
        }

        $preview = ['type' => 'meet_room', 'room_id' => $roomId];

        if ($hasCategory) {
            $categoryId              = (int) $arguments['category_id'];
            $preview['category_id']  = $categoryId > 0 ? $categoryId : null;
        }

        if ($hasProjects) {
            $preview['project_ids'] = array_map('intval', (array) $arguments['project_ids']);
        }

        if ($hasTags) {
            $preview['tag_ids'] = array_map('intval', (array) $arguments['tag_ids']);
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired($preview);
        }

        if ($hasCategory) {
            $categoryId = (int) $arguments['category_id'];
            $room->setCategory($this->taxonomy->resolveCategory($categoryId > 0 ? $categoryId : null));
        }

        if ($hasProjects) {
            $room->setProjects($this->taxonomy->resolveProjects(array_map('intval', (array) $arguments['project_ids'])));
        }

        if ($hasTags) {
            $room->setTags($this->taxonomy->resolveTags(array_map('intval', (array) $arguments['tag_ids'])));
        }

        $this->rooms->save($room);

        return $this->ok([
            'room_id'     => $roomId,
            'category_id' => $room->getCategory()?->getId(),
            'project_ids' => array_values(array_map(static fn (Project $p): ?int => $p->getId(), $room->getProjects()->toArray())),
            'tag_ids'     => array_values(array_map(static fn (Tag $t): int|string|null => $t->getId(), $room->getTags()->toArray())),
        ]);
    }
}
