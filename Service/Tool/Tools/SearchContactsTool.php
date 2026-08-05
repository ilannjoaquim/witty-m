<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;

class SearchContactsTool extends AbstractTool
{
    public function __construct(private LeadModel $leadModel)
    {
    }

    public function getName(): string
    {
        return 'search_contacts';
    }

    public function getDescription(): string
    {
        return 'Recherche des contacts avec la syntaxe de recherche Mautic '
            .'(ex : "email:*@acme.com", "segment:prospects", "tag:vip"). '
            .'Par defaut ne renvoie que id/email/name/points ; passe fields pour recuperer en plus '
            .'n importe quel alias de champ contact (standard ou personnalise), ex. '
            .'["meeting_scheduled_organizer_at", "meeting_scheduled_visitor_at", "phone"].';
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'query'  => ['type' => 'string', 'description' => 'Chaine de recherche Mautic.'],
            'limit'  => ['type' => 'integer', 'description' => 'Defaut 20, max 100.'],
            'fields' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Alias de champs contact supplementaires a inclure dans chaque resultat '
                    .'(standard ou personnalise, ex. meeting_scheduled_organizer_at, phone, company). '
                    .'Un alias inconnu ou vide pour ce contact est simplement omis, pas d erreur.',
            ],
        ], ['query']);
    }

    public function execute(array $arguments): array
    {
        $extraFields = array_values(array_unique(array_filter(
            array_map('strval', (array) ($arguments['fields'] ?? [])),
            static fn (string $alias): bool => '' !== trim($alias)
        )));

        $contacts = $this->leadModel->getEntities([
            'start'  => 0,
            'limit'  => max(1, min(100, (int) ($arguments['limit'] ?? 20))),
            'filter' => ['string' => (string) ($arguments['query'] ?? '')],
        ]);

        $items = [];

        foreach ($contacts as $contact) {
            if (!$contact instanceof Lead) {
                continue;
            }

            $item = [
                'id'     => $contact->getId(),
                'email'  => $contact->getEmail(),
                'name'   => trim((string) $contact->getFirstname().' '.(string) $contact->getLastname()),
                'points' => $contact->getPoints(),
            ];

            if ([] !== $extraFields) {
                $fieldValues = [];

                foreach ($extraFields as $alias) {
                    // getFieldValue() couvre aussi bien les proprietes
                    // standard (phone, company...) que les champs
                    // personnalises, et renvoie null sans erreur pour un
                    // alias inconnu (cf. CustomFieldEntityTrait::getFieldValue()).
                    $value = $contact->getFieldValue($alias);

                    if (null !== $value && '' !== $value) {
                        $fieldValues[$alias] = $value;
                    }
                }

                if ([] !== $fieldValues) {
                    $item['fields'] = $fieldValues;
                }
            }

            $items[] = $item;
        }

        return $this->ok(['count' => count($items), 'contacts' => $items]);
    }
}
