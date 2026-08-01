<?php

declare(strict_types=1);

use MauticPlugin\WittyBundle\Controller\WittyController;

return [
    'name'        => 'Witty',
    'description' => 'Assistant IA conversationnel capable de piloter Mautic (segments, emails, landing pages, campagnes).',
    'version'     => '1.0.0',
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

    // Ces parametres apparaissent dans Parametres > Configuration > Witty
    // et sont persistes dans app/config/local.php
    'parameters' => [
        'witty_provider'             => 'anthropic',
        'witty_model'                => '',
        'witty_api_key'              => '',
        'witty_max_iterations'       => 8,
        'witty_require_confirmation' => true,
    ],
];
