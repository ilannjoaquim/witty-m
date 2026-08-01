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

class ConfigType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        // Doit correspondre au formAlias declare dans ConfigSubscriber.
        return 'wittyconfig';
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('witty_provider', ChoiceType::class, [
            'label'      => 'mautic.witty.config.provider',
            'choices'    => [
                'Anthropic (Claude)' => WittyConfig::PROVIDER_ANTHROPIC,
                'OpenAI (GPT)'       => WittyConfig::PROVIDER_OPENAI,
                'Google (Gemini)'    => WittyConfig::PROVIDER_GEMINI,
            ],
            'required'   => true,
            'attr'       => ['class' => 'form-control'],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('witty_model', TextType::class, [
            'label'      => 'mautic.witty.config.model',
            'required'   => false,
            'attr'       => [
                'class'       => 'form-control',
                'placeholder' => 'claude-sonnet-5 / gpt-4o / gemini-2.5-flash',
                'tooltip'     => 'mautic.witty.config.model.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('witty_api_key', TextType::class, [
            'label'      => 'mautic.witty.config.api_key',
            'required'   => false,
            'attr'       => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.api_key.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('witty_max_iterations', IntegerType::class, [
            'label'      => 'mautic.witty.config.max_iterations',
            'required'   => false,
            'attr'       => [
                'class'   => 'form-control',
                'tooltip' => 'mautic.witty.config.max_iterations.tooltip',
            ],
            'label_attr' => ['class' => 'control-label'],
        ]);

        $builder->add('witty_require_confirmation', YesNoButtonGroupType::class, [
            'label' => 'mautic.witty.config.require_confirmation',
            'attr'  => ['tooltip' => 'mautic.witty.config.require_confirmation.tooltip'],
            'data'  => (bool) ($options['data']['witty_require_confirmation'] ?? true),
        ]);
    }
}
