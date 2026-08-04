<?php

declare(strict_types=1);

use MauticPlugin\WittyBundle\Controller\AuditController;
use MauticPlugin\WittyBundle\Controller\MeetJoinController;
use MauticPlugin\WittyBundle\Controller\MeetSlotAvailabilityController;
use MauticPlugin\WittyBundle\Controller\SkillController;
use MauticPlugin\WittyBundle\Controller\VideoconferenceController;
use MauticPlugin\WittyBundle\Controller\WittyController;
use MauticPlugin\WittyBundle\Entity\WittyRoom;
use MauticPlugin\WittyBundle\Entity\WittySkill;

return [
    'name'        => 'Witty',
    'description' => 'Assistant IA conversationnel capable de piloter Mautic (segments, emails, landing pages, campagnes).',
    // Le changement de version declenche Engine::up() sur Migrations/ au prochain
    // mautic:plugins:reload : c'est ce qui cree les tables sur une instance ou le
    // plugin etait deja installe.
    'version'     => '2.4.0',
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
            'witty_models' => [
                'path'       => '/witty/models/{provider}',
                'controller' => WittyController::class.'::modelsAction',
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
            'witty_search' => [
                'path'       => '/witty/search',
                'controller' => WittyController::class.'::searchAction',
                'method'     => 'GET',
            ],

            'witty_skills' => [
                'path'       => '/witty/skills',
                'controller' => SkillController::class.'::indexAction',
            ],
            'witty_skills_data' => [
                'path'       => '/witty/skills/data',
                'controller' => SkillController::class.'::dataAction',
                'method'     => 'GET',
            ],
            'witty_skills_save' => [
                'path'       => '/witty/skills/save',
                'controller' => SkillController::class.'::saveAction',
                'method'     => 'POST',
            ],
            'witty_skills_delete' => [
                'path'       => '/witty/skills/delete',
                'controller' => SkillController::class.'::deleteAction',
                'method'     => 'POST',
            ],

            'witty_video_rooms' => [
                'path'       => '/witty/video/rooms',
                'controller' => VideoconferenceController::class.'::roomsAction',
            ],
            'witty_video_rooms_data' => [
                'path'       => '/witty/video/rooms/data',
                'controller' => VideoconferenceController::class.'::roomsDataAction',
                'method'     => 'GET',
            ],
            'witty_video_rooms_create' => [
                'path'       => '/witty/video/rooms/create',
                'controller' => VideoconferenceController::class.'::roomsCreateAction',
                'method'     => 'POST',
            ],
            'witty_video_rooms_end' => [
                'path'       => '/witty/video/rooms/end',
                'controller' => VideoconferenceController::class.'::roomsEndAction',
                'method'     => 'POST',
            ],
            'witty_video_rooms_details' => [
                'path'       => '/witty/video/rooms/details',
                'controller' => VideoconferenceController::class.'::roomsDetailsAction',
                'method'     => 'GET',
            ],
            'witty_video_rooms_link' => [
                'path'       => '/witty/video/rooms/link',
                'controller' => VideoconferenceController::class.'::roomsLinkAction',
                'method'     => 'POST',
            ],

            'witty_video_past_rooms' => [
                'path'       => '/witty/video/past-rooms',
                'controller' => VideoconferenceController::class.'::pastRoomsAction',
            ],
            'witty_video_past_rooms_data' => [
                'path'       => '/witty/video/past-rooms/data',
                'controller' => VideoconferenceController::class.'::pastRoomsDataAction',
                'method'     => 'GET',
            ],

            'witty_video_recordings' => [
                'path'       => '/witty/video/recordings',
                'controller' => VideoconferenceController::class.'::recordingsAction',
            ],
            'witty_video_recordings_data' => [
                'path'       => '/witty/video/recordings/data',
                'controller' => VideoconferenceController::class.'::recordingsDataAction',
                'method'     => 'GET',
            ],
            'witty_video_recordings_delete' => [
                'path'       => '/witty/video/recordings/delete',
                'controller' => VideoconferenceController::class.'::recordingsDeleteAction',
                'method'     => 'POST',
            ],
            'witty_video_recordings_download' => [
                'path'       => '/witty/video/recordings/download',
                'controller' => VideoconferenceController::class.'::recordingsDownloadAction',
                'method'     => 'GET',
            ],
            'witty_video_recordings_to_asset' => [
                'path'       => '/witty/video/recordings/to-asset',
                'controller' => VideoconferenceController::class.'::recordingsToAssetAction',
                'method'     => 'POST',
            ],
            'witty_video_artifacts_delete' => [
                'path'       => '/witty/video/artifacts/delete',
                'controller' => VideoconferenceController::class.'::artifactsDeleteAction',
                'method'     => 'POST',
            ],
            'witty_video_artifacts_download' => [
                'path'       => '/witty/video/artifacts/download',
                'controller' => VideoconferenceController::class.'::artifactsDownloadAction',
                'method'     => 'GET',
            ],
        ],

        // Hors du prefixe /s/ (donc hors du firewall qui exige une connexion,
        // cf. app/config/security.php : access_control n'exige
        // IS_AUTHENTICATED que sur ^/(s/|elfinder|efconnect)) : un contact
        // externe doit pouvoir suivre ce lien sans compte Mautic.
        'public' => [
            'witty_meet_join' => [
                'path'       => '/meet/join/{token}',
                'controller' => MeetJoinController::class.'::joinAction',
                'method'     => 'GET',
            ],
            'witty_meet_slots_availability' => [
                'path'         => '/witty/meet/slots/{fieldId}',
                'controller'   => MeetSlotAvailabilityController::class.'::availabilityAction',
                'method'       => 'GET',
                'requirements' => ['fieldId' => '\d+'],
            ],
        ],
    ],

    'menu' => [
        'main' => [
            // Parent sans route : un item avec des enfants devient un simple
            // bouton d'ouverture/fermeture du sous-menu (cf. Menu/main.html.twig
            // de Mautic), la route eventuelle ne servirait a rien.
            'mautic.witty.menu.root' => [
                'iconClass' => 'ri-robot-2-line', // si l'icone ne s'affiche pas : 'fa fa-magic'
                'priority'  => 30,
            ],
            'mautic.witty.menu.chat' => [
                'route'     => 'witty_chat',
                'parent'    => 'mautic.witty.menu.root',
                'priority'  => 30,
            ],
            'mautic.witty.menu.skills' => [
                'route'     => 'witty_skills',
                'parent'    => 'mautic.witty.menu.root',
                'priority'  => 20,
            ],
            'mautic.witty.menu.videoconference' => [
                'iconClass' => 'ri-vidicon-line',
                'priority'  => 25,
            ],
            'mautic.witty.menu.videoconference.rooms' => [
                'route'     => 'witty_video_rooms',
                'parent'    => 'mautic.witty.menu.videoconference',
                'priority'  => 30,
            ],
            'mautic.witty.menu.videoconference.past_rooms' => [
                'route'     => 'witty_video_past_rooms',
                'parent'    => 'mautic.witty.menu.videoconference',
                'priority'  => 20,
            ],
            'mautic.witty.menu.videoconference.recordings' => [
                'route'     => 'witty_video_recordings',
                'parent'    => 'mautic.witty.menu.videoconference',
                'priority'  => 10,
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

    // Rattache Skills et Rooms au systeme de categories de Mautic (meme
    // mecanisme que segments/emails/formulaires, cf. LeadBundle\Entity\LeadList) :
    // CategorySubscriber::onCategoryBundleListBuild lit cette cle sur tous les
    // bundles (plugins compris) pour construire la liste des "bundle" de
    // categories disponibles.
    'categories' => [
        'witty_skill' => [
            'class' => WittySkill::class,
            'label' => 'mautic.witty.skill.header',
        ],
        'witty_room' => [
            'class' => WittyRoom::class,
            'label' => 'mautic.witty.video.rooms.header',
        ],
    ],

    // Pas de cle 'parameters' : toute la configuration (cle API comprise) se
    // saisit dans la fiche du plugin, cf. Integration/Support/ConfigSupport.php.
];
