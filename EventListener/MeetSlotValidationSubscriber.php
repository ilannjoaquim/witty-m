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
use Mautic\LeadBundle\Model\LeadModel;
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
    /** Index = DateTime::format('N') - 1 (1=lundi ... 7=dimanche). */
    private const WEEKDAY_KEYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    public function __construct(
        private MeetSlotAvailabilityCalculator $calculator,
        private EntityManagerInterface $em,
        private TranslatorInterface $translator,
        private LoggerInterface $logger,
        private LeadModel $leadModel,
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
            $this->updateContactMeetingFields($event, $field, $lead, $slot);
        }
    }

    /**
     * Renseigne meeting_scheduled_organizer_at et meeting_scheduled_visitor_at
     * (cf. PluginSubscriber::provisionFields()) : deux champs contact texte,
     * deja formates en toutes lettres avec leur decalage explicite, pour que
     * ni l'organisateur (email.send.user) ni le prospect (email.send.lead)
     * n'aient de calcul de fuseau a refaire a la lecture de l'email.
     *
     * meeting_scheduled_organizer_at reprend directement $slot : parseSlot()
     * a deja parse la chaine ISO soumise AVEC son decalage (celui configure
     * sur le champ, cf. widget JS), donc $slot porte deja le bon fuseau.
     *
     * meeting_scheduled_visitor_at a besoin du decalage que le VISITEUR a
     * choisi sur le widget, transmis via un champ cache brut (pas un vrai
     * champ Mautic : inutile de l'exposer comme {formfield=...}, seul ce
     * listener le lit) nomme mauticform[<alias>__visitor_tz].
     */
    private function updateContactMeetingFields(SubmissionEvent $event, Field $field, ?Lead $lead, \DateTimeImmutable $slot): void
    {
        if (null === $lead) {
            return;
        }

        // SubmissionEvent::getPost() renvoie deja le tableau 'mauticform'
        // "deballe" (cf. FormBundle\Controller\PublicController::submitAction,
        // $post = $request->request->all()['mauticform'] ?? []) : pas de
        // cle 'mauticform' supplementaire a lire ici.
        $post = $event->getPost();
        $visitorOffsetRaw = (string) ($post[$field->getAlias().'__visitor_tz'] ?? '');
        $visitorOffset    = $this->normalizeOffset($visitorOffsetRaw, $slot->format('P'));

        try {
            $visitorSlot = $slot->setTimezone(new \DateTimeZone($visitorOffset));
        } catch (\Exception) {
            $visitorSlot = $slot;
        }

        // Anglais par defaut pour tout formulaire, francais uniquement si la
        // langue du formulaire (Builder > Options > Language) commence par
        // "fr" (fr_FR, fr_CA...) : cf. Form::getLanguage().
        $formLanguage = (string) ($event->getForm()->getLanguage() ?? '');
        $isFrench     = str_starts_with(strtolower($formLanguage), 'fr');

        $this->leadModel->setFieldValues($lead, [
            PluginSubscriber::MEETING_ORGANIZER_TIME_FIELD_ALIAS => $this->formatMeetingTime($slot, $isFrench),
            PluginSubscriber::MEETING_VISITOR_TIME_FIELD_ALIAS   => $this->formatMeetingTime($visitorSlot, $isFrench),
        ], false, false);
        $this->leadModel->saveEntity($lead);
    }

    /**
     * Jour et date en toutes lettres ("Lundi 20 septembre 2026" /
     * "Monday 20 September 2026"), heure au format local ("11h30" en
     * francais, "11:30" en anglais) et decalage UTC explicite entre
     * parentheses, langue-independant : le libelle reste sans ambiguite
     * meme pour quelqu'un qui ne lit pas la langue du formulaire.
     */
    private function formatMeetingTime(\DateTimeImmutable $dt, bool $isFrench): string
    {
        $locale = $isFrench ? 'fr_FR' : 'en_US';

        $weekday = $this->translator->trans(
            'mautic.witty.meet.slotpicker.day.'.self::WEEKDAY_KEYS[((int) $dt->format('N')) - 1],
            [],
            null,
            $locale
        );
        $month = $this->translator->trans('mautic.witty.meet.slotpicker.month.'.$dt->format('n'), [], null, $locale);

        $datePart = sprintf('%s %s %s %s', $weekday, $dt->format('j'), $month, $dt->format('Y'));

        if ($isFrench) {
            $minutes  = (int) $dt->format('i');
            $timePart = $dt->format('G').'h'.('0' === (string) $minutes ? '' : sprintf('%02d', $minutes));
            $connector = 'à';
        } else {
            $timePart  = $dt->format('H:i');
            $connector = 'at';
        }

        return sprintf('%s %s %s (UTC%s)', $datePart, $connector, $timePart, $dt->format('P'));
    }

    private function normalizeOffset(string $value, string $default): string
    {
        return 1 === preg_match('/^[+-](0\d|1[0-4]):[0-5]\d$/', $value) ? $value : $default;
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
