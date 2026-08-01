<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * Onglet "Details" de la fiche du plugin.
 *
 * Les valeurs alimentent Integration::getApiKeys() : Mautic les chiffre avant
 * insertion en base et les dechiffre a la lecture, aucun traitement a faire ici.
 *
 * Le formulaire n'a pas de data_class : chaque champ est mappe sur la cle
 * correspondante du tableau (api_key => apiKeys['api_key']).
 */
class AuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('api_key', PasswordType::class, [
            'label'        => 'mautic.witty.config.api_key',
            'required'     => true,
            'always_empty' => false,
            'attr'         => [
                'class'        => 'form-control',
                'autocomplete' => 'off',
                'tooltip'      => 'mautic.witty.config.api_key.tooltip',
            ],
            'label_attr'   => ['class' => 'control-label'],
            // Mautic n'affiche les erreurs que si l'integration est publiee :
            // on peut donc enregistrer une integration desactivee sans cle.
            'constraints'  => [new NotBlank(['message' => 'mautic.core.value.required'])],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        // Injecte par IntegrationConfigType.
        $resolver->setDefined(['integration']);
    }
}
