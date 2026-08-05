<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\PlugNmeet;

use Mautic\FormBundle\Entity\Field;
use MauticPlugin\WittyBundle\Entity\WittyMeetBookingRepository;

/**
 * Calcule les creneaux reservables d'un champ "witty.meet_slot_picker" a
 * partir de sa regle de recurrence (Field::properties) en retranchant les
 * creneaux deja reserves (witty_meet_bookings). Purement lecture : la
 * reservation elle-meme se fait a la validation du formulaire, cf.
 * EventListener/MeetSlotValidationSubscriber.php.
 */
class MeetSlotAvailabilityCalculator
{
    // Garde-fou, pas une contrainte metier : evite de calculer des creneaux
    // sans fin si quelqu'un navigue le calendrier tres loin dans le futur.
    public const MAX_ADVANCE_DAYS = 180;

    // Decalage fixe (pas de bascule DST automatique) : coherent avec le
    // choix "liste deroulante UTC" propose a l'organisateur comme au
    // visiteur (cf. utcOffsetChoices() ci-dessous et le widget JS du champ).
    public const DEFAULT_TIMEZONE = '+00:00';

    private const DEFAULT_SLOT_DURATION_MINUTES = 30;

    private const DEFAULT_BUFFER_DAYS = 1;

    public function __construct(
        private WittyMeetBookingRepository $bookingRepository,
    ) {
    }

    /**
     * Creneaux disponibles dans [$rangeStart, $rangeEnd), tronque au delai de
     * securite (buffer_days) et a MAX_ADVANCE_DAYS. Utilise pour peupler un
     * mois du calendrier (widget) ou une seule journee (validation, cf.
     * isSlotAvailable ci-dessous).
     *
     * @return \DateTimeImmutable[] instants de debut de creneau, tries par ordre chronologique
     */
    public function computeAvailableSlots(
        Field $field,
        \DateTimeImmutable $rangeStart,
        \DateTimeImmutable $rangeEnd,
        ?\DateTimeImmutable $now = null,
    ): array {
        $properties = $field->getProperties();
        $timezone   = new \DateTimeZone($this->normalizeTimezoneOffset((string) ($properties['timezone'] ?? self::DEFAULT_TIMEZONE)));

        // "9h" dans la regle de recurrence signifie 9h dans le fuseau choisi
        // par l'organisateur a la creation du champ, pas dans le fuseau
        // ambiant (souvent juste le defaut PHP du serveur) que portent
        // $rangeStart/$rangeEnd/$now a l'appel : on reancre les trois dans ce
        // fuseau avant tout calcul de jour/heure.
        $rangeStart = $this->inTimezone($rangeStart, $timezone);
        $rangeEnd   = $this->inTimezone($rangeEnd, $timezone);
        $now        = $this->inTimezone($now ?? new \DateTimeImmutable(), $timezone);

        $daysOfWeek = array_map('intval', (array) ($properties['days_of_week'] ?? [1, 2, 3, 4, 5]));
        $startTime  = $this->parseTime((string) ($properties['start_time'] ?? '09:00'));
        $endTime    = $this->parseTime((string) ($properties['end_time'] ?? '17:00'));
        $duration   = max(5, (int) ($properties['slot_duration_minutes'] ?? self::DEFAULT_SLOT_DURATION_MINUTES));
        $bufferDays = max(0, (int) ($properties['buffer_days'] ?? self::DEFAULT_BUFFER_DAYS));

        if ([] === $daysOfWeek || $endTime <= $startTime) {
            return [];
        }

        $earliestBookable = $now->modify(sprintf('+%d days', $bufferDays))->setTime(0, 0);
        $latestBookable    = $now->modify(sprintf('+%d days', self::MAX_ADVANCE_DAYS));

        $windowStart = max($rangeStart, $earliestBookable);
        $windowEnd   = min($rangeEnd, $latestBookable);

        if ($windowStart >= $windowEnd) {
            return [];
        }

        $bookedSlots = $this->bookingRepository->findBookedSlots((int) $field->getId(), $windowStart, $windowEnd);
        $booked      = [];
        foreach ($bookedSlots as $bookedSlot) {
            $booked[self::slotKey($bookedSlot)] = true;
        }

        $slots = [];
        $day   = $windowStart->setTime(0, 0);

        while ($day < $windowEnd) {
            if (in_array((int) $day->format('N'), $daysOfWeek, true)) {
                $slots = array_merge($slots, $this->slotsForDay($day, $startTime, $endTime, $duration, $windowStart, $booked));
            }
            $day = $day->modify('+1 day');
        }

        return $slots;
    }

