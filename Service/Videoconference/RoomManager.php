<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Videoconference;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Entity\WittyRoomRepository;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;

/**
 * Metadonnees cote Mautic pour les salles plugNmeet (createdBy/categorie/
 * projets, cf. Entity/WittyRoom.php) + calcul du nombre total de
 * participants ayant rejoint une salle, toutes sessions confondues.
 */
class RoomManager
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private PlugNmeetClient $plugNmeetClient,
    ) {
    }

    public function find(int $id): ?WittyRoom
    {
        return $this->getRepository()->find($id);
    }

    public function findByRoomId(string $roomId): ?WittyRoom
    {
        return $this->getRepository()->findOneByRoomId($roomId);
    }

    /**
     * @param string[] $roomIds
     *
     * @return array<string, WittyRoom>
     */
    public function findByRoomIds(array $roomIds): array
    {
        return $this->getRepository()->findByRoomIds($roomIds);
    }

    public function save(WittyRoom $room): void
    {
        $isNew = null === $room->getId();

        if ($isNew) {
            $user = $this->userHelper->getUser();

            if (null !== $user && null !== $user->getId()) {
                $room->setCreatedBy($user);
            }
        } else {
            $room->touch();
        }

        $this->entityManager->persist($room);
        $this->entityManager->flush();
    }

    /**
     * Somme les participants de toutes les sessions passees de cette salle
     * + la session en cours si elle est active : plugNmeet reutilise le meme
     * room_id a travers plusieurs sessions successives (cf. Past rooms), le
     * compteur "joined_participants" d'une seule session ne represente donc
     * qu'une fraction du total.
     */
    public function totalParticipants(string $roomId, int $liveParticipants = 0): int
    {
        $total = max(0, $liveParticipants);

        try {
            $result = $this->plugNmeetClient->fetchPastRooms([$roomId], 0, 100);
        } catch (PlugNmeetException) {
            return $total;
        }

        foreach ($result['result']['rooms_list'] ?? [] as $session) {
            $total += (int) ($session['joined_participants'] ?? 0);
        }

        return $total;
    }

    private function getRepository(): WittyRoomRepository
    {
        /** @var WittyRoomRepository $repository */
        $repository = $this->entityManager->getRepository(WittyRoom::class);

        return $repository;
    }
}
