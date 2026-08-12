<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequest;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequestRepository;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

/**
 * Recupere le resultat (ou l etat d avancement) d une demande
 * d enrichissement Apollo "waterfall" lancee via enrich_person_waterfall.
 *
 * Trois usages selon ce qui est fourni : request_id pour UNE demande precise
 * (le cas normal, l agent l a recu en retour de enrich_person_waterfall),
 * contact_id pour l historique d un contact (utile si l agent n a plus le
 * request_id, ex. nouvelle conversation), ou aucun des deux pour les
 * demandes recentes de l utilisateur courant (statut par defaut : en
 * attente, pour voir ce qui n a pas encore de reponse).
 */
class CheckWaterfallEnrichmentTool extends AbstractTool
{
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
    ) {
    }

    public function getName(): string
    {
        return 'check_waterfall_enrichment';
    }

    public function getDescription(): string
    {
        return 'Recupere le resultat d une demande enrich_person_waterfall. Fournis request_id pour une demande '
            .'precise (email/telephone trouves si status=completed), ou contact_id pour l historique d un contact, '
            .'ou rien pour lister tes demandes recentes (status filtre optionnel : pending/completed/failed).';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'request_id' => ['type' => 'string'],
            'contact_id' => ['type' => 'integer'],
            'status'     => ['type' => 'string', 'enum' => ['pending', 'completed', 'failed'], 'description' => 'Filtre applique seulement quand ni request_id ni contact_id ne sont fournis.'],
            'limit'      => ['type' => 'integer', 'description' => 'Defaut 20, maximum 50.'],
        ], []);
    }

    public function execute(array $arguments): array
    {
        /** @var WittyApolloWaterfallRequestRepository $repository */
        $repository = $this->entityManager->getRepository(WittyApolloWaterfallRequest::class);

        $requestId = trim((string) ($arguments['request_id'] ?? ''));

        if ('' !== $requestId) {
            $found = $repository->findOneByRequestId($requestId);

            if (!$found instanceof WittyApolloWaterfallRequest) {
                return ['status' => 'error', 'error' => sprintf('Aucune demande avec request_id=%s.', $requestId)];
            }

            return $this->ok(['request' => $this->serialize($found)]);
        }

        $limit = max(1, min(50, (int) ($arguments['limit'] ?? self::DEFAULT_LIMIT)));

        if (!empty($arguments['contact_id'])) {
            $requests = $repository->findForLead((int) $arguments['contact_id'], $limit);

            return $this->ok(['requests' => array_map($this->serialize(...), $requests)]);
        }

        $user   = $this->userHelper->getUser();
        $status = $arguments['status'] ?? null;
        $status = is_string($status) && '' !== $status ? $status : null;

        $requests = $repository->findRecentForUser((int) $user->getId(), $status, $limit);

        return $this->ok(['requests' => array_map($this->serialize(...), $requests)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(WittyApolloWaterfallRequest $request): array
    {
        return array_filter([
            'request_id'     => $request->getRequestId(),
            'label'          => $request->getLabel(),
            'mode'           => $request->getMode(),
            'status'         => $request->getStatus(),
            'contact_id'     => $request->getLead()?->getId(),
            'result'         => $request->getResult(),
            'date_added'     => $request->getDateAdded()->format(\DateTimeInterface::ATOM),
            'date_completed' => $request->getDateCompleted()?->format(\DateTimeInterface::ATOM),
        ], static fn ($value): bool => null !== $value);
    }
}
