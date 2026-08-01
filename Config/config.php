<?php

declare(strict_types=1);

use MauticPlugin\WittyBundle\Controller\WittyController;

return [
    'name'        => 'Witty',
    'description' => 'Assistant IA conversationnel capable de piloter Mautic (segments, emails, landing pages, campagnes).',
    'version'     => '1.0.1',
    'author'      => 'Witty',

    'routes' => [
        'main' => [
            'witty_chat' => [
                'path'       => '/witty',
                'controller' => WittyController::class.'::indexAction',
            ],
            'witty_chat_send' => [
                'path'       => '/witty/send',
                'controller' => WittyController::class.'::sendAction',
                'method'     => 'POST',
            ],
        ],
    ],

    'menu' => [
        'main' => [
            'mautic.witty.menu.chat' => [
                'route'     => 'witty_chat',
                'iconClass' => 'ri-robot-2-line', // si l'icone ne s'affiche pas : 'fa fa-magic'
                'priority'  => 30,
            ],
        ],
    ],

    // Pas de cle 'parameters' : toute la configuration (cle API comprise) se
    // saisit dans la fiche du plugin, cf. Integration/Support/ConfigSupport.php.
];
