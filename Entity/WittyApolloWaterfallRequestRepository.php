<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Entity;

use Mautic\CoreBundle\Entity\CommonRepository;

/**
 * @extends CommonRepository<WittyApolloWaterfallRequest>
 */
class WittyApolloWaterfallRequestRepository extends CommonRepository
{
    /**
     * Utilise par Controller/ApolloWaterfallWebhookController.php pour
     * retrouver la demande correspondant au `request_id` renvoye par Apollo
     * — la seule cle de correlation commune entre l'appel synchrone initial
     * et le webhook asynchrone.
     */
    public function findOneByRequestId(string $requestId): ?WittyApolloWaterfallRequest
    {
        return $this->findOneBy(['requestId' => $requestId]);
    }

    /**
     * Historique recent d'un utilisateur (le plus recent en premier), pour
     * CheckWaterfallEnrichmentTool quand ni request_id ni contact_id ne sont
     * fournis. Scope par utilisateur (comme WittyAttachment) : le detail
     * d'une demande d'enrichissement (nom/email cible) n'a pas a etre visible
     * par d'autres comptes que celui qui l'a lancee.
     *
     * @return WittyApolloWaterfallRequest[]
     */
    public function findRecentForUser(int $userId, ?string $status, int $limit): array
    {
        $qb = $this->createQueryBuilder('r')
            ->where('IDENTITY(r.createdBy) = :userId')
            ->setParameter('userId', $userId)
            ->orderBy('r.dateAdded', 'DESC')
            ->setMaxResults($limit);

        if (null !== $status) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status);
        }

        return $qb->getQuery()->getResult();
    }

    /**
     * Historique d'un contact precis, quel que soit qui a lance la demande :
     * contrairement a findRecentForUser(), pas de scope par utilisateur ici
     * (retrouver "ou en est l'enrichissement de ce contact" est legitime pour
     * quiconque a le droit de modifier ce contact, cf. permission de l'outil).
     *
     * @return WittyApolloWaterfallRequest[]
     */
    public function findForLead(int $leadId, int $limit): array
    {
        return $this->createQueryBuilder('r')
            ->where('IDENTITY(r.lead) = :leadId')
            ->setParameter('leadId', $leadId)
            ->orderBy('r.dateAdded', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }
}
