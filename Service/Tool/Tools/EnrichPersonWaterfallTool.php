<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\CoreBundle\Helper\UserHelper;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\LeadModel;
use MauticPlugin\WittyBundle\Entity\WittyApolloWaterfallRequest;
use MauticPlugin\WittyBundle\Service\Apollo\ApolloClient;
use MauticPlugin\WittyBundle\Service\Apollo\Exception\ApolloException;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Lance un enrichissement Apollo "waterfall" (email et/ou telephone) pour UN
 * profil — https://docs.apollo.io/docs/enrich-phone-and-email-using-data-waterfall
 *
 * Contrairement a enrich_person (synchrone, reponse immediate mais limite a
 * ce qu Apollo connait deja), le waterfall interroge des sources
 * supplementaires en cascade et repond de facon ASYNCHRONE : cet outil ne
 * renvoie donc jamais l email/le telephone lui-meme, seulement un request_id
 * a repasser plus tard a check_waterfall_enrichment (le resultat arrive via
 * webhook, parfois plusieurs minutes apres, cf.
 * Controller/ApolloWaterfallWebhookController.php).
 *
 * `mode` est OBLIGATOIRE et n a AUCUNE valeur par defaut cote description
 * (l agent doit le deduire de la demande de l utilisateur, jamais deviner) :
 * email seul, telephone seul, ou les deux — le cout en credits Apollo differe
 * fortement selon le choix, l utilisateur doit donc l avoir exprime
 * explicitement (ex. "trouve-moi son email" = email, "trouve son numero" =
 * phone, "enrichis-le completement" = both). Ne jamais mettre both par
 * defaut faute de precision : redemander a l utilisateur.
 */
class EnrichPersonWaterfallTool extends AbstractTool
{
    public function __construct(
        private ApolloClient $apollo,
        private LeadModel $leadModel,
        private UserHelper $userHelper,
        private UrlGeneratorInterface $router,
        private EntityManagerInterface $entityManager,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'enrich_person_waterfall';
    }

    public function getDescription(): string
    {
        return 'Lance un enrichissement Apollo "waterfall" (sources en cascade, plus complet et plus cher que '
            .'enrich_person) pour reveler l email et/ou le telephone d UN profil. ASYNCHRONE : ne renvoie jamais la '
            .'valeur trouvee, seulement un request_id a repasser a check_waterfall_enrichment plus tard (le resultat '
            .'peut prendre plusieurs minutes a arriver). mode est OBLIGATOIRE : "email" (uniquement l email), '
            .'"phone" (uniquement le telephone) ou "both" (les deux) — reflete exactement ce que l utilisateur a '
            .'demande, ne jamais choisir both par defaut, le cout en credits differe fortement selon le choix. '
            .'Fournis soit contact_id (contact Mautic existant, ses champs servent d identifiants), soit au moins un '
            .'identifiant (name/email/domain/linkedin_url...).';
    }

