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
            .'["meeting_scheduled_organizer_at", "meeting_scheduled_visitor_at", "phone"]. '
            .'La reponse inclut total (nombre de contacts correspondant a la requete, au-dela de cette seule '
            .'page) : pour parcourir un ensemble qui depasse 100 resultats, rappelle cet outil en augmentant '
            .'start de limit a chaque fois (start=0, puis 100, puis 200...) jusqu a avoir couvert total. '
            .'Reste cependant un outil de lecture page par page : pour une action en masse sur des milliers de '
            .'contacts (ex. dedoublonner un segment entier), previens l utilisateur qu il n existe pas encore '
            .'de traitement cote serveur pour ce cas, seulement l enumeration manuelle page par page.';
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
            'start'  => ['type' => 'integer', 'description' => 'Decalage de pagination (nombre de resultats a sauter). Defaut 0.'],
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

        // LeadRepository::getEntities() est un override complet (custom fields
        // via CustomFieldRepositoryTrait), PAS un Doctrine\ORM\Tools\Pagination\Paginator
        // comme la majorite des repositories Mautic : sans withTotalCount, il renvoie
        // juste le tableau de la page courante, dont count() ne dit rien du total
        // (verifie en le supposant Paginator : count() variait selon start, page par
        // page, jamais le vrai total). withTotalCount=true fait executer une vraie
        // requete COUNT(...) a part (avec le meme WHERE, GROUP BY neutralise) et
        // renvoie ['count' => total, 'results' => [id => Lead...]] -- jamais les
        // 55 000 lignes chargees pour un simple total.
        $response = $this->leadModel->getEntities([
            'start'          => max(0, (int) ($arguments['start'] ?? 0)),
            'limit'          => max(1, min(100, (int) ($arguments['limit'] ?? 20))),
            'filter'         => ['string' => (string) ($arguments['query'] ?? '')],
            'withTotalCount' => true,
        ]);

        $total    = (int) ($response['count'] ?? 0);
        $contacts = $response['results'] ?? [];

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

        return $this->ok([
            'count'    => count($items),
            'total'    => $total,
            'start'    => max(0, (int) ($arguments['start'] ?? 0)),
            'contacts' => $items,
        ]);
    }
}