    /**
     * Utilise par la validation de soumission (le creneau choisi est-il
     * toujours dans la regle de recurrence, pas deja reserve, et hors du
     * delai de securite ?). Ne recalcule que la journee du creneau : c'est le
     * meme calcul que le widget, on evite ainsi de dupliquer la logique de
     * recurrence sans avoir a recalculer tout un mois pour verifier un seul
     * instant.
     */
    public function isSlotAvailable(Field $field, \DateTimeImmutable $slot, ?\DateTimeImmutable $now = null): bool
    {
        $dayStart = $slot->setTime(0, 0);
        $dayEnd   = $dayStart->modify('+1 day');
        $target   = $slot->getTimestamp();

        foreach ($this->computeAvailableSlots($field, $dayStart, $dayEnd, $now) as $available) {
            if ($available->getTimestamp() === $target) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, int>          $startTime
     * @param array<string, int>          $endTime
     * @param array<string, true>         $booked
     *
     * @return \DateTimeImmutable[]
     */
    private function slotsForDay(
        \DateTimeImmutable $day,
        array $startTime,
        array $endTime,
        int $durationMinutes,
        \DateTimeImmutable $windowStart,
        array $booked,
    ): array {
        $cursor  = $day->setTime($startTime['hour'], $startTime['minute']);
        $dayEnd  = $day->setTime($endTime['hour'], $endTime['minute']);
        $slots   = [];

        while ($cursor->modify(sprintf('+%d minutes', $durationMinutes)) <= $dayEnd) {
            if ($cursor >= $windowStart && !isset($booked[self::slotKey($cursor)])) {
                $slots[] = $cursor;
            }
            $cursor = $cursor->modify(sprintf('+%d minutes', $durationMinutes));
        }

        return $slots;
    }

    /**
     * Liste des decalages UTC proposables, meme cote organisateur (proprietes
     * du champ, cf. Form/Type/MeetSlotPickerPropertiesType.php et
     * Service/Tool/Tools/CreateFormTool.php) que cote visiteur (widget JS,
     * meme granularite reconstruite en JS) : un seul catalogue "source de
     * verite" pour eviter toute divergence. Pas de fuseaux nommes (IANA) : un
     * decalage fixe, plus simple et previsible, au prix de ne pas suivre
     * automatiquement les changements d'heure ete/hiver.
     *
     * Granularite 15 min (pas 30) : necessaire pour couvrir les decalages
     * reels non ronds, ex. Nepal +05:45, Chatham +12:45.
     *
     * @return array<string, string> libelle "UTC+01:00" => valeur "+01:00"
     */
    public static function utcOffsetChoices(): array
    {
        $choices = [];

        for ($minutes = -12 * 60; $minutes <= 14 * 60; $minutes += 15) {
            $offset = self::formatOffset($minutes);
            $choices['UTC'.$offset] = $offset;
        }

        return $choices;
    }

    private static function formatOffset(int $minutes): string
    {
        $sign = $minutes < 0 ? '-' : '+';
        $abs  = abs($minutes);

        return sprintf('%s%02d:%02d', $sign, intdiv($abs, 60), $abs % 60);
    }

    private function normalizeTimezoneOffset(string $value): string
    {
        return 1 === preg_match('/^[+-](0\d|1[0-4]):[0-5]\d$/', $value) ? $value : self::DEFAULT_TIMEZONE;
    }

    /**
     * Reconstruit le meme instant "mur" (annee/mois/jour/heure/minute/seconde
     * affiches) mais rattache au fuseau donne, sans tenir compte du fuseau
     * que portait $dt a l'appel : c'est la date/heure telle qu'elle
     * apparaitrait sur une horloge murale, pas l'instant absolu, qui nous
     * interesse ici (cf. docblock de computeAvailableSlots()).
     */
    private function inTimezone(\DateTimeImmutable $dt, \DateTimeZone $timezone): \DateTimeImmutable
    {
        return new \DateTimeImmutable($dt->format('Y-m-d\TH:i:s'), $timezone);
    }

    /**
     * @return array{hour: int, minute: int}
     */
    private function parseTime(string $value): array
    {
        if (1 !== preg_match('/^(\d{1,2}):(\d{2})/', $value, $matches)) {
            return ['hour' => 9, 'minute' => 0];
        }

        return ['hour' => min(23, (int) $matches[1]), 'minute' => min(59, (int) $matches[2])];
    }

    // Cle de comparaison normalisee en UTC : l'instant comparé doit rester le
    // meme quelle que soit la timezone attachee a l'objet DateTime d'origine
    // (calcul local vs valeur hydratee depuis Doctrine).
    private static function slotKey(\DateTimeInterface $dt): string
    {
        return (new \DateTimeImmutable('@'.$dt->getTimestamp()))->format(\DateTimeInterface::ATOM);
    }
}
