<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequest;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequestRepository;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloWaterfallPayloadParser;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Point d'entree public (pas de compte Mautic requis, cf. Config/config.php)
 * appele par Apollo lui-meme pour livrer le resultat d'un enrichissement
 * "waterfall" (cf. EnrichPersonWaterfallTool, `webhook_url` transmis a
 * l'appel initial). Asynchrone par nature : la reponse peut arriver plusieurs
 * minutes apres la requete, sur un tout autre cycle HTTP que celui qui a
 * declenche l'enrichissement — c'est ce qui impose de passer par une table
 * (WittyApolloWaterfallRequest) plutot que par une reponse en memoire.
 *
 * `{token}` (cf. WittyConfig::getApolloWebhookToken()) protege l'URL : sans
 * lui n'importe qui devinant le chemin pourrait POSTer un faux resultat
 * d'enrichissement (ex. un email/telephone invente) que l'agent relaierait
 * ensuite comme fiable a l'utilisateur.
 */
class ApolloWaterfallWebhookController extends CommonController
{
    public function receiveAction(
        string $token,
        Request $request,
        WittyConfig $config,
        EntityManagerInterface $em,
    ): JsonResponse {
        // hash_equals() plutot que === : comparaison a temps constant, meme
        // si le risque reel ici (un jeton derive de la cle API, pas un secret
        // protegeant un acces direct) ne justifierait pas a lui seul cette
        // precaution — sans cout, autant l'appliquer.
        if (!hash_equals($config->getApolloWebhookToken(), $token)) {
            return new JsonResponse(['error' => 'invalid token'], JsonResponse::HTTP_NOT_FOUND);
        }

        $payload = json_decode($request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'invalid payload'], JsonResponse::HTTP_BAD_REQUEST);
        }

        $requestId = (string) ($payload['request_id'] ?? '');

        if ('' === $requestId) {
            return new JsonResponse(['error' => 'missing request_id'], JsonResponse::HTTP_BAD_REQUEST);
        }

        /** @var WittyApolloWaterfallRequestRepository $repository */
        $repository = $em->getRepository(WittyApolloWaterfallRequest::class);
        $pending    = $repository->findOneByRequestId($requestId);

        // Rien a rattacher (requete inconnue, ou deja traitee et purgee) :
        // on accuse quand meme reception pour qu'Apollo n'insiste pas en
        // retentant indefiniment.
        if (!$pending instanceof WittyApolloWaterfallRequest) {
            return new JsonResponse(['status' => 'ok']);
        }

        // Idempotent par construction (re-ecrit les memes champs) : un webhook
        // livre deux fois (retry Apollo) ne casse rien.
        if (ApolloWaterfallPayloadParser::isSuccess($payload)) {
            $pending->setStatus(WittyApolloWaterfallRequest::STATUS_COMPLETED);
            $pending->setResult(ApolloWaterfallPayloadParser::extract($payload));
        } else {
            $pending->setStatus(WittyApolloWaterfallRequest::STATUS_FAILED);
            $pending->setResult(['message' => (string) ($payload['message'] ?? 'echec non precise par Apollo.')]);
        }

        $pending->setDateCompleted(new \DateTimeImmutable());

        $em->persist($pending);
        $em->flush();

        return new JsonResponse(['status' => 'ok']);
    }
}
