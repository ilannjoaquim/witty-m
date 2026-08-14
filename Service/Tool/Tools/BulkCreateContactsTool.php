<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\ListModel;
use MauticPlugin\WittyBundle\Service\Field\FieldWriteGuard;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree ou met a jour plusieurs contacts d'un coup, et les rattache
 * optionnellement a un segment existant en un seul appel.
 *
 * Pense pour operationnaliser le resultat d'une recherche de prospects (ex.
 * les outils MCP prospeo_search_person / prospeo_enrich_person, decouverts
 * dynamiquement si une cle Prospeo est renseignee — cf. Service/Mcp/ProspeoMcpClient.php) :
 * ces outils ne connaissent que des donnees Prospeo, jamais Mautic. C'est a
 * l'agent d'extraire, pour chaque prospect retenu, les champs pertinents
 * (email si disponible via enrich_person, prenom, nom, poste, entreprise,
 * LinkedIn...) dans la forme generique attendue ici — alias de champ contact
 * Mautic -> valeur, comme create_contact/import_leads_from_file.
 *
 * Contrairement a create_contact (qui exige un email et refuse le doublon),
 * un contact issu d'une recherche peut n'avoir aucun email (search_person
 * seul, avant enrichissement) : le dedoublonnage par email ne s'applique
 * qu'aux entrees qui en fournissent un, les autres sont toujours creees.
 * Meme politique de mise a jour que import_leads_from_file (doublon d'email
 * mis a jour, jamais refuse) : un import de masse ne doit pas echouer
 * ligne par ligne.
 */
class BulkCreateContactsTool extends AbstractTool
{
    private const MAX_CONTACTS = 500;

    public function __construct(
        private LeadModel $leadModel,
        private ListModel $listModel,
        private WittyConfig $config,
        private FieldWriteGuard $fieldWriteGuard,
    ) {
    }

    public function getName(): string
    {
        return 'bulk_create_contacts';
    }

    public function getDescription(): string
    {
        return 'Cree ou met a jour plusieurs contacts en un appel (plafonne a '.self::MAX_CONTACTS.'), et les '
            .'rattache optionnellement a un segment existant (segment_id, cree au prealable avec create_segment si '
            .'besoin). contacts est un tableau d objets, chacun un alias de champ contact Mautic (PAS le nom du champ '
            .'chez le fournisseur de donnees, ex. le champ Mautic est linkedin, pas linkedin_url — utilise list_fields '
            .'en cas de doute) -> valeur (ex. email, firstname, lastname, company, position, linkedin, city, country, '
            .'phone...) — comme create_contact/import_leads_from_file, mais email n est pas obligatoire ici (utile '
            .'pour des prospects pas encore enrichis, ex. resultats de prospeo_search_person avant '
            .'prospeo_enrich_person). Un contact avec le meme email est mis a jour, pas duplique ; sans email, '
            .'toujours cree.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:create';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'contacts' => [
                'type'        => 'array',
                'description' => 'Un objet par contact : alias de champ contact Mautic -> valeur. Voir create_contact pour les alias standards.',
                'items'       => ['type' => 'object'],
            ],
            'segment_id' => [
                'type'        => 'integer',
                'description' => 'Segment existant auquel rattacher tous les contacts crees/mis a jour (voir create_segment / list_entities avec entity=segment).',
            ],
        ], ['contacts']);
    }

    public function execute(array $arguments): array
    {
        $rawContacts = (array) ($arguments['contacts'] ?? []);

        if ([] === $rawContacts) {
            return ['status' => 'error', 'error' => 'contacts est obligatoire et ne peut pas etre vide.'];
        }

        if (count($rawContacts) > self::MAX_CONTACTS) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    'Trop de contacts pour un seul appel (%d, maximum %d). Repartis-les sur plusieurs appels.',
                    count($rawContacts),
                    self::MAX_CONTACTS,
                ),
            ];
        }

        [$valid, $invalidCount] = $this->normalizeContacts($rawContacts);

        if ([] === $valid) {
            return ['status' => 'error', 'error' => 'Aucun contact valide : chaque entree doit avoir au moins un champ non vide.'];
        }

        $prepared = $this->fieldWriteGuard->prepareMany($valid, 'lead');

        if ([] !== $prepared['unknown']) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Alias de champ inconnu : %s. Verifie l orthographe avec l outil list_fields (object: 'contact') avant de reessayer.",
                    implode(', ', $prepared['unknown']),
                ),
            ];
        }

        $valid = $prepared['rows'];

        $segment = null;

        if (!empty($arguments['segment_id'])) {
            $segment = $this->listModel->getEntity((int) $arguments['segment_id']);

            if (null === $segment) {
                return ['status' => 'error', 'error' => sprintf('Segment #%d introuvable.', (int) $arguments['segment_id'])];
            }
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired(array_filter([
                'type'          => 'bulk_contacts',
                'valid_count'   => count($valid),
                'invalid_count' => $invalidCount > 0 ? $invalidCount : null,
                'segment'       => $segment?->getName(),
                'sample'        => array_slice($valid, 0, 5),
            ], static fn ($value): bool => null !== $value));
        }

        [$created, $updated, $addedToSegment] = $this->importContacts($valid, $segment);

        return $this->ok([
            'created'          => $created,
            'updated'          => $updated,
            'invalid_count'    => $invalidCount,
            'added_to_segment' => $addedToSegment,
        ]);
    }

    /**
     * @param array<int, mixed> $rawContacts
     *
     * @return array{0: array<int, array<string, string>>, 1: int} [entrees valides, nombre d entrees rejetees]
     */
    private function normalizeContacts(array $rawContacts): array
    {
        $valid   = [];
        $invalid = 0;

        foreach ($rawContacts as $entry) {
            if (!is_array($entry)) {
                ++$invalid;
                continue;
            }

            $fields = array_filter(
                array_map('strval', $entry),
                static fn (string $value): bool => '' !== trim($value),
            );

            if ([] === $fields) {
                ++$invalid;
                continue;
            }

            $valid[] = $fields;
        }

        return [$valid, $invalid];
    }

    /**
     * @param array<int, array<string, string>> $contacts
     *
     * @return array{0: int, 1: int, 2: int} [created, updated, addedToSegment]
     */
    private function importContacts(array $contacts, ?object $segment): array
    {
        $created        = 0;
        $updated        = 0;
        $addedToSegment = 0;

        foreach ($contacts as $fields) {
            $email    = trim((string) ($fields['email'] ?? ''));
            $existing = '' !== $email ? $this->leadModel->getRepository()->findBy(['email' => $email], null, 1) : [];
            $lead     = $existing[0] ?? new Lead();

            $this->leadModel->setFieldValues($lead, $fields, false, false);
            $this->leadModel->saveEntity($lead);

            [] === $existing ? ++$created : ++$updated;

            if (null !== $segment) {
                $this->listModel->addLead($lead, [$segment->getId()], true);
                ++$addedToSegment;
            }
        }

        return [$created, $updated, $addedToSegment];
    }
}
