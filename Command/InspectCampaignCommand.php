<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Command;

use Mautic\CampaignBundle\Entity\Campaign;
use Mautic\CampaignBundle\Entity\Event;
use Mautic\CampaignBundle\Model\CampaignModel;
use MauticPlugin\WittyBundle\Service\Campaign\CampaignEventCatalog;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Outil de retro-ingenierie du format des campagnes.
 *
 *   php bin/console witty:campaign:inspect 12          dump JSON d une campagne existante
 *   php bin/console witty:campaign:inspect --catalog   liste des evenements installes
 */
#[AsCommand(
    name: 'witty:campaign:inspect',
    description: 'Dump la structure interne d une campagne Mautic, ou le catalogue des evenements disponibles.',
)]
class InspectCampaignCommand extends Command
{
    public function __construct(
        private CampaignModel $campaignModel,
        private CampaignEventCatalog $catalog,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::OPTIONAL, 'Identifiant de la campagne a inspecter.')
            ->addOption('catalog', 'c', InputOption::VALUE_NONE, 'Afficher le catalogue des evenements au lieu d une campagne.')
            ->addOption('no-properties', null, InputOption::VALUE_NONE, 'Avec --catalog : omettre le detail des proprietes.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('catalog')) {
            $this->write($output, $this->catalog->all(!$input->getOption('no-properties')));

            return Command::SUCCESS;
        }

        $id = $input->getArgument('id');

        if (null === $id) {
            $output->writeln('<error>Fournir un identifiant de campagne, ou utiliser --catalog.</error>');

            return Command::INVALID;
        }

        $campaign = $this->campaignModel->getEntity((int) $id);

        if (!$campaign instanceof Campaign) {
            $output->writeln(sprintf('<error>Campagne %d introuvable.</error>', (int) $id));

            return Command::FAILURE;
        }

        $this->write($output, [
            'campaign' => [
                'id'          => $campaign->getId(),
                'name'        => $campaign->getName(),
                'isPublished' => $campaign->isPublished(),
            ],
            'leadSources' => [
                'lists' => $this->identify($campaign->getLists()),
                'forms' => $this->identify($campaign->getForms()),
            ],
            'canvasSettings' => $campaign->getCanvasSettings(),
            'events'         => $this->describeEvents($campaign),
        ]);

        return Command::SUCCESS;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function describeEvents(Campaign $campaign): array
    {
        $events = [];

        foreach ($campaign->getEvents() as $key => $event) {
            if (!$event instanceof Event) {
                continue;
            }

            $events[] = array_filter([
                'collectionKey'       => (string) $key,
                'id'                  => $event->getId(),
                'tempId'              => $this->call($event, 'getTempId'),
                'name'                => $event->getName(),
                'eventType'           => $event->getEventType(),
                'type'                => $event->getType(),
                'order'               => $event->getOrder(),
                'parentId'            => $event->getParent()?->getId(),
                'decisionPath'        => $event->getDecisionPath(),
                'triggerMode'         => $event->getTriggerMode(),
                'triggerInterval'     => $event->getTriggerInterval(),
                'triggerIntervalUnit' => $event->getTriggerIntervalUnit(),
                'triggerDate'         => $event->getTriggerDate()?->format(\DATE_ATOM),
                'triggerHour'         => $this->call($event, 'getTriggerHour'),
                'triggerRestrictedDaysOfWeek' => $this->call($event, 'getTriggerRestrictedDaysOfWeek'),
                'channel'             => $this->call($event, 'getChannel'),
                'channelId'           => $this->call($event, 'getChannelId'),
                'properties'          => $event->getProperties(),
            ], static fn ($value): bool => null !== $value && [] !== $value);
        }

        return $events;
    }

    private function call(object $object, string $method): mixed
    {
        if (!method_exists($object, $method)) {
            return null;
        }

        $value = $object->$method();

        return $value instanceof \DateTimeInterface ? $value->format(\DATE_ATOM) : $value;
    }

    /**
     * @param iterable<object> $collection
     *
     * @return array<int, array<string, mixed>>
     */
    private function identify(iterable $collection): array
    {
        $items = [];

        foreach ($collection as $item) {
            $items[] = [
                'id'   => method_exists($item, 'getId') ? $item->getId() : null,
                'name' => method_exists($item, 'getName') ? $item->getName() : null,
            ];
        }

        return $items;
    }

    /**
     * @param array<mixed> $data
     */
    private function write(OutputInterface $output, array $data): void
    {
        $output->writeln((string) json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }
}
