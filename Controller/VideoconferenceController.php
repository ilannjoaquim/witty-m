<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\PlugNmeet\RecordingToAssetConverter;
use MauticPlugin\WittyBundle\Service\Taxonomy\TaxonomyOptionsProvider;
use MauticPlugin\WittyBundle\Service\Videoconference\RoomManager;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Section "Videoconference" : pilote une instance plugNmeet depuis Mautic
 * (salles, historique, enregistrements) sans jamais exposer la cle API ni le
 * secret HMAC au navigateur — tout passe par ce controleur cote serveur.
 */
class VideoconferenceController extends CommonController
{
    public function roomsAction(WittyConfig $config): Response
    {
        return $this->page('rooms', '@Witty/Videoconference/Rooms.html.twig', $config);
    }

    public function pastRoomsAction(WittyConfig $config): Response
    {
        return $this->page('past_rooms', '@Witty/Videoconference/PastRooms.html.twig', $config);
    }

    public function recordingsAction(WittyConfig $config): Response
    {
        return $this->page('recordings', '@Witty/Videoconference/Recordings.html.twig', $config);
    }

    private function page(string $section, string $template, WittyConfig $config): Response
    {
        return $this->delegateView([
            'viewParameters' => [
                'isConfigured' => $config->isPlugNmeetConfigured(),
            ],
            'contentTemplate' => $template,
            'passthroughVars' => [
                'activeLink'    => '#witty_video_'.$section,
                'mauticContent' => 'wittyVideo'.$section,
                'route'         => $this->generateUrl('witty_video_'.$section),
            ],
        ]);
    }

    // ---- Salles ----

    public function roomsDataAction(PlugNmeetClient $client, RoomManager $rooms, TaxonomyOptionsProvider $taxonomy): JsonResponse
    {
        // safeFetch() : plugNmeet renvoie status:false ("no active room
        // found") comme reponse normale pour une liste vide, pas comme une
        // vraie erreur (cf. recordingsDataAction plus bas).
        $activeRooms = $this->safeFetch(fn () => $client->getActiveRoomsInfo())['rooms'] ?? [];

        $roomIds = array_values(array_filter(array_map(
            static fn (array $entry): string => (string) ($entry['room_info']['room_id'] ?? ''),
            $activeRooms,
        )));

        $localRooms = $rooms->findByRoomIds($roomIds);

        $enriched = array_map(function (array $entry) use ($rooms, $localRooms): array {
            $info   = $entry['room_info'] ?? [];
            $roomId = (string) ($info['room_id'] ?? '');
            $local  = $localRooms[$roomId] ?? null;

            $entry['witty'] = [
                'category'          => $local?->getCategory()?->getTitle(),
                'projects'          => $local ? array_values(array_map(
                    static fn ($project): string => (string) $project->getName(),
                    $local->getProjects()->toArray(),
                )) : [],
                'createdBy'         => $local?->getCreatedBy()?->getName(),
                'totalParticipants' => $rooms->totalParticipants($roomId, (int) ($info['joined_participants'] ?? 0)),
            ];

            return $entry;
        }, $activeRooms);

        return new JsonResponse([
            'status'  => true,
            'rooms'   => $enriched,
            'options' => [
                'categories' => $taxonomy->categoryChoices('witty_room'),
                'projects'   => $taxonomy->projectChoices(),
            ],
        ]);
    }

