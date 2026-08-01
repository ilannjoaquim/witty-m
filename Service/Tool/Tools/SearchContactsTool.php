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
            .'(ex : "email:*@acme.com", "segment:prospects", "tag:vip").';
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'query' => ['type' => 'string', 'description' => 'Chaine de recherche Mautic.'],
            'limit' => ['type' => 'integer', 'description' => 'Defaut 20, max 100.'],
        ], ['query']);
    }

    public function execute(array $arguments): array
    {
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

            $items[] = [
                'id'     => $contact->getId(),
                'email'  => $contact->getEmail(),
                'name'   => trim((string) $contact->getFirstname().' '.(string) $contact->getLastname()),
                'points' => $contact->getPoints(),
            ];
        }

        return $this->ok(['count' => count($items), 'contacts' => $items]);
    }
}
