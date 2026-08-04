<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyMeetInvitation;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\InvitationLinkSigner;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entree public (pas de compte Mautic requis, cf. Config/config.php)
 * d'un lien d'invitation genere par l'action de campagne "Creer un lien
 * invitation meet".
 *
 * Le token est longue duree (30 jours), mais ne contient que contact+salle :
 * le jeton plugNmeet reel, a usage unique et courte duree de vie, est mint ici
 * a la volee a chaque clic, jamais mis en cache.
 */
class MeetJoinController extends CommonController
{
    public function joinAction(
        string $token,
        InvitationLinkSigner $signer,
        PlugNmeetClient $client,
        LeadModel $leadModel,
        EntityManagerInterface $em,
    ): Response {
        $claims = $signer->verify($token);

        if (null === $claims) {
            return $this->errorPage('Ce lien d\'invitation est invalide ou a expire.');
        }

        $lead = $leadModel->getEntity($claims['lead_id']);

        if (!$lead instanceof Lead) {
            return $this->errorPage('Ce lien d\'invitation n\'est plus valide.');
        }

        try {
            $active = $client->isRoomActive($claims['room_id']);
        } catch (PlugNmeetException) {
            return $this->errorPage('Cette reunion n\'est pas (ou plus) disponible.');
        }

        if (true !== ($active['is_active'] ?? false)) {
            return $this->errorPage('Cette reunion n\'est pas encore ouverte ou est deja terminee.');
        }

        $this->markClicked($em, $token);

        $name = trim($lead->getFirstname().' '.$lead->getLastname());

        try {
            $result = $client->getJoinToken($claims['room_id'], [
                'name'     => '' !== $name ? $name : ($lead->getEmail() ?? sprintf('Contact #%d', $lead->getId())),
                // Prefixe stable et reconnaissable : c'est ce que la reconciliation
                // de presence (ReconcileMeetAttendanceCommand) recherche dans
                // l'artefact MEETING_ANALYTICS pour retrouver ce contact.
                'user_id'  => 'lead-'.$lead->getId(),
                'is_admin' => false,
            ]);
        } catch (PlugNmeetException) {
            return $this->errorPage('Impossible de rejoindre la reunion pour le moment. Reessayez dans un instant.');
        }

        return new RedirectResponse($client->buildJoinUrl((string) ($result['token'] ?? '')));
    }

    private function markClicked(EntityManagerInterface $em, string $token): void
    {
        // Best-effort : un souci d'ecriture ici ne doit jamais empecher le
        // contact de rejoindre la reunion.
        try {
            $invitation = $em->getRepository(WittyMeetInvitation::class)->findOneBy(['token' => $token]);

            if ($invitation instanceof WittyMeetInvitation) {
                $invitation->markClicked();
                // persist() sur une entite deja managed n'a normalement rien a
                // faire, mais sans lui le changement n'est pas detecte au
                // flush() dans ce contexte (constate empiriquement) : Mautic
                // suit la meme regle defensive dans tous ses saveEntity().
                $em->persist($invitation);
                $em->flush();
            }
        } catch (\Throwable) {
        }
    }

    private function errorPage(string $message): Response
    {
        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Invitation</title>'
            .'<style>body{font-family:sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;'
            .'background:#f6f8fb;margin:0;padding:24px;box-sizing:border-box;}'
            .'.card{background:#fff;border-radius:8px;padding:32px;max-width:420px;text-align:center;'
            .'box-shadow:0 1px 4px rgba(0,0,0,.08);}</style></head>'
            .'<body><div class="card"><p>'.htmlspecialchars($message, ENT_QUOTES).'</p></div></body></html>';

        return new Response($html, Response::HTTP_GONE);
    }
}
