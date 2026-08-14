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
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Point d'entree public (pas de compte Mautic requis, cf. Config/config.php)
 * d'un lien d'invitation : genere par l'action de campagne "Creer un lien
 * invitation meet" (rattache a un Lead precis) OU par le bouton "Lien
 * partageable" de la section Rooms (pas rattache a un contact, lead_id=null
 * dans le token, cf. InvitationLinkSigner).
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

        try {
            $active = $client->isRoomActive($claims['room_id']);
        } catch (PlugNmeetException) {
            return $this->errorPage('Cette reunion n\'est pas (ou plus) disponible.');
        }

        if (true !== ($active['is_active'] ?? false)) {
            return $this->errorPage('Cette reunion n\'est pas encore ouverte ou est deja terminee.');
        }

        // Lien partageable (pas de Lead connu a l'avance) : on demande le nom
        // au visiteur plutot que de le resoudre automatiquement. La saisie
        // poste vers joinAnonymousAction, qui refait sa propre verification
        // du token (jamais de confiance dans une valeur non re-verifiee).
        if (null === $claims['lead_id']) {
            return $this->nameForm($token);
        }

        $lead = $leadModel->getEntity($claims['lead_id']);

        if (!$lead instanceof Lead) {
            return $this->errorPage('Ce lien d\'invitation n\'est plus valide.');
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

    /**
     * Cible du formulaire affiche par nameForm() : un lien partageable n'a
     * pas de Lead a resoudre, le visiteur fournit son propre nom d'affichage
     * a cet instant precis. Jamais de creation/rattachement de contact ici —
     * ce visiteur reste anonyme cote Mautic, par design (cf. docblock de
     * classe pour le compromis assume sur le suivi de presence).
     */
    public function joinAnonymousAction(
        Request $request,
        string $token,
        InvitationLinkSigner $signer,
        PlugNmeetClient $client,
    ): Response {
        $claims = $signer->verify($token);

        if (null === $claims || null !== $claims['lead_id']) {
            // Un token a lead_id connu ne doit jamais transiter par ce
            // chemin (defense en profondeur : l'UI ne le propose pas, mais
            // on ne fait jamais confiance a la seule UI).
            return $this->errorPage('Ce lien d\'invitation est invalide ou a expire.');
        }

        $name = trim((string) $request->request->get('name', ''));

        if ('' === $name) {
            return $this->nameForm($token, 'Merci de renseigner votre nom.');
        }

        $name = mb_substr($name, 0, 80);

        try {
            $active = $client->isRoomActive($claims['room_id']);
        } catch (PlugNmeetException) {
            return $this->errorPage('Cette reunion n\'est pas (ou plus) disponible.');
        }

        if (true !== ($active['is_active'] ?? false)) {
            return $this->errorPage('Cette reunion n\'est pas encore ouverte ou est deja terminee.');
        }

        try {
            $result = $client->getJoinToken($claims['room_id'], [
                'name'     => $name,
                // Pas de prefixe lead- : ce visiteur n'a pas de Lead Mautic,
                // ReconcileMeetAttendanceCommand ne le retrouvera jamais et
                // c'est attendu (lien partageable = pas de suivi individuel).
                'user_id'  => 'guest-'.substr(md5($name.microtime()), 0, 8),
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

    private function nameForm(string $token, ?string $error = null): Response
    {
        $action = $this->generateUrl('witty_meet_join_anonymous', ['token' => $token]);

        $errorHtml = null !== $error
            ? '<p class="error">'.htmlspecialchars($error, ENT_QUOTES).'</p>'
            : '';

        $html = '<!doctype html><html lang="fr"><head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>Rejoindre la reunion</title>'
            .'<style>body{font-family:sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;'
            .'background:#f6f8fb;margin:0;padding:24px;box-sizing:border-box;}'
            .'.card{background:#fff;border-radius:8px;padding:32px;max-width:420px;width:100%;text-align:center;'
            .'box-shadow:0 1px 4px rgba(0,0,0,.08);box-sizing:border-box;}'
            .'.card p.error{color:#c0392b;font-size:14px;margin-top:0;}'
            .'.card input{width:100%;padding:10px 12px;font-size:16px;border:1px solid #d5dae0;border-radius:4px;'
            .'box-sizing:border-box;margin:12px 0;}'
            .'.card button{width:100%;padding:10px 12px;font-size:16px;border:0;border-radius:4px;'
            .'background:#4e5e9e;color:#fff;cursor:pointer;}</style></head>'
            .'<body><div class="card">'
            .'<h2>Rejoindre la reunion</h2>'
            .$errorHtml
            .'<form method="post" action="'.htmlspecialchars($action, ENT_QUOTES).'">'
            .'<input type="text" name="name" placeholder="Votre nom" maxlength="80" autofocus required>'
            .'<button type="submit">Rejoindre</button>'
            .'</form></div></body></html>';

        return new Response($html);
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
