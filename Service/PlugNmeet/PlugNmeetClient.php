<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\PlugNmeet;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client PHP de l'API REST plugNmeet (portage de PlugNMeetClient.js).
 *
 * Authentification par HMAC : chaque appel signe le corps JSON avec la cle
 * secrete (jamais transmise), envoyee en en-tete HASH-SIGNATURE aux cotes de
 * API-KEY. Voir https://www.plugnmeet.org — routes sous /auth/...
 */
class PlugNmeetClient
{
    /**
     * plugNmeet-server ferme une salle vide apres ce delai (secondes). Repli a
     * effectivement "jamais" : une salle ne doit se terminer que sur action
     * explicite (endRoom), pas parce que tout le monde est momentanement sorti.
     */
    private const NEVER_AUTO_CLOSE_EMPTY_TIMEOUT = 31536000;

    /**
     * @var array<string, mixed>
     */
    private const DEFAULT_ROOM_METADATA = [
        'room_title'      => 'Nouvelle salle',
        'welcome_message' => 'Bienvenue dans la salle',
        'room_features'   => [
            'allow_webcams'               => true,
            'mute_on_start'               => false,
            'allow_screen_share'          => true,
            'admin_only_webcams'          => false,
            'allow_view_other_webcams'    => true,
            'allow_view_other_users_list' => true,
            'enable_analytics'            => true,
            'allow_virtual_bg'            => true,
            'allow_raise_hand'            => true,
            'auto_gen_user_id'            => false,
            'room_duration'               => 0,
            'recording_features'         => [
                'is_allow'                    => true,
                'is_allow_cloud'              => true,
                'is_allow_local'              => true,
                'enable_auto_cloud_recording' => false,
                'only_record_admin_webcams'   => false,
                'recorder_bot_options'        => ['enable_auto_close_chat_panel' => true, 'duration_after_last_message' => 10],
            ],
            'chat_features'                    => ['is_allow' => true, 'is_allow_file_upload' => true],
            'shared_note_pad_features'          => ['is_allow' => true],
            'whiteboard_features'               => ['is_allow' => true],
            'external_media_player_features'    => ['is_allow' => true],
            'external_broadcasting_features'    => [
                'is_allow'      => true,
                'is_allow_rtmp' => true,
                'recorder_bot_options' => ['enable_auto_close_chat_panel' => true, 'duration_after_last_message' => 10],
            ],
            'waiting_room_features'   => ['is_active' => false],
            'breakout_room_features'  => ['is_allow' => true, 'allowed_number_rooms' => 6],
            'display_external_link_features' => ['is_allow' => true],
            'ingress_features'        => ['is_allow' => true],
            'polls_features'          => ['is_allow' => true],
            'insights_features'       => [
                'is_allow'                  => false,
                'transcription_features'    => ['is_allow' => false, 'is_allow_translation' => false, 'is_allow_speech_synthesis' => false],
                'chat_translation_features' => ['is_allow' => false],
                'ai_features'                => [
                    'is_allow' => false,
                    'ai_text_chat_features' => ['is_allow' => false],
                    'meeting_summarization_features' => ['is_allow' => false],
                ],
            ],
            'sip_dial_in_features' => ['is_allow' => false, 'enable_dial_in_on_create' => false, 'hide_phone_number' => false],
            'end_to_end_encryption_features' => [
                'is_enabled'                          => false,
                'enabled_self_insert_encryption_key' => false,
                'included_chat_messages'              => false,
                'included_whiteboard'                 => false,
            ],
        ],
        'default_lock_settings' => [
            'lock_microphone'        => false,
            'lock_webcam'            => false,
            'lock_screen_sharing'    => true,
            'lock_whiteboard'        => true,
            'lock_shared_notepad'    => true,
            'lock_chat'              => false,
            'lock_chat_send_message' => false,
            'lock_chat_file_share'   => false,
            'lock_private_chat'      => false,
        ],
        'copyright_conf' => [
            'display' => true,
            'text'    => 'Powered by <a href="https://www.plugnmeet.org" target="_blank">plugNmeet</a>',
        ],
        'extra_data' => [],
    ];

    public function __construct(
        private WittyConfig $config,
        private HttpClientInterface $httpClient,
    ) {
    }

    // ---- Salles ----

