<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Mautic\CoreBundle\Controller\CommonController;
use MauticPlugin\WittyBundle\Service\Agent\AgentRunner;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WittyController extends CommonController
{
    public function indexAction(WittyConfig $config): Response
    {
        return $this->delegateView([
            'viewParameters' => [
                'isConfigured' => $config->isConfigured(),
                'provider'     => $config->getProvider(),
                'model'        => $config->getModel(),
            ],
            'contentTemplate' => '@Witty/Chat/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_chat',
                'mauticContent' => 'witty',
                'route'         => $this->generateUrl('witty_chat'),
            ],
        ]);
    }

    public function sendAction(Request $request, AgentRunner $agentRunner): JsonResponse
    {
        $payload = json_decode((string) $request->getContent(), true);

        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Payload invalide.'], Response::HTTP_BAD_REQUEST);
        }

        $message = trim((string) ($payload['message'] ?? ''));

        if ('' === $message) {
            return new JsonResponse(['error' => 'Message vide.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $result = $agentRunner->run((array) ($payload['history'] ?? []), $message);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return new JsonResponse($result);
    }
}
