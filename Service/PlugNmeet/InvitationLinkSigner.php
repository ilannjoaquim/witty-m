<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\PlugNmeet;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Genere et verifie les liens d'invitation a une salle plugNmeet.
 *
 * Le token est un JWT-maison auto-porteur (charge utile + signature HMAC-SHA256,
 * pas de tour en base pour le verifier) : {lead_id, room_id, exp}. C'est
 * exactement le mecanisme du "shareable link" du backoffice plugNmeet de
 * reference (dashboard/server.js, signLinkToken/verifyLinkToken), porte en PHP.
 *
 * Le jeton plugNmeet reel (celui que renvoie getJoinToken) est a usage unique
 * et de courte duree de vie : on ne le genere jamais a l'avance. Ce token-ci
 * ne sert qu'a franchir /meet/join/{token}, qui mint le vrai jeton a la volee
 * au moment du clic (voir JoinController). D'ou une duree de vie longue (30
 * jours par defaut) sans compromettre la securite du join lui-meme.
 */
class InvitationLinkSigner
{
    private const DEFAULT_TTL_DAYS = 30;

    public function __construct(
        private CoreParametersHelper $coreParametersHelper,
        private UrlGeneratorInterface $router,
    ) {
    }

    /**
     * @return array{token: string, url: string}
     */
    public function sign(int $leadId, string $roomId, ?string $roomTitle = null, int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $payload = [
            'lead_id' => $leadId,
            'room_id' => $roomId,
            'exp'     => time() + max(1, $ttlDays) * 86400,
        ];

        $body  = $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $token = $body.'.'.$this->signature($body);

        $url = $this->router->generate('witty_meet_join', ['token' => $token], UrlGeneratorInterface::ABSOLUTE_URL);

        return ['token' => $token, 'url' => $url];
    }

    /**
     * @return array{lead_id: int, room_id: string}|null null si absent, expire ou signature invalide.
     */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token, 2);

        if (2 !== count($parts)) {
            return null;
        }

        [$body, $signature] = $parts;

        if (!hash_equals($this->signature($body), $signature)) {
            return null;
        }

        $json = $this->base64UrlDecode($body);
        $payload = null !== $json ? json_decode($json, true) : null;

        if (!is_array($payload)
            || !isset($payload['lead_id'], $payload['room_id'], $payload['exp'])
            || !is_int($payload['lead_id'])
            || !is_string($payload['room_id'])
            || !is_int($payload['exp'])
        ) {
            return null;
        }

        if (time() > $payload['exp']) {
            return null;
        }

        return ['lead_id' => $payload['lead_id'], 'room_id' => $payload['room_id']];
    }

    private function signature(string $body): string
    {
        return hash_hmac('sha256', $body, (string) $this->coreParametersHelper->get('secret_key'));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return false === $decoded ? null : $decoded;
    }
}