    /**
     * @param array{title?: string, listeners_locked?: bool, max_participants?: int, metadata?: array<string, mixed>} $options
     *
     * @return array<string, mixed>
     */
    public function createRoom(string $roomId, array $options = []): array
    {
        $metadata = self::DEFAULT_ROOM_METADATA;

        if ('' !== ($options['title'] ?? '')) {
            $metadata['room_title'] = $options['title'];
        }

        if (true === ($options['listeners_locked'] ?? false)) {
            // Seuls admins/presentateurs recuperent micro et webcam : tous les
            // autres restent verrouilles pour toute la reunion, pas juste a l'entree.
            $metadata = $this->mergeDeep($metadata, [
                'default_lock_settings' => ['lock_microphone' => true, 'lock_webcam' => true],
            ]);
        }

        if (isset($options['metadata']) && is_array($options['metadata'])) {
            $metadata = $this->mergeDeep($metadata, $options['metadata']);
        }

        // PHP encode un tableau vide en JSON [] ; le serveur plugNmeet attend un
        // objet {} ici (cote protobuf) et rejette la requete entiere sinon
        // ("proto: syntax error ... unexpected token [").
        if ([] === $metadata['extra_data']) {
            $metadata['extra_data'] = new \stdClass();
        }

        $body = ['room_id' => $roomId, 'metadata' => $metadata];

        if (isset($options['max_participants'])) {
            $body['max_participants'] = $options['max_participants'];
        }

        $body['empty_timeout'] = $options['empty_timeout'] ?? self::NEVER_AUTO_CLOSE_EMPTY_TIMEOUT;

        return $this->request('/room/create', $body);
    }

    /**
     * @param array{name: string, user_id?: string, is_admin?: bool} $userInfo
     *
     * @return array<string, mixed>
     */
    public function getJoinToken(string $roomId, array $userInfo): array
    {
        return $this->request('/room/getJoinToken', ['room_id' => $roomId, 'user_info' => $userInfo]);
    }

    public function buildJoinUrl(string $token): string
    {
        $separator = str_contains($this->config->getPlugNmeetServerUrl(), '?') ? '&' : '?';

        return $this->config->getPlugNmeetServerUrl().$separator.'access_token='.rawurlencode($token);
    }

