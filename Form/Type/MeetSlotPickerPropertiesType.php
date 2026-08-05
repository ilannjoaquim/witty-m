<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use MauticPlugin\WittyBundle\Service\PlugNmeet\MeetSlotAvailabilityCalculator;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TimeType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Configuration du champ "Creneau de rendez-vous" : regle de recurrence
 * hebdomadaire simple (jours + une plage horaire quotidienne), decoupee en
 * creneaux d'une duree fixe, avec un delai de securite avant le premier
 * creneau reservable. Stocke tel quel dans Field::properties (tableau libre,
 * pas de schema impose par Mautic).
 */
class MeetSlotPickerPropertiesType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('days_of_week', ChoiceType::class, [
            'label'    => 'mautic.witty.meet.slotpicker.days_of_week',
            'choices'  => [
                'mautic.witty.meet.slotpicker.day.monday'    => 1,
                'mautic.witty.meet.slotpicker.day.tuesday'   => 2,
                'mautic.witty.meet.slotpicker.day.wednesday' => 3,
                'mautic.witty.meet.slotpicker.day.thursday'  => 4,
                'mautic.witty.meet.slotpicker.day.friday'    => 5,
                'mautic.witty.meet.slotpicker.day.saturday'  => 6,
                'mautic.witty.meet.slotpicker.day.sunday'    => 7,
            ],
            'multiple' => true,
            'expanded' => true,
            'required' => true,
            'attr'     => ['tooltip' => 'mautic.witty.meet.slotpicker.days_of_week_tooltip'],
        ]);

        $builder->add('timezone', ChoiceType::class, [
            'label'       => 'mautic.witty.meet.slotpicker.timezone',
            'choices'     => MeetSlotAvailabilityCalculator::utcOffsetChoices(),
            'required'    => true,
            // Un <select> HTML soumet toujours une valeur (le premier <option>
            // si aucune n'est explicitement selectionnee) : sans un placeholder
            // reellement vide ici, un utilisateur qui ne touche pas ce champ
            // enregistrerait silencieusement le tout premier decalage de la
            // liste (UTC-12:00) au lieu du sien. Avec 'required' => true, un
            // placeholder soumis vide echoue la validation du formulaire et
            // force un choix explicite plutot que de deviner un defaut.
            'placeholder' => 'mautic.witty.meet.slotpicker.timezone_placeholder',
            'attr'        => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.witty.meet.slotpicker.timezone_tooltip',
            ],
        ]);

        $builder->add('start_time', TimeType::class, [
            'label'        => 'mautic.witty.meet.slotpicker.start_time',
            'widget'       => 'single_text',
            'input'        => 'string',
            // Sans ceci, 'input' => 'string' parse/formate en 'H:i:s' par
            // defaut (avec secondes) alors que le widget single_text envoie
            // "09:00" (sans secondes) : mismatch -> TransformationFailedException
            // "Not enough data available to satisfy format" a l'ouverture ou
            // a l'enregistrement du champ. 'H:i' est aussi le format attendu
            // par MeetSlotAvailabilityCalculator::parseTime().
            'input_format' => 'H:i',
            'attr'         => ['class' => 'form-control'],
        ]);

        $builder->add('end_time', TimeType::class, [
            'label'        => 'mautic.witty.meet.slotpicker.end_time',
            'widget'       => 'single_text',
            'input'        => 'string',
            'input_format' => 'H:i',
            'attr'         => ['class' => 'form-control'],
        ]);

        $builder->add('slot_duration_minutes', IntegerType::class, [
            'label' => 'mautic.witty.meet.slotpicker.slot_duration_minutes',
            'attr'  => ['class' => 'form-control', 'min' => 5, 'tooltip' => 'mautic.witty.meet.slotpicker.slot_duration_minutes_tooltip'],
        ]);

        $builder->add('buffer_days', IntegerType::class, [
            'label' => 'mautic.witty.meet.slotpicker.buffer_days',
            'attr'  => ['class' => 'form-control', 'min' => 0, 'tooltip' => 'mautic.witty.meet.slotpicker.buffer_days_tooltip'],
        ]);
    }
}
