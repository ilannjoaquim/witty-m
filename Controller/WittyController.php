<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Entity\WittyConversation;
use MauticPlugin\WittyBundle\Service\Agent\AgentRunner;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationManager;
use MauticPlugin\WittyBundle\Service\Conversation\ConversationNotFoundException;
use MauticPlugin\WittyBundle\Service\Llm\ModelCatalog;
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
        $providers = array_map(
            static fn (string $provider): array => [
                'key'   => $provider,
                'label' => $config->getProviderLabel($provider),
                'model' => $config->getModel($provider),
            ],
            $config->getConfiguredProviders(),
        );

        return $this->delegateView([
            'viewParameters' => [
                'isConfigured'    => $config->isConfigured(),
                'providers'       => $providers,
                'defaultProvider' => $config->getDefaultProvider(),
                'streaming'       => $config->isStreamingEnabled(),
                'conversations'   => $conversations->listForCurrentUser(),
                'usage'           => $usageGuard->getStatus(),
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

        [$message, $conversationId, $provider, $model] = $payload;

        try {
            $conversation = $conversations->resolve($conversationId);
            $result       = $agentRunner->run($conversation, $message, null, $provider, $model);
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

        [$message, $conversationId, $provider, $model] = $payload;

        // La session est verrouillee pendant toute la requete : sans fermeture
        // explicite, le reste de l'interface Mautic attendrait la fin du flux.
        $request->getSession()->save();

        $response = new StreamedResponse(function () use ($agentRunner, $conversations, $message, $conversationId, $provider, $model): void {
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
                $result       = $agentRunner->run($conversation, $message, $emit, $provider, $model);

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

    public function searchAction(Request $request, ConversationManager $conversations): JsonResponse
    {
        $term = trim((string) $request->query->get('q', ''));

        if ('' === $term) {
            return new JsonResponse(['results' => []]);
        }

        return new JsonResponse(['results' => $conversations->search($term)]);
    }

    /**
     * Liste des modeles disponibles pour un fournisseur configure, pour peupler
     * le menu deroulant du chat. Jamais vide : ModelCatalog replie sur le modele
     * par defaut si l'appel au fournisseur echoue.
     */
    public function modelsAction(string $provider, WittyConfig $config, ModelCatalog $catalog): JsonResponse
    {
        if (!$config->isProviderConfigured($provider)) {
            return new JsonResponse(['error' => 'Fournisseur non configure.'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse([
            'models'  => $catalog->getModels($provider),
            'default' => $config->getModel($provider),
        ]);
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
            'provider'        => $conversation->getProvider(),
            'model'           => $conversation->getModel(),
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
     * @return array{0: string, 1: int|null, 2: string|null, 3: string|null}|null
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

        $provider = trim((string) ($payload['provider'] ?? ''));
        $model    = trim((string) ($payload['model'] ?? ''));

        return [$message, $conversationId, '' !== $provider ? $provider : null, '' !== $model ? $model : null];
    }
}
