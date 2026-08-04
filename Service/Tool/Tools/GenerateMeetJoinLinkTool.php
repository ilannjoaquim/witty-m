<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lien de connexion ad hoc pour un nom donne (pas forcement un contact Mautic
 * existant), ex. pour un moderateur ou un invite ponctuel. Pour un contact
 * deja en base avec suivi de presence, preferer create_meet_invitation.
 */
class GenerateMeetJoinLinkTool extends AbstractTool
{
    public function __construct(
        private PlugNmeetClient $client,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'generate_meet_join_link';
    }

    public function getDescription(): string
    {
        return 'Genere un lien de connexion ponctuel a une salle plugNmeet active, pour un nom donne '
            .'(moderateur, invite...). Le lien plugNmeet reel est a usage unique et courte duree de vie : '
            .'a transmettre et utiliser immediatement, jamais a mettre en cache pour plus tard.';
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
            'room_id'  => ['type' => 'string', 'description' => 'Identifiant de la salle, doit etre active.'],
            'name'     => ['type' => 'string', 'description' => 'Nom affiche dans la reunion.'],
            'is_admin' => ['type' => 'boolean', 'description' => 'true pour un lien presentateur/moderateur, false pour un lien auditeur. Defaut false.'],
        ], ['room_id', 'name']);
    }

    public function execute(array $arguments): array
    {
        $roomId  = trim((string) ($arguments['room_id'] ?? ''));
        $name    = trim((string) ($arguments['name'] ?? ''));
        $isAdmin = (bool) ($arguments['is_admin'] ?? false);

        if ('' === $roomId || '' === $name) {
            return ['status' => 'error', 'error' => 'room_id et name sont obligatoires.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'meet_join_link',
                'room_id'  => $roomId,
                'name'     => $name,
                'is_admin' => $isAdmin,
            ]);
        }

        try {
            $result = $this->client->getJoinToken($roomId, [
                'name'     => $name,
                'user_id'  => 'adhoc-'.substr(md5($name.microtime()), 0, 10),
                'is_admin' => $isAdmin,
            ]);
        } catch (PlugNmeetException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        return $this->ok([
            'room_id'  => $roomId,
            'name'     => $name,
            'is_admin' => $isAdmin,
            'join_url' => $this->client->buildJoinUrl((string) ($result['token'] ?? '')),
        ]);
    }
}
