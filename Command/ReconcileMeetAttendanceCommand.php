<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Command;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyMeetInvitation;
use MauticPlugin\WittyBundle\Entity\WittyMeetInvitationRepository;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Confronte les invitations en attente a la liste reelle des presents
 * (artefact plugNmeet MEETING_ANALYTICS) une fois la salle terminee, pour
 * pouvoir ensuite conditionner points/segments/campagnes sur "a participe a
 * telle reunion" plutot que "a seulement ete invite".
 *
 * A executer periodiquement via le cron systeme (comme mautic:segments:update
 * ou mautic:campaigns:trigger, Mautic n'a pas de planificateur interne) :
 *   php bin/console witty:meet:reconcile-attendance
 *
 * L'analyse plugNmeet peut mettre un moment a etre generee apres la fin d'une
 * salle : une invitation sans artefact disponible reste simplement en attente
 * et sera reessayee au prochain passage, sans limite de tentatives.
 */
#[AsCommand(name: 'witty:meet:reconcile-attendance', description: 'Compare les invitations meet aux presents reels une fois la salle terminee.')]
class ReconcileMeetAttendanceCommand extends Command
{
    private const ARTIFACT_TYPE = 'MEETING_ANALYTICS';

    public function __construct(
        private PlugNmeetClient $client,
        private WittyMeetInvitationRepository $repository,
        private EntityManagerInterface $em,
        private LeadModel $leadModel,
        private LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $roomIds = $this->repository->findPendingRoomIds();

        if ([] === $roomIds) {
            $output->writeln('Aucune invitation en attente de reconciliation.');

            return Command::SUCCESS;
        }

        foreach ($roomIds as $roomId) {
            $this->reconcileRoom($roomId, $output);
        }

        return Command::SUCCESS;
    }

    private function reconcileRoom(string $roomId, OutputInterface $output): void
    {
        $artifactId = $this->findLatestAnalyticsArtifactId($roomId, $output);

        if (null === $artifactId) {
            return;
        }

        try {
            $analytics = $this->client->downloadArtifactJson($artifactId);
        } catch (PlugNmeetException $e) {
            $output->writeln(sprintf('Salle %s : telechargement de l analyse impossible (%s).', $roomId, $e->getMessage()));

            return;
        }

        $attendedLeadIds = $this->extractAttendedLeadIds($analytics);
        $pending         = $this->repository->findPendingForRoom($roomId);
        $attendedCount   = 0;

        foreach ($pending as $invitation) {
            $leadId = $invitation->getLead()?->getId();

            if (null !== $leadId && in_array($leadId, $attendedLeadIds, true)) {
                $invitation->markAttended(new \DateTimeImmutable());
                $this->tagAttended($invitation);
                ++$attendedCount;
            }

            $invitation->markReconciled();
            $this->em->persist($invitation);
        }

        $this->em->flush();

        $output->writeln(sprintf(
            'Salle %s : %d/%d invitation(s) confirmee(s) presente(s).',
            $roomId,
            $attendedCount,
            count($pending),
        ));
    }

    private function findLatestAnalyticsArtifactId(string $roomId, OutputInterface $output): ?string
    {
        try {
            $data = $this->client->fetchArtifacts([$roomId], 0, 50);
        } catch (PlugNmeetException $e) {
            $output->writeln(sprintf('Salle %s : liste des artefacts indisponible (%s).', $roomId, $e->getMessage()));

            return null;
        }

        foreach ((array) ($data['result']['artifacts_list'] ?? []) as $artifact) {
            if (self::ARTIFACT_TYPE === ($artifact['type'] ?? null)) {
                $id = (string) ($artifact['artifact_id'] ?? '');

                return '' !== $id ? $id : null;
            }
        }

        $output->writeln(sprintf('Salle %s : analyse pas encore disponible, on reessaiera.', $roomId));

        return null;
    }

    /**
     * Le user_id transmis a getJoinToken (voir MeetJoinController) est
     * toujours "lead-{id}" : c'est ce prefixe qu'on retrouve tel quel dans
     * users[].user_id de l'artefact d'analyse.
     *
     * @param array<string, mixed> $analytics
     *
     * @return array<int, int>
     */
    private function extractAttendedLeadIds(array $analytics): array
    {
        $leadIds = [];

        foreach ((array) ($analytics['users'] ?? []) as $user) {
            $userId = (string) ($user['user_id'] ?? '');

            if (1 === preg_match('/^lead-(\d+)$/', $userId, $matches)) {
                $leadIds[] = (int) $matches[1];
            }
        }

        return array_unique($leadIds);
    }

    private function tagAttended(WittyMeetInvitation $invitation): void
    {
        $lead = $invitation->getLead();

        if (null === $lead) {
            return;
        }

        try {
            $this->leadModel->modifyTags($lead, ['attended-'.$invitation->getRoomId()], null, true);
        } catch (\Throwable $e) {
            // Le statut "attended" en base est ce qui compte le plus ; un tag
            // qui echoue ne doit pas faire perdre le reste de la reconciliation.
            $this->logger->warning('Witty : pose du tag de presence impossible.', [
                'lead_id'   => $lead->getId(),
                'room_id'   => $invitation->getRoomId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
