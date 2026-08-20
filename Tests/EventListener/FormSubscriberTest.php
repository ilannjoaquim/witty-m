<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Tests\EventListener;

use Mautic\FormBundle\Entity\Action;
use Mautic\FormBundle\Entity\Submission;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\LeadBundle\Entity\DoNotContact as DNC;
use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Model\DoNotContact;
use Mautic\LeadBundle\Tracker\ContactTracker;
use MauticPlugin\WittyBundle\EventListener\FormSubscriber;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use Psr\Log\LoggerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * Verifie le CABLAGE de l'action "Ajouter en Ne Plus Contacter"
 * (onSubmitActionAddDoNotContact) : le comportement reel de DoNotContact::addDncForContact()
 * (code coeur Mautic) est deja verifie contre la vraie base locale via un
 * harnais manuel (dispatch reel de FormEvents::ON_EXECUTE_SUBMIT_ACTION,
 * meme mecanisme que SubmissionModel::executeFormActions()) -- ce test isole
 * ce qui n'est pas couvert par cette verification manuelle : le filtrage par
 * checkContext(), channel/reason fixes en dur, et l'absence de plantage sans
 * contact identifie.
 */
class FormSubscriberTest extends TestCase
{
    private function subscriber(ContactTracker $contactTracker, DoNotContact $doNotContact): FormSubscriber
    {
        return new FormSubscriber(
            $this->createMock(MeetInvitationCreator::class),
            $contactTracker,
            $doNotContact,
            $this->createMock(LoggerInterface::class),
        );
    }

    private function eventForAction(string $actionType): SubmissionEvent
    {
        $action = new Action();
        $action->setType($actionType);

        $submission = new Submission();
        $event      = new SubmissionEvent($submission, [], [], Request::create('/'));
        $event->setAction($action);

        return $event;
    }

    public function testMarksTheTrackedContactAsUnsubscribedOnEmail(): void
    {
        $lead = new Lead();
        (new \ReflectionProperty(Lead::class, 'id'))->setValue($lead, 42);

        $contactTracker = $this->createMock(ContactTracker::class);
        $contactTracker->method('getContact')->willReturn($lead);

        $doNotContact = $this->createMock(DoNotContact::class);
        $doNotContact->expects($this->once())
            ->method('addDncForContact')
            ->with(42, 'email', DNC::UNSUBSCRIBED);

        $subscriber = $this->subscriber($contactTracker, $doNotContact);
        $subscriber->onSubmitActionAddDoNotContact($this->eventForAction(FormSubscriber::DNC_ACTION_KEY));
    }

    public function testDoesNothingWhenTheContextDoesNotMatchThisAction(): void
    {
        $contactTracker = $this->createMock(ContactTracker::class);
        $contactTracker->expects($this->never())->method('getContact');

        $doNotContact = $this->createMock(DoNotContact::class);
        $doNotContact->expects($this->never())->method('addDncForContact');

        // Meme evenement partage par toutes les actions de formulaire :
        // une autre action (ex. l'invitation meet) ne doit jamais declencher
        // celle-ci.
        $subscriber = $this->subscriber($contactTracker, $doNotContact);
        $subscriber->onSubmitActionAddDoNotContact($this->eventForAction(FormSubscriber::ACTION_KEY));
    }

    public function testDoesNothingWithoutAnIdentifiedContactRatherThanThrowing(): void
    {
        $contactTracker = $this->createMock(ContactTracker::class);
        $contactTracker->method('getContact')->willReturn(null);

        $doNotContact = $this->createMock(DoNotContact::class);
        $doNotContact->expects($this->never())->method('addDncForContact');

        $subscriber = $this->subscriber($contactTracker, $doNotContact);
        $subscriber->onSubmitActionAddDoNotContact($this->eventForAction(FormSubscriber::DNC_ACTION_KEY));
    }

    public function testTheActionKeyIsRegisteredForTheAgentToUseInCreateFormUpdateForm(): void
    {
        // FormDefinitions::ACTION_TYPES doit connaitre cette action pour que
        // create_form/update_form l'acceptent sans la rejeter comme type
        // d'action inconnu.
        $this->assertContains(FormSubscriber::DNC_ACTION_KEY, \MauticPlugin\WittyBundle\Service\Form\FormDefinitions::ACTION_TYPES);
    }
}
