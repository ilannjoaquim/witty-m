<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Contact;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\LeadList;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;

/**
 * Cree/met a jour UN contact, de deux facons distinctes selon ce qu on sait de
 * lui :
 * - `importOne()` — dedoublonnage par email quand fourni, toujours cree
 *   sinon. Meme logique que BulkCreateContactsTool::importContacts(),
 *   extraite ici pour etre reutilisee par
 *   Service/Job/Handlers/ImportContactsFromJobHandler.php sans dupliquer la
 *   boucle — BulkCreateContactsTool lui-meme n'a pas ete retouche (deja
 *   teste/stable), ce service est reserve au nouveau chemin "import depuis un
 *   job source".
 * - `updateById()` — met a jour un contact dont l'id Mautic est DEJA connu
 *   avec certitude (cas d'un job d'enrichissement sur un segment existant,
 *   ex. ApolloBulkEnrichPeopleJobHandler, qui stocke l'id du contact comme
 *   `external_ref`) : jamais de recherche par email (moins fiable, et
 *   inutile puisqu on a deja l'id exact), jamais de creation — un
 *   enrichissement met a jour un contact qui existe deja, par definition.
 */
class ContactImporter
{
    public function __construct(
        private LeadModel $leadModel,
        private ListModel $listModel,
    ) {
    }

    /**
     * @param array<string, string> $fields
     *
     * @return array{created: bool, lead: Lead}
     */
    public function importOne(array $fields, ?LeadList $segment): array
    {
        $email    = trim((string) ($fields['email'] ?? ''));
        $existing = '' !== $email ? $this->leadModel->getRepository()->findBy(['email' => $email], null, 1) : [];
        $lead     = $existing[0] ?? new Lead();

        $this->leadModel->setFieldValues($lead, $fields, false, false);
        $this->leadModel->saveEntity($lead);

        if (null !== $segment) {
            $this->listModel->addLead($lead, [$segment->getId()], true);
        }

        return ['created' => [] === $existing, 'lead' => $lead];
    }

    /**
     * @param array<string, string> $fields
     */
    public function updateById(int $leadId, array $fields, ?LeadList $segment): ?Lead
    {
        $lead = $this->leadModel->getEntity($leadId);

        if (!$lead instanceof Lead) {
            return null;
        }

        $this->leadModel->setFieldValues($lead, $fields, false, false);
        $this->leadModel->saveEntity($lead);

        if (null !== $segment) {
            $this->listModel->addLead($lead, [$segment->getId()], true);
        }

        return $lead;
    }
}
