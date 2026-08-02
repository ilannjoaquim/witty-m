<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Usage;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\UserBundle\Entity\User;
use MauticPlugin\WittyBundle\Entity\WittyMessage;
use MauticPlugin\WittyBundle\Entity\WittyMessageRepository;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Garde-fou de cout : plafond de tokens par utilisateur et par jour.
 *
 * Le controle a lieu avant chaque appel au modele, pas apres : depasser d'un
 * tour complet un quota deja atteint n'aurait aucun interet. La consequence
 * est qu'un tour peut franchir la limite (on ne connait le cout qu'apres
 * l'appel) ; le suivant sera refuse.
 */
class UsageGuard
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserHelper $userHelper,
        private WittyConfig $config,
    ) {
    }

    /**
     * @throws QuotaExceededException
     */
    public function assertWithinQuota(): void
    {
        $quota = $this->config->getDailyTokenQuota();

        if (0 >= $quota) {
            return;
        }

        $used = $this->getTokensUsedToday();

        if ($used >= $quota) {
            throw new QuotaExceededException(sprintf(
                'Quota quotidien atteint : %s tokens consommes sur %s autorises. Le compteur repart a minuit.',
                number_format($used, 0, ',', ' '),
                number_format($quota, 0, ',', ' '),
            ));
        }
    }

    /**
     * @return array{used: int, quota: int, remaining: int|null}
     */
    public function getStatus(): array
    {
        $quota = $this->config->getDailyTokenQuota();
        $used  = $this->getTokensUsedToday();

        return [
            'used'      => $used,
            'quota'     => $quota,
            'remaining' => 0 < $quota ? max(0, $quota - $used) : null,
        ];
    }

    public function getTokensUsedToday(): int
    {
        $user = $this->userHelper->getUser();

        if (!$user instanceof User || null === $user->getId()) {
            return 0;
        }

        /** @var WittyMessageRepository $repository */
        $repository = $this->entityManager->getRepository(WittyMessage::class);

        return $repository->getTokensUsedSince(
            (int) $user->getId(),
            new \DateTimeImmutable('today midnight'),
        );
    }
}
