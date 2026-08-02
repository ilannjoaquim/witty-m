<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Onglet "Fonctionnalites" de la fiche du plugin.
 *
 * Les valeurs sont persistees dans Integration::getFeatureSettings()['integration'].
 */
class FeatureSettingsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Une integration fraichement installee n'a aucun reglage : on pre-remplit
        // pour que le formulaire s'ouvre sur des valeurs coherentes.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            $data = is_array($data) ? $data : [];

            $event->setData($data + [
                'provider'             => WittyConfig::PROVIDER_ANTHROPIC,
                'model'                => '',
                'max_iterations'       => WittyConfig::DEFAULT_MAX_ITERATIONS,
                'require_confirmation' => true,
                'streaming'            => true,
                'daily_token_quota'    => 0,
            ]);
        });

        $builder->add('provider', ChoiceType::class, [
            'label'       => 'mautic.witty.config.provider',
            'choices'     => [
                'Anthropic (Claude)' => WittyConfig::PROVIDER_ANTHROPIC,
                'OpenAI (GPT)'       => WittyConfig::PROVIDER_OPENAI,
                'Google (Gemini)'    => WittyConfig::PROVIDER_GEMINI,
            ],
            'required'    => true,
            'placeholder' => false,
            'attr'        => ['class' => 'form-control'],
            'label_attr'  => ['class' => 'control-label'],
        ]);

        $builder->add('model', TextType::class, [
            'label'      => 'mautic.witty.config.model',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'claude-sonnet-5 / gpt-4o / gemini-2.5-flash',
                'tooltip'     => 'mautic.witty.config.model.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('max_iterations', IntegerType::class, [
            'label'      => 'mautic.witty.config.max_iterations',
            'required'   => false,
            'attr'       => [
                'class'   => 'form-control',
                'min'     => 1,
                'max'     => 20,
                'tooltip' => 'mautic.witty.config.max_iterations.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('require_confirmation', YesNoButtonGroupType::class, [
            'label' => 'mautic.witty.config.require_confirmation',
            'attr'  => ['tooltip' => 'mautic.witty.config.require_confirmation.tooltip'],
        ]);

        $builder->add('streaming', YesNoButtonGroupType::class, [
            'label' => 'mautic.witty.config.streaming',
            'attr'  => ['tooltip' => 'mautic.witty.config.streaming.tooltip'],
        ]);

        $builder->add('daily_token_quota', IntegerType::class, [
            'label'      => 'mautic.witty.config.daily_token_quota',
            'required'   => false,
            'attr'       => [
                'class'   => 'form-control',
                'min'     => 0,
                'tooltip' => 'mautic.witty.config.daily_token_quota.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);
    }
}