    public function roomsCreateAction(Request $request, PlugNmeetClient $client, RoomManager $rooms, TaxonomyOptionsProvider $taxonomy): JsonResponse
    {
        $roomId     = trim((string) $request->request->get('room_id', ''));
        $title      = trim((string) $request->request->get('title', ''));
        $categoryId = (int) $request->request->get('category_id', 0);
        $projectIds = (array) $request->request->all('project_ids');

        if ('' === $roomId) {
            return new JsonResponse(['status' => false, 'msg' => 'room_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        $lockSettings = [];

        foreach ((array) $request->request->all('lock') as $key => $value) {
            $lockSettings[$key] = (bool) $value;
        }

        try {
            $result = $client->createRoom($roomId, [
                'title'    => $title,
                'metadata' => [] !== $lockSettings ? ['default_lock_settings' => $lockSettings] : [],
            ]);
        } catch (PlugNmeetException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }

        $room = new WittyRoom();
        $room->setRoomId($roomId);
        $room->setTitle($title);
        $room->setCategory($taxonomy->resolveCategory($categoryId > 0 ? $categoryId : null));
        $room->setProjects($taxonomy->resolveProjects($projectIds));
        $rooms->save($room);

        return new JsonResponse(['status' => true] + $result);
    }

    public function roomsEndAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $roomId = trim((string) $request->request->get('room_id', ''));

        if ('' === $roomId) {
            return new JsonResponse(['status' => false, 'msg' => 'room_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->call(fn () => $client->endRoom($roomId));
    }

    public function roomsDetailsAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $roomId = trim((string) $request->query->get('room_id', ''));

        if ('' === $roomId) {
            return new JsonResponse(['status' => false, 'msg' => 'room_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->call(fn () => $client->getActiveRoomInfo($roomId));
    }

    public function roomsLinkAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $roomId  = trim((string) $request->request->get('room_id', ''));
        $name    = trim((string) $request->request->get('name', ''));
        $isAdmin = (bool) $request->request->get('is_admin', false);

        if ('' === $roomId || '' === $name) {
            return new JsonResponse(['status' => false, 'msg' => 'room_id et name sont obligatoires.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->call(function () use ($client, $roomId, $name, $isAdmin) {
            $data = $client->getJoinToken($roomId, [
                'name'     => $name,
                'user_id'  => 'mautic-'.substr(md5($name.microtime()), 0, 8),
                'is_admin' => $isAdmin,
            ]);

            return $data + ['join_url' => $client->buildJoinUrl((string) ($data['token'] ?? ''))];
        });
    }

    // ---- Salles passees ----

    public function pastRoomsDataAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $from = max(0, (int) $request->query->get('from', 0));

        // Meme quirk plugNmeet que roomsDataAction() : liste vide = status:false.
        return new JsonResponse(['status' => true] + $this->safeFetch(fn () => $client->fetchPastRooms([], $from, 50)));
    }

    // ---- Enregistrements et artefacts ----

    public function recordingsDataAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $from = max(0, (int) $request->query->get('from', 0));

        // Recus separement : plugNmeet renvoie une erreur (pas juste une liste
        // vide) quand il n'y a aucun enregistrement, ce qui masquerait les
        // artefacts si les deux etaient recuperes dans le meme appel groupe.
        return new JsonResponse([
            'status'     => true,
            'recordings' => $this->safeFetch(fn () => $client->fetchRecordings([], $from, 50)['result'] ?? []),
            'artifacts'  => $this->safeFetch(fn () => $client->fetchArtifacts([], $from, 50)['result'] ?? []),
        ]);
    }

    /**
     * @param callable(): array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    private function safeFetch(callable $call): array
    {
        try {
            return $call();
        } catch (PlugNmeetException) {
            return [];
        }
    }

    public function recordingsDeleteAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $recordId = trim((string) $request->request->get('record_id', ''));

        if ('' === $recordId) {
            return new JsonResponse(['status' => false, 'msg' => 'record_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->call(fn () => $client->deleteRecording($recordId));
    }

    public function recordingsDownloadAction(Request $request, PlugNmeetClient $client): Response
    {
        $recordId = trim((string) $request->query->get('record_id', ''));

        if ('' === $recordId) {
            return new JsonResponse(['status' => false, 'msg' => 'record_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new RedirectResponse($client->getRecordingDownloadUrl($recordId));
        } catch (PlugNmeetException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * Telecharge l'enregistrement depuis plugNmeet et le republie comme Asset
     * Mautic (stockage local) : l'objectif est de pouvoir le joindre/partager
     * depuis un email, ce que l'URL de telechargement plugNmeet (a jeton, courte
     * duree de vie) ne permet pas.
     */
    public function recordingsToAssetAction(Request $request, RecordingToAssetConverter $converter): JsonResponse
    {
        $recordId = trim((string) $request->request->get('record_id', ''));
        $title    = trim((string) $request->request->get('title', ''));

        if ('' === $recordId) {
            return new JsonResponse(['status' => false, 'msg' => 'record_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $asset = $converter->convert($recordId, $title);

            return new JsonResponse([
                'status' => true,
                'asset'  => ['id' => $asset->getId(), 'title' => $asset->getTitle(), 'url' => '/s/assets/edit/'.$asset->getId()],
            ]);
        } catch (PlugNmeetException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    public function artifactsDeleteAction(Request $request, PlugNmeetClient $client): JsonResponse
    {
        $artifactId = trim((string) $request->request->get('artifact_id', ''));

        if ('' === $artifactId) {
            return new JsonResponse(['status' => false, 'msg' => 'artifact_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        return $this->call(fn () => $client->deleteArtifact($artifactId));
    }

    public function artifactsDownloadAction(Request $request, PlugNmeetClient $client): Response
    {
        $artifactId = trim((string) $request->query->get('artifact_id', ''));

        if ('' === $artifactId) {
            return new JsonResponse(['status' => false, 'msg' => 'artifact_id est obligatoire.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            return new RedirectResponse($client->getArtifactDownloadUrl($artifactId));
        } catch (PlugNmeetException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }

    /**
     * @param callable(): array<string, mixed> $call
     */
    private function call(callable $call): JsonResponse
    {
        try {
            return new JsonResponse(['status' => true] + $call());
        } catch (PlugNmeetException $e) {
            return new JsonResponse(['status' => false, 'msg' => $e->getMessage()], Response::HTTP_BAD_GATEWAY);
        }
    }
}
