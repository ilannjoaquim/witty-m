<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use MauticPlugin\WittyBundle\Service\WittyConfig;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\Callback;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Onglet "Details" de la fiche du plugin.
 *
 * Une cle par fournisseur plutot qu'un choix unique : chaque cle renseignee
 * rend son fournisseur disponible dans le selecteur du chat. Aucune n'est
 * individuellement obligatoire, mais il en faut au moins une (contrainte de
 * formulaire ci-dessous) — un plugin publie sans aucune cle ne sert a rien.
 *
 * Les valeurs alimentent Integration::getApiKeys() : Mautic les chiffre avant
 * insertion en base et les dechiffre a la lecture, cle par cle, quel que soit
 * leur nom (voir Facade\EncryptionService) — aucun traitement a faire ici.
 *
 * Le formulaire n'a pas de data_class : chaque champ est mappe sur la cle
 * correspondante du tableau (anthropic_api_key => apiKeys['anthropic_api_key']).
 */
class AuthType extends AbstractType
{
    private const FIELDS = [
        WittyConfig::PROVIDER_ANTHROPIC => 'anthropic_api_key',
        WittyConfig::PROVIDER_OPENAI    => 'openai_api_key',
        WittyConfig::PROVIDER_GEMINI    => 'gemini_api_key',
        WittyConfig::PROVIDER_DEEPSEEK  => 'deepseek_api_key',
    ];

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        foreach (self::FIELDS as $provider => $field) {
            $builder->add($field, PasswordType::class, [
                'label'        => 'mautic.witty.config.api_key.'.$provider,
                'required'     => false,
                'always_empty' => false,
                'attr'         => [
                    'class'        => 'form-control',
                    'autocomplete' => 'off',
                    'tooltip'      => 'mautic.witty.config.api_key.tooltip',
                ],
                'label_attr'   => ['class' => 'control-label'],
            ]);
        }

        // plugNmeet est une integration facultative independante des fournisseurs
        // IA : ni comptee ni requise par la contrainte "au moins une cle" ci-dessous.
        $builder->add('plugnmeet_api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.plugnmeet.api_key',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.plugnmeet.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);

        $builder->add('plugnmeet_api_secret', PasswordType::class, [
            'label'        => 'mautic.witty.config.plugnmeet.api_secret',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.plugnmeet.api_secret.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);

        // Bright Data (MCP) est une capacite facultative independante des
        // fournisseurs IA, meme logique que plugNmeet : ni comptee ni requise
        // par la contrainte "au moins une cle" ci-dessous.
        $builder->add('brightdata_api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.brightdata.api_key',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.brightdata.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);

        // Prospeo (MCP) : meme logique que Bright Data, capacite facultative
        // independante des fournisseurs IA.
        $builder->add('prospeo_api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.prospeo.api_key',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.prospeo.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);

        // Apollo (API REST classique, cle API "utilisateur" — pas le serveur
        // MCP, qui exige OAuth 2.0 cote "partenaire") : meme logique que
        // Bright Data/Prospeo, capacite facultative independante des
        // fournisseurs IA.
        $builder->add('apollo_api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.apollo.api_key',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.apollo.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);

        // QuickEnrich (API REST, gratuite) : meme logique que Bright
        // Data/Prospeo/Apollo, capacite facultative independante des
        // fournisseurs IA.
        $builder->add('quickenrich_api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.quickenrich.api_key',
            'required'     => false,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.quickenrich.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Injecte par IntegrationConfigType.
        $resolver->setDefined(['integration']);

        $resolver->setDefaults([
            'constraints' => [
                new Callback(function ($data, ExecutionContextInterface $context): void {
                    $data   = is_array($data) ? $data : [];
                    $filled = array_filter(
                        array_intersect_key($data, array_flip(self::FIELDS)),
                        static fn ($value): bool => '' !== trim((string) $value),
                    );

                    if ([] === $filled) {
                        // Le validateur Symfony traduit par defaut dans le domaine
                        // "validators", pas "messages" ou vivent les libelles du
                        // plugin : sans setTranslationDomain(), la cle brute
                        // s'affiche telle quelle au lieu du texte traduit.
                        $context->buildViolation('mautic.witty.config.api_key.required_one')
                            ->setTranslationDomain('messages')
                            ->addViolation();
                    }
                }),
            ],
        ]);
    }
}
