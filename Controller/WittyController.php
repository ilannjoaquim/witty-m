<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Service\Agent\AgentRunner;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationManager;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationNotFoundException;
use MauticPlugin\WittyBundle\Service\Usage\QuotaExceededException;
use MauticPlugin\WittyBundle\Service\Usage\UsageGuard;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WittyController extends CommonController
{
    public function indexAction(
        WittyConfig $config,
        ConversationManager $conversations,
        UsageGuard $usageGuard,
    ): Response {
        return $this->delegateView([
            'viewParameters' => [
                'isConfigured'  => $config->isConfigured(),
                'provider'      => $config->getProvider(),
                'model'         => $config->getModel(),
                'streaming'     => $config->isStreamingEnabled(),
                'conversations' => $conversations->listForCurrentUser(),
                'usage'         => $usageGuard->getStatus(),
            ],
            'contentTemplate' => '@Witty/Chat/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_chat',
                'mauticContent' => 'witty',
                'route'         => $this->generateUrl('witty_chat'),
            ],
        ]);
    }

    /**
     * Reponse en un bloc. Sert de repli quand le streaming est desactive en
     * configuration ou quand l'hebergement bufferise les reponses.
     */
    public function sendAction(Request $request, AgentRunner $agentRunner, ConversationManager $conversations): JsonResponse
    {
        $payload = $this->decode($request);

        if (null === $payload) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$message, $conversationId] = $payload;

        try {
            $conversation = $conversations->resolve($conversationId);
            $result       = $agentRunner->run($conversation, $message);
        } catch (ConversationNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        } catch (QuotaExceededException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_TOO_MANY_REQUESTS);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($result + ['conversation_id' => $conversation->getId()]);
    }

    /**
     * Meme traitement, diffuse en SSE.
     */
    public function streamAction(Request $request, AgentRunner $agentRunner, ConversationManager $conversations): Response
    {
        $payload = $this->decode($request);

        if (null === $payload) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        [$message, $conversationId] = $payload;

        // La session est verrouillee pendant toute la requete : sans fermeture
        // explicite, le reste de l'interface Mautic attendrait la fin du flux.
        $request->getSession()->save();

        $response = new StreamedResponse(function () use ($agentRunner, $conversations, $message, $conversationId): void {
            $emit = function (string $event, array $data): void {
                echo 'event: '.$event."\n";
                echo 'data: '.json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (0 < ob_get_level()) {
                    @ob_flush();
                }

                flush();
            };

            $conversation = null;

            try {
                $conversation = $conversations->resolve($conversationId);
                $result       = $agentRunner->run($conversation, $message, $emit);

                $emit('done', $result + ['conversation_id' => $conversation->getId()]);
            } catch (ConversationNotFoundException|QuotaExceededException $e) {
                $emit('error', ['error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $emit('error', [
                    'error'           => $e->getMessage(),
                    'conversation_id' => $conversation?->getId(),
                ]);
            }
        });

        $response->headers->set('Content-Type', 'text/event-stream');
        $response->headers->set('Cache-Control', 'no-cache');
        $response->headers->set('Connection', 'keep-alive');
        // Demande a nginx de ne pas bufferiser : sans cela le flux arriverait
        // d'un seul bloc a la fin, ce qui annulerait tout l'interet.
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    public function conversationsAction(ConversationManager $conversations): JsonResponse
    {
        return new JsonResponse(['conversations' => $conversations->listForCurrentUser()]);
    }

    public function conversationAction(int $id, ConversationManager $conversations): JsonResponse
    {
        try {
            $conversation = $conversations->resolve($id);
        } catch (ConversationNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'conversation_id' => $conversation->getId(),
            'title'           => $conversation->getTitle(),
            'transcript'      => $conversations->toDisplayTranscript($conversation),
            'tokens'          => $conversation->getTotalTokens(),
        ]);
    }

    public function deleteConversationAction(int $id, ConversationManager $conversations): JsonResponse
    {
        try {
            $conversation = $conversations->resolve($id);
        } catch (ConversationNotFoundException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_NOT_FOUND);
        }

        $conversations->delete($conversation);

        return new JsonResponse(['deleted' => $id]);
    }

    /**
     * @return array{0: string, 1: int|null}|null
     */
    private function decode(Request $request): ?array
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return null;
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ('' === $message) {
            return null;
        }

        $conversationId = isset($payload['conversation_id']) && is_numeric($payload['conversation_id'])
            ? (int) $payload['conversation_id']
            : null;

        return [$message, $conversationId];
    }
}
