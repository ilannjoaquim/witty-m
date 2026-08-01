<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Campaign;

use Mautic\CampaignBundle\EventCollector\EventCollector;
use Psr\Log\LoggerInterface;
use Symfony\Component\Form\FormFactoryInterface;

/**
 * Catalogue des evenements de campagne reellement disponibles sur l instance.
 *
 * Plutot que de figer une liste d actions dans le code, on interroge le registre
 * de Mautic : chaque bundle declare ses decisions/conditions/actions sur
 * CampaignEvents::CAMPAIGN_ON_BUILD. Un plugin tiers installe apres coup devient
 * donc utilisable par l agent sans modification de Witty.
 */
class CampaignEventCatalog
{
    public function __construct(
        private EventCollector $eventCollector,
        private FormFactoryInterface $formFactory,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array<string, array<string, array<string, mixed>>> eventType => type => description
     */
    public function all(bool $withProperties = true): array
    {
        $catalog = [];

        foreach ($this->eventCollector->getEventsArray() as $eventType => $events) {
            if (!is_array($events)) {
                continue;
            }

            foreach ($events as $type => $definition) {
                $entry = [
                    'type'        => (string) $type,
                    'eventType'   => (string) $eventType,
                    'label'       => (string) ($definition['label'] ?? $type),
                    'description' => (string) ($definition['description'] ?? ''),
                ];

                if ($withProperties) {
                    $entry['properties'] = $this->describeForm(
                        $definition['formType'] ?? null,
                        (array) ($definition['formTypeOptions'] ?? []),
                    );
                }

                $catalog[(string) $eventType][(string) $type] = $entry;
            }
        }

        return $catalog;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $type): ?array
    {
        foreach ($this->all() as $events) {
            if (isset($events[$type])) {
                return $events[$type];
            }
        }

        return null;
    }

    /**
     * Derive la forme du tableau "properties" a partir du FormType declare par
     * l evenement. Introspection best effort : certains formulaires exigent des
     * options que l on n a pas ici, on echoue alors silencieusement.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, array<string, mixed>>
     */
    private function describeForm(mixed $formType, array $options): array
    {
        if (!is_string($formType) || !class_exists($formType)) {
            return [];
        }

        try {
            $form = $this->formFactory->create($formType, null, $options);
        } catch (\Throwable $e) {
            $this->logger->debug('Witty: introspection de formulaire impossible', [
                'formType' => $formType,
                'error'    => $e->getMessage(),
            ]);

            return [];
        }

        $fields = [];

        foreach ($form->all() as $child) {
            $config = $child->getConfig();

            $fields[$child->getName()] = array_filter([
                'widget'   => (new \ReflectionClass($config->getType()->getInnerType()))->getShortName(),
                'required' => $config->getRequired(),
                'label'    => is_string($config->getOption('label')) ? $config->getOption('label') : null,
                'choices'  => $config->hasOption('choices') ? $this->flattenChoices($config->getOption('choices')) : null,
            ], static fn ($value): bool => null !== $value && [] !== $value && false !== $value);
        }

        return $fields;
    }

    /**
     * @return array<int, string>
     */
    private function flattenChoices(mixed $choices): array
    {
        if (!is_array($choices)) {
            return [];
        }

        $flat = [];

        array_walk_recursive($choices, static function ($value) use (&$flat): void {
            if (is_scalar($value)) {
                $flat[] = (string) $value;
            }
        });

        return array_slice(array_unique($flat), 0, 40);
    }
}