    public function getObjectType(): ?string
    {
        return null;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:viewown';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'mode'              => ['type' => 'string', 'enum' => ['email', 'phone', 'both'], 'description' => 'Obligatoire, doit refleter exactement la demande de l utilisateur.'],
            'contact_id'        => ['type' => 'integer', 'description' => 'Contact Mautic existant. Ses champs (email, nom, entreprise) servent d identifiants si non fournis explicitement.'],
            'first_name'        => ['type' => 'string'],
            'last_name'         => ['type' => 'string'],
            'name'              => ['type' => 'string', 'description' => 'Nom complet, alternative a first_name/last_name.'],
            'email'             => ['type' => 'string'],
            'organization_name' => ['type' => 'string', 'description' => 'Employeur actuel ou passe.'],
            'domain'            => ['type' => 'string', 'description' => 'Domaine de l employeur (sans www., ex. apollo.io).'],
            'id'                => ['type' => 'string', 'description' => 'Identifiant Apollo de la personne, si deja connu.'],
            'linkedin_url'      => ['type' => 'string'],
        ], ['mode']);
    }

    public function execute(array $arguments): array
    {
        $mode = (string) ($arguments['mode'] ?? '');

        if (!in_array($mode, [WittyApolloWaterfallRequest::MODE_EMAIL, WittyApolloWaterfallRequest::MODE_PHONE, WittyApolloWaterfallRequest::MODE_BOTH], true)) {
            return ['status' => 'error', 'error' => 'mode est obligatoire et doit valoir email, phone ou both.'];
        }

        $lead = null;

        if (!empty($arguments['contact_id'])) {
            $lead = $this->leadModel->getEntity((int) $arguments['contact_id']);

            if (!$lead instanceof Lead) {
                return ['status' => 'error', 'error' => sprintf('Contact #%d introuvable.', (int) $arguments['contact_id'])];
            }
        }

        $params = array_filter([
            'first_name'        => trim((string) ($arguments['first_name'] ?? ($lead?->getFirstname() ?? ''))),
            'last_name'         => trim((string) ($arguments['last_name'] ?? ($lead?->getLastname() ?? ''))),
            'name'              => trim((string) ($arguments['name'] ?? '')),
            'email'             => trim((string) ($arguments['email'] ?? ($lead?->getEmail() ?? ''))),
            'organization_name' => trim((string) ($arguments['organization_name'] ?? ($lead?->getCompany() ?? ''))),
            'domain'            => trim((string) ($arguments['domain'] ?? '')),
            'id'                => trim((string) ($arguments['id'] ?? '')),
            'linkedin_url'      => trim((string) ($arguments['linkedin_url'] ?? '')),
        ], static fn (string $value): bool => '' !== $value);

        if ([] === $params) {
            return ['status' => 'error', 'error' => 'Au moins un identifiant est obligatoire (contact_id, name, email, domain, id, linkedin_url...).'];
        }

        $params['run_waterfall_email'] = in_array($mode, [WittyApolloWaterfallRequest::MODE_EMAIL, WittyApolloWaterfallRequest::MODE_BOTH], true) ? 'true' : 'false';
        $params['run_waterfall_phone'] = in_array($mode, [WittyApolloWaterfallRequest::MODE_PHONE, WittyApolloWaterfallRequest::MODE_BOTH], true) ? 'true' : 'false';
        $params['webhook_url']         = $this->router->generate(
            'witty_apollo_waterfall_webhook',
            ['token' => $this->config->getApolloWebhookToken()],
            UrlGeneratorInterface::ABSOLUTE_URL,
        );

        try {
            $response = $this->apollo->get('/people/match', $params);
        } catch (ApolloException $e) {
            return ['status' => 'error', 'error' => $e->getMessage()];
        }

        $requestId = (string) ($response['request_id'] ?? '');
        $waterfall = is_array($response['waterfall'] ?? null) ? $response['waterfall'] : [];
        $wfStatus  = (string) ($waterfall['status'] ?? '');

        if ('' === $requestId || 'accepted' !== $wfStatus) {
            return ['status' => 'error', 'error' => '' !== (string) ($waterfall['message'] ?? '')
                ? (string) $waterfall['message']
                : 'Apollo n a pas accepte la demande waterfall (reponse inattendue).'];
        }

        $pending = (new WittyApolloWaterfallRequest())
            ->setRequestId($requestId)
            ->setLead($lead)
            ->setCreatedBy($this->userHelper->getUser())
            ->setMode($mode)
            ->setStatus(WittyApolloWaterfallRequest::STATUS_PENDING)
            ->setLabel($this->buildLabel($lead, $params));

        $this->entityManager->persist($pending);
        $this->entityManager->flush();

        return $this->ok([
            'request_id' => $requestId,
            'mode'       => $mode,
            'message'    => 'Demande acceptee par Apollo. Le resultat est asynchrone (peut prendre plusieurs minutes) : '
                .'rappelle check_waterfall_enrichment(request_id="'.$requestId.'") plus tard pour le recuperer.',
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildLabel(?Lead $lead, array $params): string
    {
        if ($lead instanceof Lead) {
            $name = trim($lead->getFirstname().' '.$lead->getLastname());

            return '' !== $name ? $name : (string) ($lead->getEmail() ?? sprintf('Contact #%d', $lead->getId()));
        }

        $name = trim((string) ($params['name'] ?? trim(($params['first_name'] ?? '').' '.($params['last_name'] ?? ''))));

        return '' !== $name ? $name : (string) ($params['email'] ?? ($params['linkedin_url'] ?? 'profil sans nom'));
    }
}