    /**
     * @return array<string, mixed>
     */
    public function isRoomActive(string $roomId): array
    {
        return $this->request('/room/isRoomActive', ['room_id' => $roomId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getActiveRoomInfo(string $roomId): array
    {
        return $this->request('/room/getActiveRoomInfo', ['room_id' => $roomId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getActiveRoomsInfo(): array
    {
        return $this->request('/room/getActiveRoomsInfo', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function endRoom(string $roomId): array
    {
        return $this->request('/room/endRoom', ['room_id' => $roomId]);
    }

    /**
     * @param array<int, string> $roomIds Vide = toutes les salles.
     *
     * @return array<string, mixed>
     */
    public function fetchPastRooms(array $roomIds = [], int $from = 0, int $limit = 20, string $orderBy = 'DESC'): array
    {
        return $this->request('/room/fetchPastRooms', [
            'room_ids' => $roomIds,
            'from'     => $from,
            'limit'    => $limit,
            'order_by' => $orderBy,
        ]);
    }

    // ---- Enregistrements ----

    /**
     * @param array<int, string> $roomIds Vide = toutes les salles.
     *
     * @return array<string, mixed>
     */
    public function fetchRecordings(array $roomIds = [], int $from = 0, int $limit = 20, string $orderBy = 'DESC'): array
    {
        return $this->request('/recording/fetch', [
            'room_ids' => $roomIds,
            'from'     => $from,
            'limit'    => $limit,
            'order_by' => $orderBy,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getRecordingInfo(string $recordId): array
    {
        return $this->request('/recording/info', ['record_id' => $recordId]);
    }

    public function getRecordingDownloadUrl(string $recordId): string
    {
        $data = $this->request('/recording/getDownloadToken', ['record_id' => $recordId]);

        return rtrim($this->config->getPlugNmeetServerUrl(), '/').'/download/recording/'.(string) ($data['token'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRecording(string $recordId): array
    {
        return $this->request('/recording/delete', ['record_id' => $recordId]);
    }

    /**
     * Telecharge un enregistrement vers un fichier local, par flux (les
     * enregistrements peuvent peser plusieurs Go : hors de question de les
     * charger entierement en memoire).
     *
     * @return array{content_type: ?string, size: int}
     */
    public function downloadRecordingToFile(string $recordId, string $destinationPath): array
    {
        $url = $this->getRecordingDownloadUrl($recordId);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 300, 'buffer' => false]);

            $status = $response->getStatusCode();

            if ($status >= 400) {
                throw new PlugNmeetException(sprintf('Telechargement de l enregistrement echoue (HTTP %d).', $status));
            }

            $contentType = $response->getHeaders(false)['content-type'][0] ?? null;
            $size        = 0;

            $handle = fopen($destinationPath, 'wb');

            if (false === $handle) {
                throw new PlugNmeetException(sprintf('Impossible d ecrire le fichier temporaire %s.', $destinationPath));
            }

            try {
                foreach ($this->httpClient->stream($response) as $chunk) {
                    $content = $chunk->getContent();
                    $size += strlen($content);
                    fwrite($handle, $content);
                }
            } finally {
                fclose($handle);
            }

            return ['content_type' => $contentType, 'size' => $size];
        } catch (PlugNmeetException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PlugNmeetException(sprintf('Telechargement de l enregistrement impossible (%s).', $e->getMessage()), 0, $e);
        }
    }

    // ---- Artefacts (exports tableau blanc, chat...) ----

    /**
     * @param array<int, string> $roomIds
     *
     * @return array<string, mixed>
     */
    public function fetchArtifacts(array $roomIds = [], int $from = 0, int $limit = 20, string $orderBy = 'DESC'): array
    {
        return $this->request('/artifact/fetch', [
            'room_ids' => $roomIds,
            'from'     => $from,
            'limit'    => $limit,
            'order_by' => $orderBy,
        ]);
    }

    public function getArtifactDownloadUrl(string $artifactId): string
    {
        $data = $this->request('/artifact/getDownloadToken', ['artifact_id' => $artifactId]);

        return rtrim($this->config->getPlugNmeetServerUrl(), '/').'/download/artifact/'.(string) ($data['token'] ?? '');
    }

    /**
     * Telecharge un artefact JSON (ex. MEETING_ANALYTICS) et le decode.
     *
     * Contrairement aux enregistrements video, ces fichiers sont petits (des
     * relevés d'evenements, pas du binaire) : chargement direct en memoire,
     * pas de flux vers un fichier temporaire.
     *
     * @return array<string, mixed>
     */
    public function downloadArtifactJson(string $artifactId): array
    {
        $url = $this->getArtifactDownloadUrl($artifactId);

        try {
            $response = $this->httpClient->request('GET', $url, ['timeout' => 60]);
            $status   = $response->getStatusCode();
            $content  = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new PlugNmeetException(sprintf('Telechargement de l artefact impossible (%s).', $e->getMessage()), 0, $e);
        }

        if ($status >= 400) {
            throw new PlugNmeetException(sprintf('Telechargement de l artefact echoue (HTTP %d).', $status));
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new PlugNmeetException('Le contenu de l artefact n est pas un JSON valide.');
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteArtifact(string $artifactId): array
    {
        return $this->request('/artifact/delete', ['artifact_id' => $artifactId]);
    }

    // ---- Interne ----

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function request(string $path, array $body): array
    {
        $bodyJson  = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $signature = hash_hmac('sha256', $bodyJson, $this->config->getPlugNmeetApiSecret());
        $serverUrl = rtrim($this->config->getPlugNmeetServerUrl(), '/');

        try {
            $response = $this->httpClient->request('POST', $serverUrl.'/auth'.$path, [
                'headers' => [
                    'Content-Type'   => 'application/json',
                    'API-KEY'        => $this->config->getPlugNmeetApiKey(),
                    'HASH-SIGNATURE' => $signature,
                ],
                'body'    => $bodyJson,
                'timeout' => 30,
            ]);

            $status = $response->getStatusCode();
            $content = $response->getContent(false);
        } catch (\Throwable $e) {
            throw new PlugNmeetException(sprintf('Appel plugNmeet impossible (%s).', $e->getMessage()), 0, $e);
        }

        $decoded = json_decode($content, true);

        if (!is_array($decoded)) {
            throw new PlugNmeetException(sprintf('Reponse plugNmeet non-JSON (HTTP %d).', $status));
        }

        if (false === ($decoded['status'] ?? null)) {
            throw new PlugNmeetException((string) ($decoded['msg'] ?? 'Erreur plugNmeet inconnue.'));
        }

        if ($status >= 400) {
            throw new PlugNmeetException(sprintf('Erreur plugNmeet (HTTP %d) : %s', $status, (string) ($decoded['msg'] ?? $content)));
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     *
     * @return array<string, mixed>
     */
    private function mergeDeep(array $target, array $source): array
    {
        $output = $target;

        foreach ($source as $key => $value) {
            $output[$key] = (is_array($value) && isset($output[$key]) && is_array($output[$key]) && !array_is_list($value))
                ? $this->mergeDeep($output[$key], $value)
                : $value;
        }

        return $output;
    }
}
