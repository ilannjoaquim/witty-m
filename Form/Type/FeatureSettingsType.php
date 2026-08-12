<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use Mautic\CoreBundle\Form\Type\YesNoButtonGroupType;
use MauticPlugin\WittyBundle\Service\Branding\BrandingAssetManager;
use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\File as FileConstraint;

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

    public function __construct(private BrandingAssetManager $brandingAssets)
    {
    }

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
                'brightdata_pro_mode'   => false,
                'datagouv_enabled'      => false,
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

        // Cle Bright Data dans l'onglet Details (AuthType), comme les cles des
        // fournisseurs IA ; seul le reglage non sensible (mode Pro) est ici.
        $builder->add('brightdata_pro_mode', YesNoButtonGroupType::class, [
            'label' => 'mautic.witty.config.brightdata.pro_mode',
            'attr'  => ['tooltip' => 'mautic.witty.config.brightdata.pro_mode.tooltip'],
        ]);

        // data.gouv.fr (MCP) : pas de cle API (serveur public en lecture
        // seule), donc pas de champ dans AuthType — juste cet interrupteur.
        $builder->add('datagouv_enabled', YesNoButtonGroupType::class, [
            'label' => 'mautic.witty.config.datagouv.enabled',
            'attr'  => ['tooltip' => 'mautic.witty.config.datagouv.enabled.tooltip'],
        ]);

        // Affichage : logo et favicon personnalises. 'mapped' => false : ce sont
        // des UploadedFile, pas des scalaires serialisables tels quels dans
        // feature_settings — le SUBMIT ci-dessous les deplace sur disque et
        // n'ecrit que le nom de fichier resultant dans les donnees du formulaire.
        // Rien de soumis = on garde le fichier deja en place (pas de re-upload
        // obligatoire a chaque enregistrement).
        $builder->add('witty_logo', FileType::class, [
            'label'      => 'mautic.witty.config.display.logo',
            'mapped'     => false,
            'required'   => false,
            'attr'       => ['tooltip' => 'mautic.witty.config.display.logo.tooltip'],
            'label_attr' => ['class' => 'control-label'],
            'constraints' => [
                new FileConstraint([
                    'maxSize'          => '2M',
                    'mimeTypes'        => ['image/png', 'image/jpeg', 'image/svg+xml', 'image/webp'],
                    'mimeTypesMessage' => 'mautic.witty.config.display.logo.invalid_type',
                ]),
            ],
        ]);

        $builder->add('witty_favicon', FileType::class, [
            'label'      => 'mautic.witty.config.display.favicon',
            'mapped'     => false,
            'required'   => false,
            'attr'       => ['tooltip' => 'mautic.witty.config.display.favicon.tooltip'],
            'label_attr' => ['class' => 'control-label'],
            'constraints' => [
                new FileConstraint([
                    'maxSize'          => '1M',
                    'mimeTypes'        => ['image/x-icon', 'image/vnd.microsoft.icon', 'image/png'],
                    'mimeTypesMessage' => 'mautic.witty.config.display.favicon.invalid_type',
                ]),
            ],
        ]);

        $builder->addEventListener(FormEvents::SUBMIT, function (FormEvent $event): void {
            $form = $event->getForm();
            $data = (array) $event->getData();

            $logo = $form->get('witty_logo')->getData();

            if ($logo instanceof UploadedFile) {
                $filename = $this->brandingAssets->storeLogo($logo);

                if (null !== $filename) {
                    $data['witty_logo_filename'] = $filename;
                }
            }

            $favicon = $form->get('witty_favicon')->getData();

            if ($favicon instanceof UploadedFile) {
                $this->brandingAssets->storeFavicon($favicon);
            }

            $event->setData($data);
        });
    }
}
