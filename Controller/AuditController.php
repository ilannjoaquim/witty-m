<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Controller;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Controller\CommonController;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyAuditLog;
use MauticPlugin\WittyBundle\Entity\WittyAuditLogRepository;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consultation du journal des actions de l'agent.
 *
 * Un administrateur voit tout le monde ; les autres ne voient que leurs propres
 * actions, comme partout ailleurs dans Mautic.
 */
class AuditController extends CommonController
{
    public function indexAction(
        Request $request,
        EntityManagerInterface $entityManager,
        UserHelper $userHelper,
    ): Response {
        /** @var WittyAuditLogRepository $repository */
        $repository = $entityManager->getRepository(WittyAuditLog::class);

        $user    = $userHelper->getUser();
        $isAdmin = $this->security->isAdmin();
        $scopeId = !$isAdmin && $user instanceof User && null !== $user->getId() ? (int) $user->getId() : null;

        $tool    = trim((string) $request->query->get('tool', ''));
        $entries = $repository->findRecent(200, $scopeId, '' !== $tool ? $tool : null);

        return $this->delegateView([
            'viewParameters' => [
                'entries' => $entries,
                'isAdmin' => $isAdmin,
                'tool'    => $tool,
                'writes'  => $repository->countWritesByTool(new \DateTimeImmutable('-30 days')),
            ],
            'contentTemplate' => '@Witty/Audit/index.html.twig',
            'passthroughVars' => [
                'activeLink'    => '#witty_audit',
                'mauticContent' => 'wittyAudit',
                'route'         => $this->generateUrl('witty_audit'),
            ],
        ]);
    }
}
