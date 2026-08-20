<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Form\Type;

use Symfony\Component\Form\AbstractType;

/**
 * Panneau de configuration de l'action "Ajouter en Ne Plus Contacter"
 * (cf. EventListener/FormSubscriber.php::DNC_ACTION_KEY) : volontairement
 * vide, comme son equivalent inverse du coeur Mautic
 * (Mautic\LeadBundle\Form\Type\ActionRemoveDoNotContact) -- channel ('email')
 * et reason (DNC::UNSUBSCRIBED) sont fixes en dur cote handler, jamais une
 * propriete configurable ici, pour qu'un formulaire de desinscription ne
 * puisse jamais etre mal configure (ex. un mauvais canal, une raison
 * 'bounced' au lieu de 'unsubscribed').
 *
 * Un formType est neanmoins obligatoire (meme vide) : c'est ce que le
 * panneau "Actions" du constructeur de formulaire Mautic instancie pour
 * afficher la fenetre de configuration de cette action.
 */
class ActionAddDoNotContactType extends AbstractType
{
    public function getBlockPrefix(): string
    {
        return 'witty_action_add_do_not_contact';
    }
}
