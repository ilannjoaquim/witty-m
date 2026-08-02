<?php

declare(strict_types=1);

use MauticPlugin\WittyBundle\Controller\AuditController;
use MauticPlugin\WittyBundle\Controller\WittyController;

return [
    'name'        => 'Witty',
    'description' => 'Assistant IA conversationnel capable de piloter Mautic (segments, emails, landing pages, campagnes).',
    // Le changement de version declenche Engine::up() sur Migrations/ au prochain
    // mautic:plugins:reload : c'est ce qui cree les tables sur une instance ou le
    // plugin etait deja installe.
    'version'     => '1.2.0',
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
            'witty_chat_stream' => [
                'path'       => '/witty/stream',
                'controller' => WittyController::class.'::streamAction',
                'method'     => 'POST',
            ],
            'witty_conversations' => [
                'path'       => '/witty/conversations',
                'controller' => WittyController::class.'::conversationsAction',
                'method'     => 'GET',
            ],
            'witty_conversation' => [
                'path'         => '/witty/conversation/{id}',
                'controller'   => WittyController::class.'::conversationAction',
                'method'       => 'GET',
                'requirements' => ['id' => '\d+'],
            ],
            'witty_conversation_delete' => [
                'path'         => '/witty/conversation/{id}/delete',
                'controller'   => WittyController::class.'::deleteConversationAction',
                'method'       => 'POST',
                'requirements' => ['id' => '\d+'],
            ],
            'witty_audit' => [
                'path'       => '/witty/audit',
                'controller' => AuditController::class.'::indexAction',
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
        'admin' => [
            'mautic.witty.menu.audit' => [
                'route'     => 'witty_audit',
                'iconClass' => 'ri-file-list-3-line',
                'priority'  => 30,
            ],
        ],
    ],

    // Pas de cle 'parameters' : toute la configuration (cle API comprise) se
    // saisit dans la fiche du plugin, cf. Integration/Support/ConfigSupport.php.
];
