<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Form;

use MauticPlugin\WittyBundle\EventListener\FormSubscriber;

/**
 * Constantes partagees entre create_form, update_form et read_form : les
 * types de champ/action acceptes doivent rester identiques d un outil a
 * l autre (un champ que create_form sait creer mais qu update_form ne
 * reconnaitrait pas serait un piege). Anciennement dupliquees dans
 * CreateFormTool seul.
 */
class FormDefinitions
{
    /**
     * Types acceptes par Mautic (Helper\FormFieldHelper), plus notre champ
     * "Creneau de rendez-vous". Volontairement hors liste : les types propres
     * a une integration tierce (ex. lookup personnalise autre que companyLookup).
     */
    public const FIELD_TYPES = [
        'text', 'email', 'textarea', 'tel', 'url', 'number', 'date', 'datetime',
        'country', 'select', 'radiogrp', 'checkboxgrp', 'hidden', 'freetext', 'button',
        'captcha', 'freehtml', 'pagebreak', 'slider', 'password', 'companyLookup', 'file',
        FormSubscriber::SLOT_PICKER_FIELD_TYPE,
    ];

    /** Types d'action de soumission geres par ces outils. */
    public const ACTION_TYPES = [
        FormSubscriber::ACTION_KEY,
        'email.send.lead',
        'email.send.user',
        'lead.changelist',
        'lead.changetags',
        'lead.updatelead',
        'lead.pointschange',
        'form.email',
        'form.repost',
    ];

    public const POINTS_OPERATORS = ['plus', 'minus', 'times', 'divide'];
}
