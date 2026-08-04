<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;

/**
 * Onglet "Fonctionnalites" de la fiche du plugin.
 *
 * Le fournisseur et le modele ne sont plus des reglages globaux : ils se
 * choisissent dans le chat, a chaque tour, parmi les fournisseurs dont une cle
 * API est renseignee (onglet Details). Le modele par fournisseur ici est une
 * simple valeur de repli, facultative — WittyConfig::DEFAULT_MODELS s'applique
 * sinon.
 *
 * Les valeurs sont persistees dans Integration::getFeatureSettings()['integration'].
 */
class FeatureSettingsType extends AbstractType
{
    private const MODEL_FIELDS = [
        WittyConfig::PROVIDER_ANTHROPIC => 'anthropic_model',
        WittyConfig::PROVIDER_OPENAI    => 'openai_model',
        WittyConfig::PROVIDER_GEMINI    => 'gemini_model',
    ];

    private const MODEL_PLACEHOLDERS = [
        WittyConfig::PROVIDER_ANTHROPIC => 'claude-sonnet-5',
        WittyConfig::PROVIDER_OPENAI    => 'gpt-4o',
        WittyConfig::PROVIDER_GEMINI    => 'gemini-2.5-flash',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        // Une integration fraichement installee n'a aucun reglage : on pre-remplit
        // pour que le formulaire s'ouvre sur des valeurs coherentes.
        $builder->addEventListener(FormEvents::PRE_SET_DATA, function (FormEvent $event): void {
            $data = $event->getData();
            $data = is_array($data) ? $data : [];

            $event->setData($data + array_fill_keys(self::MODEL_FIELDS, '') + [
                'max_iterations'        => WittyConfig::DEFAULT_MAX_ITERATIONS,
                'require_confirmation'  => true,
                'streaming'             => true,
                'daily_token_quota'     => 0,
                'plugnmeet_server_url'  => '',
            ]);
        });

        foreach (self::MODEL_FIELDS as $provider => $field) {
            $builder->add($field, TextType::class, [
                'label'      => 'mautic.witty.config.model.'.$provider,
                'required'   => false,
                'attr'       => [
                    'class'       => 'form-control',
                    'placeholder' => self::MODEL_PLACEHOLDERS[$provider],
                    'tooltip'     => 'mautic.witty.config.model.tooltip',
                ],
                'label_attr' => ['class' => 'control-label'],
            ]);
        }

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

        // Cle et secret sont dans l'onglet Details (AuthType), chiffres comme les
        // cles des fournisseurs IA ; seule l'URL du serveur, non sensible, est ici.
        $builder->add('plugnmeet_server_url', TextType::class, [
            'label'      => 'mautic.witty.config.plugnmeet.server_url',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'https://meet.example.com',
                'tooltip'     => 'mautic.witty.config.plugnmeet.server_url.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);
    }
}
