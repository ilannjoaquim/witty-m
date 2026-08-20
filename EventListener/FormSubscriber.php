<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Mautic\FormBundle\Event\FormBuilderEvent;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\WittyBundle\Form\Type\ActionAddDoNotContactType;
use MauticPlugin\WittyBundle\Form\Type\CreateMeetInvitationLinkActionType;
use MauticPlugin\WittyBundle\Form\Type\MeetSlotPickerPropertiesType;
use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Action de formulaire "Creer un lien invitation meet" (onglet Actions d'un
 * formulaire) : meme logique que l'action de campagne homonyme
 * (EventListener/CampaignSubscriber.php), factorisee dans MeetInvitationCreator,
 * mais declenchee directement a la soumission d'un formulaire, sans avoir a
 * passer par une campagne.
 *
 * Toutes les actions de formulaire partagent le meme evenement d'execution
 * (FormEvents::ON_EXECUTE_SUBMIT_ACTION) : c'est checkContext() qui filtre sur
 * la notre, pas un evenement dedie comme cote CampaignBundle.
 */
class FormSubscriber implements EventSubscriberInterface
{
    // Public : reutilisee par Service/Tool/Tools/CreateFormTool.php pour que
    // l'agent IA puisse ajouter cette action a un formulaire qu'il cree.
    public const ACTION_KEY = 'witty.create_meet_invitation_link';

    public const SLOT_PICKER_FIELD_TYPE = 'witty.meet_slot_picker';

    // Action "Ajouter en Ne Plus Contacter" (public, meme raison que
    // ACTION_KEY ci-dessus). Mautic core n'expose QUE l'inverse
    // (LeadBundle\EventListener\FormSubscriber::onFormSubmitActionRemoveFromDoNotContact,
    // action 'lead.remove_do_not_contact') : aucune action de formulaire
    // native pour AJOUTER un contact en DNC, seulement le lien de
    // desinscription automatique {unsubscribe_url} glisse dans les emails.
    // Ce lien pose un vrai probleme de fiabilite en production (rapporte en
    // session) : les scanners de securite email (Outlook Safe Links,
    // proxys anti-phishing d'entreprise...) suivent/pre-chargent chaque lien
    // d'un email recu AVANT meme que le destinataire humain ne l'ouvre, pour
    // en verifier l'innocuite -- ce qui declenche le lien de desinscription
    // a un clic aussi surement qu'un vrai clic humain, desabonnant des
    // contacts qui n'ont jamais rien demande. Un formulaire (page reelle a
    // charger PUIS bouton a soumettre) est resistant a ce comportement
    // precis : un bot qui suit un lien ne remplit jamais un formulaire.
    public const DNC_ACTION_KEY = 'witty.add_do_not_contact';

    public function __construct(
        private MeetInvitationCreator $creator,
        private ContactTracker $contactTracker,
        private DoNotContact $doNotContact,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::FORM_ON_BUILD            => ['onFormBuilder', 0],
            FormEvents::ON_EXECUTE_SUBMIT_ACTION => [
                ['onSubmitActionCreateInvitation', 0],
                ['onSubmitActionAddDoNotContact', 0],
            ],
        ];
    }

    public function onFormBuilder(FormBuilderEvent $event): void
    {
        $event->addSubmitAction(self::ACTION_KEY, [
            'group'       => 'mautic.witty.meet.submitaction',
            'label'       => 'mautic.witty.meet.action.create_invitation',
            'description' => 'mautic.witty.meet.action.create_invitation_descr',
            'formType'    => CreateMeetInvitationLinkActionType::class,
            'eventName'   => FormEvents::ON_EXECUTE_SUBMIT_ACTION,
        ]);

        $event->addSubmitAction(self::DNC_ACTION_KEY, [
            'group'       => 'mautic.witty.lead.submitaction',
            'label'       => 'mautic.witty.lead.action.add_do_not_contact',
            'description' => 'mautic.witty.lead.action.add_do_not_contact_descr',
            'formType'    => ActionAddDoNotContactType::class,
            'eventName'   => FormEvents::ON_EXECUTE_SUBMIT_ACTION,
        ]);

        $event->addFormField(self::SLOT_PICKER_FIELD_TYPE, [
            'label'          => 'mautic.witty.meet.field.slot_picker',
            'formType'       => MeetSlotPickerPropertiesType::class,
            'template'       => '@Witty/Field/meet_slot_picker.html.twig',
            'builderOptions' => [
                // Un creneau choisi n'a pas de sens comme valeur pre-remplie.
                'addDefaultValue' => false,
            ],
        ]);

        // Sans cet enregistrement, SubmissionModel::validateFieldValue() ne
        // dispatche jamais ON_FORM_VALIDATE pour ce type de champ (il ne
        // dispatche que pour les types presents dans cette table), et
        // MeetSlotValidationSubscriber::onFormValidate() ne serait jamais
        // appele.
        $event->addValidator('witty.meet_slot_picker.validation', [
            'eventName' => FormEvents::ON_FORM_VALIDATE,
            'fieldType' => self::SLOT_PICKER_FIELD_TYPE,
        ]);
    }

    public function onSubmitActionCreateInvitation(SubmissionEvent $event): void
    {
        if (false === $event->checkContext(self::ACTION_KEY)) {
            return;
        }

        $lead = $this->contactTracker->getContact();

        if (null === $lead) {
            return;
        }

        try {
            $this->creator->createFromActionConfig($lead, $event->getActionConfig());
        } catch (PlugNmeetException $e) {
            // Un envoi de formulaire ne doit jamais echouer a cause d'une
            // salle indisponible : on journalise et on laisse le reste de la
            // soumission (autres actions, redirection...) suivre son cours.
            $this->logger->warning('Witty : impossible de generer un lien invitation meet (formulaire).', [
                'lead_id'   => $lead->getId(),
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Channel et reason volontairement fixes (jamais configurables depuis le
     * formulaire ni par l'agent) : 'email' est le seul canal concerne par un
     * formulaire de desinscription, et DNC::UNSUBSCRIBED est la SEULE raison
     * correcte pour une action initiee par le contact lui-meme -- BOUNCED/
     * MANUAL ont un sens different cote Mautic (rapports de deliverabilite,
     * action d'un utilisateur interne) qu'il ne faut jamais laisser choisir
     * par erreur. Aucune propriete a configurer : ActionAddDoNotContactType
     * est volontairement vide, comme l'action inverse du coeur Mautic
     * (Mautic\LeadBundle\Form\Type\ActionRemoveDoNotContact).
     */
    public function onSubmitActionAddDoNotContact(SubmissionEvent $event): void
    {
        if (false === $event->checkContext(self::DNC_ACTION_KEY)) {
            return;
        }

        $lead = $this->contactTracker->getContact();

        if (null === $lead) {
            return;
        }

        $this->doNotContact->addDncForContact($lead->getId(), 'email', DNC::UNSUBSCRIBED);
    }
}
