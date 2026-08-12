<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Form;

use MauticPlugin\WittyBundle\EventListener\FormSubscriber;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetSlotAvailabilityCalculator;

/**
 * Construit le tableau `properties` d un champ/d une action de formulaire a
 * partir de la definition transmise par le modele (meme forme dans
 * create_form et update_form — extrait de CreateFormTool pour que les deux
 * outils partagent exactement la meme logique, plutot que deux copies
 * pouvant diverger avec le temps).
 */
class FormPropertyBuilder
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function buildFieldProperties(string $type, array $definition): array
    {
        return match ($type) {
            FormSubscriber::SLOT_PICKER_FIELD_TYPE => $this->slotPickerProperties((array) ($definition['slot_picker'] ?? [])),
            'select', 'radiogrp', 'checkboxgrp' => $this->choiceProperties((array) ($definition['options'] ?? [])),
            'freehtml' => ['text' => (string) ($definition['html'] ?? '')],
            // Le template pagebreak.html.twig lit ces deux cles sans
            // |default() (constate en pratique : RuntimeError "Key ... does
            // not exist" si properties est vide) ; il les faut donc toujours,
            // meme si l'appelant ne les demande pas explicitement.
            'pagebreak' => [
                'prev_page_label' => 'Precedent',
                'next_page_label' => 'Suivant',
            ],
            'slider' => [
                'min'  => (int) ($definition['min'] ?? 0),
                'max'  => (int) ($definition['max'] ?? 100),
                'step' => max(1, (int) ($definition['step'] ?? 1)),
            ],
            'file' => [
                'allowed_file_extensions' => [] !== ($definition['allowed_file_extensions'] ?? [])
                    ? array_values(array_map('strval', (array) $definition['allowed_file_extensions']))
                    : ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png'],
                'allowed_file_size' => max(1, (int) ($definition['allowed_file_size_mb'] ?? 6)),
                'public'            => false,
            ],
            default => [],
        };
    }

    /**
     * @param array<string> $options
     *
     * @return array<string, mixed>
     */
    public function choiceProperties(array $options): array
    {
        $options = array_values(array_filter($options));

        if ([] === $options) {
            return [];
        }

        return [
            'syncList' => 0,
            'list'     => array_map(
                static fn (string $value): array => ['label' => $value, 'value' => $value],
                array_map('strval', $options),
            ),
        ];
    }

    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public function buildActionProperties(string $type, array $definition): array
    {
        if (FormSubscriber::ACTION_KEY === $type) {
            $targetField = (string) ($definition['target_field'] ?? MeetInvitationCreator::FIELD_MEETING);

            if (!in_array($targetField, [MeetInvitationCreator::FIELD_WEBINAR, MeetInvitationCreator::FIELD_MEETING], true)) {
                $targetField = MeetInvitationCreator::FIELD_MEETING;
            }

            return [
                'target_field' => $targetField,
                'room_id'      => trim((string) ($definition['room_id'] ?? '')),
            ];
        }

        return match ($type) {
            'email.send.lead' => ['email' => (int) ($definition['email_id'] ?? 0)],
            'email.send.user' => [
                'useremail' => ['email' => (int) ($definition['email_id'] ?? 0)],
                'user_id'   => array_values(array_filter(array_map('intval', (array) ($definition['user_ids'] ?? [])))),
            ],
            'lead.changelist' => [
                'addToLists'      => array_map('intval', (array) ($definition['add_to_segments'] ?? [])),
                'removeFromLists' => array_map('intval', (array) ($definition['remove_from_segments'] ?? [])),
            ],
            'lead.changetags' => [
                'add_tags'    => array_values(array_map('strval', (array) ($definition['add_tags'] ?? []))),
                'remove_tags' => array_values(array_map('strval', (array) ($definition['remove_tags'] ?? []))),
            ],
            'lead.updatelead' => array_map('strval', (array) ($definition['update_fields'] ?? [])),
            'lead.pointschange' => [
                'operator' => in_array($op = (string) ($definition['points_operator'] ?? 'plus'), FormDefinitions::POINTS_OPERATORS, true) ? $op : 'plus',
                'points'   => (int) ($definition['points'] ?? 0),
                'group'    => isset($definition['points_group_id']) ? (int) $definition['points_group_id'] : null,
            ],
            'form.email' => [
                'subject'        => (string) ($definition['email_subject'] ?? ''),
                'message'        => (string) ($definition['email_message'] ?? ''),
                'immediately'    => false,
                'copy_lead'      => false,
                'set_replyto'    => true,
                'email_to_owner' => false,
                'to'             => (string) ($definition['email_to'] ?? ''),
                'cc'             => (string) ($definition['email_cc'] ?? ''),
                'bcc'            => (string) ($definition['email_bcc'] ?? ''),
            ],
            'form.repost' => [
                'post_url'             => (string) ($definition['repost_url'] ?? ''),
                'authorization_header' => (string) ($definition['repost_auth_header'] ?? ''),
                'failure_email'        => (string) ($definition['repost_failure_email'] ?? ''),
            ],
            default => [],
        };
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    public function slotPickerProperties(array $config): array
    {
        $daysOfWeek = array_values(array_unique(array_map(
            'intval',
            (array) ($config['days_of_week'] ?? [1, 2, 3, 4, 5])
        )));
        $daysOfWeek = array_values(array_filter($daysOfWeek, static fn (int $day): bool => $day >= 1 && $day <= 7));

        return [
            'timezone'              => $this->normalizeTimezone((string) ($config['timezone'] ?? MeetSlotAvailabilityCalculator::DEFAULT_TIMEZONE)),
            'days_of_week'          => [] !== $daysOfWeek ? $daysOfWeek : [1, 2, 3, 4, 5],
            'start_time'            => $this->normalizeTime((string) ($config['start_time'] ?? '09:00'), '09:00'),
            'end_time'              => $this->normalizeTime((string) ($config['end_time'] ?? '17:00'), '17:00'),
            'slot_duration_minutes' => max(5, (int) ($config['slot_duration_minutes'] ?? 30)),
            'buffer_days'           => max(0, (int) ($config['buffer_days'] ?? 1)),
        ];
    }

    public function normalizeTimezone(string $value): string
    {
        return in_array($value, MeetSlotAvailabilityCalculator::utcOffsetChoices(), true)
            ? $value
            : MeetSlotAvailabilityCalculator::DEFAULT_TIMEZONE;
    }

    public function normalizeTime(string $value, string $default): string
    {
        return 1 === preg_match('/^([01]?\d|2[0-3]):([0-5]\d)/', $value, $m)
            ? sprintf('%02d:%s', (int) $m[1], $m[2])
            : $default;
    }

    public function slugify(string $value, string $fallbackPrefix = 'form'): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        $value = trim($value, '_');

        return '' !== $value ? $value : $fallbackPrefix.'_'.substr((string) time(), -6);
    }
}
