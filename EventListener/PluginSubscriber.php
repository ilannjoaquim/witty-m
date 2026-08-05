<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\EventListener;

use Doctrine\ORM\EntityManagerInterface;
use Mautic\LeadBundle\Entity\LeadField;
use Mautic\LeadBundle\Model\FieldModel;
use Mautic\PluginBundle\Event\PluginInstallEvent;
use Mautic\PluginBundle\Event\PluginUpdateEvent;
use Mautic\PluginBundle\PluginEvents;
use MauticPlugin\WittyBundle\Service\Theme\ThemeInstaller;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Deploie les themes du plugin et provisionne les champs contact lies a
 * plugNmeet lors de l'installation et des mises a jour, c'est-a-dire au
 * `mautic:plugins:reload`.
 *
 * Priorite negative : le souscripteur du coeur (creation du schema, puis
 * migrations) doit passer en premier.
 */
class PluginSubscriber implements EventSubscriberInterface
{
    private const BUNDLE = 'WittyBundle';

    /**
     * Ancien nom du champ, avant qu'on separe webinaire (salle existante,
     * planifiee a l'avance) et rendez-vous (nouvelle salle a la volee, cf.
     * create_meet_room a la demande) : deux usages distincts qui ne doivent
     * plus partager le meme champ.
     */
    private const OLD_MEET_LINK_FIELD_ALIAS = 'meet_invitation_link';

    private const WEBINAR_LINK_FIELD_ALIAS = 'webinaire_invitation_link';
    private const MEETING_LINK_FIELD_ALIAS = 'meeting_invitation_link';
    private const MEETING_DATE_FIELD_ALIAS = 'meeting_scheduled_at';

    /**
     * meeting_scheduled_at reste une vraie date (organisateur, fuseau
     * configure sur le champ) : utilisable dans un filtre de segment ou une
     * campagne ("3 jours avant le RDV"). Ces deux-la sont du texte lisible,
     * deja formate avec le decalage explicite en toutes lettres, ecrits par
     * MeetSlotValidationSubscriber a la reservation (cf. cette classe) :
     * aucun calcul de fuseau a refaire cote destinataire de l'email.
     */
    public const MEETING_ORGANIZER_TIME_FIELD_ALIAS = 'meeting_scheduled_organizer_at';
    public const MEETING_VISITOR_TIME_FIELD_ALIAS   = 'meeting_scheduled_visitor_at';

    public function __construct(
        private ThemeInstaller $themeInstaller,
        private FieldModel $fieldModel,
        private EntityManagerInterface $em,
        private LoggerInterface $logger,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PluginEvents::ON_PLUGIN_INSTALL => ['onInstall', -100],
            PluginEvents::ON_PLUGIN_UPDATE  => ['onUpdate', -100],
        ];
    }

    public function onInstall(PluginInstallEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->deployThemes();
        $this->provisionFields();
    }

    public function onUpdate(PluginUpdateEvent $event): void
    {
        if (self::BUNDLE !== $event->getPlugin()->getBundle()) {
            return;
        }

        $this->deployThemes();
        $this->provisionFields();
    }

    private function deployThemes(): void
    {
        // Un theme non deploye ne doit pas faire echouer l'installation du
        // plugin : le reste (agent, outils, persistance) fonctionne sans lui.
        try {
            foreach ($this->themeInstaller->install() as $theme => $state) {
                $this->logger->info(sprintf('Witty : theme %s %s.', $theme, $state));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Witty : deploiement des themes impossible', ['exception' => $e]);
        }
    }

    /**
     * Champs contact que les emails/landing pages peuvent referencer
     * directement (ex. {contactfield=webinaire_invitation_link}), sans
     * configuration manuelle dans Settings > Custom Fields.
     */
    private function provisionFields(): void
    {
        $this->provisionField(self::WEBINAR_LINK_FIELD_ALIAS, 'Webinar invitation link', 'text');
        $this->provisionField(self::MEETING_LINK_FIELD_ALIAS, 'Meeting invitation link', 'text');
        $this->provisionField(self::MEETING_DATE_FIELD_ALIAS, 'Meeting scheduled at', 'datetime');
        $this->provisionField(self::MEETING_ORGANIZER_TIME_FIELD_ALIAS, 'Meeting scheduled Organizer at', 'text');
        $this->provisionField(self::MEETING_VISITOR_TIME_FIELD_ALIAS, 'Meeting scheduled Visitor at', 'text');
        $this->migrateOldMeetLinkField();
    }

    private function provisionField(string $alias, string $label, string $type): void
    {
        try {
            if (null !== $this->fieldModel->getEntityByAlias($alias)) {
                return;
            }

            $field = new LeadField();
            $field->setLabel($label);
            $field->setAlias($alias);
            $field->setType($type);
            $field->setObject('lead');
            $field->setGroup('plugnmeet');
            $field->setIsPublished(true);

            if ('text' === $type) {
                // LeadField::$charLengthLimit vaut 64 par defaut : trop court
                // pour une URL complete (domaine + jeton signe, ~200-350 car.).
                $field->setCharLengthLimit(500);
            }

            $this->fieldModel->saveEntity($field);

            $this->logger->info(sprintf('Witty : champ contact %s cree.', $alias));
        } catch (\Throwable $e) {
            // Un champ non cree ne doit pas faire echouer l'installation : le
            // reste du plugin fonctionne sans lui, seules les actions qui en
            // dependent (invitation meet, prise de rendez-vous) sont affectees.
            $this->logger->error(sprintf('Witty : creation du champ %s impossible', $alias), ['exception' => $e]);
        }
    }

    /**
     * meet_invitation_link (webinaire et rendez-vous confondus, version
     * initiale) n'est pas renomme en place : FieldModel::saveEntity() ne sait
     * pas renommer la colonne physique d'un champ personnalise existant, seul
     * son libelle. On copie donc les valeurs deja presentes vers le nouveau
     * champ webinaire (l'ancien usage), puis on supprime l'ancien champ.
     */
    private function migrateOldMeetLinkField(): void
    {
        $old = $this->fieldModel->getEntityByAlias(self::OLD_MEET_LINK_FIELD_ALIAS);

        if (null === $old) {
            return;
        }

        try {
            $this->em->getConnection()->executeStatement(sprintf(
                'UPDATE %sleads SET %s = %s WHERE %s IS NOT NULL AND (%s IS NULL OR %s = \'\')',
                MAUTIC_TABLE_PREFIX,
                self::WEBINAR_LINK_FIELD_ALIAS,
                self::OLD_MEET_LINK_FIELD_ALIAS,
                self::OLD_MEET_LINK_FIELD_ALIAS,
                self::WEBINAR_LINK_FIELD_ALIAS,
                self::WEBINAR_LINK_FIELD_ALIAS,
            ));

            $this->fieldModel->deleteEntity($old);

            $this->logger->info('Witty : ancien champ meet_invitation_link migre vers webinaire_invitation_link puis supprime.');
        } catch (\Throwable $e) {
            // L'ancien champ reste alors en place, orphelin mais inoffensif :
            // aucune donnee n'est perdue, seul le menage n'a pas pu se faire
            // (ex. le champ est utilise dans un filtre de segment).
            $this->logger->error('Witty : migration de l ancien champ meet_invitation_link impossible', ['exception' => $e]);
        }
    }
}
