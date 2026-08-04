<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use MauticPlugin\WittyBundle\Service\PlugNmeet\Exception\PlugNmeetException;
use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetInvitationCreator;
use MauticPlugin\WittyBundle\Service\PlugNmeet\PlugNmeetClient;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Formulaire de l'action "Creer un lien invitation meet" (campagne et
 * formulaire).
 *
 * room_id est facultatif : renseigne, on rejoint une salle existante deja
 * active (webinaire, planifie a l'avance) ; laisse vide, une nouvelle salle
 * est creee a la volee pour ce contact (rendez-vous, ex. depuis un formulaire
 * de prise de rendez-vous avec un champ "Creneau de rendez-vous"). Chaque mode
 * ecrit dans son propre champ contact pour ne jamais melanger les deux usages.
 */
class CreateMeetInvitationLinkActionType extends AbstractType
{
    public function __construct(private PlugNmeetClient $client)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('target_field', ChoiceType::class, [
            'label'   => 'mautic.witty.meet.action.target_field',
            'choices' => [
                'mautic.witty.meet.action.target_field.webinar' => MeetInvitationCreator::FIELD_WEBINAR,
                'mautic.witty.meet.action.target_field.meeting' => MeetInvitationCreator::FIELD_MEETING,
            ],
            'required' => true,
            'attr'     => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.witty.meet.action.target_field_tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('room_id', ChoiceType::class, [
            'label'       => 'mautic.witty.meet.action.room',
            'choices'     => $this->roomChoices(),
            'placeholder' => 'mautic.witty.meet.action.room_placeholder_auto',
            'required'    => false,
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.witty.meet.action.room_tooltip_auto',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function roomChoices(): array
    {
        try {
            $data = $this->client->getActiveRoomsInfo();
        } catch (PlugNmeetException) {
            return [];
        }

        $choices = [];

        foreach ((array) ($data['rooms'] ?? []) as $entry) {
            $info   = (array) ($entry['room_info'] ?? []);
            $roomId = (string) ($info['room_id'] ?? '');

            if ('' === $roomId) {
                continue;
            }

            $label           = ((string) ($info['room_title'] ?? $roomId)).' ('.$roomId.')';
            $choices[$label] = $roomId;
        }

        return $choices;
    }
}
