<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Mautic\FormBundle\Entity\Field;
use Mautic\FormBundle\Event\SubmissionEvent;
use Mautic\FormBundle\Event\ValidationEvent;
use Mautic\FormBundle\FormEvents;
use Mautic\LeadBundle\Entity\Lead;
use MauticPlugin\WittyBundle\Entity\WittyMeetBooking;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetSlotAvailabilityCalculator;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Deux passes distinctes sur le champ "witty.meet_slot_picker" :
 *
 * 1. ON_FORM_VALIDATE (avant l'enregistrement de la soumission) : le creneau
 *    choisi est-il toujours dans la regle de recurrence du champ et pas deja
 *    reserve ? Purement lecture, cf. MeetSlotAvailabilityCalculator.
 *
 * 2. FORM_ON_SUBMIT (apres l'enregistrement de la soumission ET l'execution
 *    reussie de toutes les actions du formulaire) : la reservation elle-meme
 *    (insertion witty_meet_bookings). Ne PAS reserver des l'etape 1 : le
 *    formulaire valide les champs un par un dans une seule boucle et
 *    n'echoue qu'a la fin (cf. SubmissionModel::saveSubmission) -- reserver
 *    trop tot gaspillerait un creneau si un AUTRE champ du meme formulaire
 *    est ensuite juge invalide.
 *
 * La contrainte unique (field_id, slot_start) reste le rempart final contre
 * le double-booking en cas de course entre deux soumissions concurrentes
 * passees toutes les deux l'etape 1 avant que l'une des deux ne reserve :
 * cas accepte et documente plutot que de construire une reservation
 * transactionnelle complete bout en bout.
 */
class MeetSlotValidationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private MeetSlotAvailabilityCalculator $calculator,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            FormEvents::ON_FORM_VALIDATE => ['onFormValidate', 0],
            FormEvents::FORM_ON_SUBMIT   => ['onFormSubmit', 0],
        ];
    }

    public function onFormValidate(ValidationEvent $event): void
    {
        $field = $event->getField();

        if (FormSubscriber::SLOT_PICKER_FIELD_TYPE !== $field->getType()) {
            return;
        }

        $value = $event->getValue();

        if (empty($value)) {
            // Champ non requis et laisse vide : rien a valider (le cas
            // requis-et-vide est deja intercepte plus tot dans la boucle de
            // SubmissionModel::saveSubmission, avant meme d'arriver ici).
            return;
        }

        $slot = $this->parseSlot((string) $value);

        if (null === $slot) {
            $event->failedValidation($this->translator->trans('mautic.witty.meet.slotpicker.invalid_slot', [], 'validators'));

            return;
        }

        if (!$this->calculator->isSlotAvailable($field, $slot)) {
            $event->failedValidation($this->translator->trans('mautic.witty.meet.slotpicker.slot_unavailable', [], 'validators'));
        }
    }

    public function onFormSubmit(SubmissionEvent $event): void
    {
        $results = $event->getResults();
        $lead    = $event->getSubmission()->getLead();

        foreach ($event->getForm()->getFields() as $field) {
            if (FormSubscriber::SLOT_PICKER_FIELD_TYPE !== $field->getType()) {
                continue;
            }

            $value = $results[$field->getAlias()] ?? '';

            if ('' === $value) {
                continue;
            }

            $slot = $this->parseSlot((string) $value);

            if (null === $slot) {
                continue;
            }

            $this->reserveSlot($field, $lead, $slot);
        }
    }

    private function reserveSlot(Field $field, ?Lead $lead, \DateTimeImmutable $slot): void
    {
        $booking = new WittyMeetBooking();
        $booking->setField($field)
            ->setLead($lead)
            ->setSlotStart($slot);

        try {
            $this->em->persist($booking);
            $this->em->flush();
        } catch (UniqueConstraintViolationException $e) {
            // Course perdue face a une autre soumission concurrente : la
            // soumission de ce contact est deja enregistree a ce stade (cf.
            // docblock de la classe), on se contente de journaliser.
            $this->em->detach($booking);
            $this->logger->warning('Witty : creneau deja reserve par une autre soumission concurrente.', [
                'field_id'   => $field->getId(),
                'slot_start' => $slot->format(\DateTimeInterface::ATOM),
                'exception'  => $e->getMessage(),
            ]);
        }
    }

    private function parseSlot(string $value): ?\DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
