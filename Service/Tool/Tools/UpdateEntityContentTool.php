<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Remplace le HTML d'un email ou d'une landing page existant, en place.
 *
 * Complement de update_entity (name/description/is_published/category,
 * volontairement sans le contenu) : avant cet outil, la seule facon de
 * changer le HTML d'un email/page deja cree etait de le supprimer et d'en
 * recreer un nouveau, avec un nouvel id, en perdant ses statistiques et ses
 * eventuelles references dans une campagne.
 *
 * Le remplacement integral n'est autorise qu'en mode code source ('blank' /
 * 'mautic_code_mode'), pas parce que `setCustomHtml()` serait sans effet
 * ailleurs (il a toujours un effet reel : `MailHelper::setEmail()` envoie
 * `customHtml` dans tous les cas, theme ou non) mais parce qu'un remplacement
 * INTEGRAL sur un objet construit avec un theme visuel/MJML risquerait de
 * diverger fortement de ce que l'editeur visuel/MJML donnerait a voir a la
 * prochaine ouverture (rendu depuis `content`/la source MJML, pas depuis
 * `customHtml`). Un objet en theme visuel est donc refuse ici plutot que
 * silencieusement accepte — voir replace_entity_content_text pour une
 * retouche ponctuelle (ex. une URL), elle fonctionne quel que soit le mode.
 */
class UpdateEntityContentTool extends AbstractTool
{
    private const CODE_MODE_TEMPLATES = ['', 'blank', 'mautic_code_mode'];

    private const TYPES = ['email', 'page'];

    public function __construct(
        private EntityCatalog $catalog,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'update_entity_content';
    }

    public function getDescription(): string
    {
        return 'Remplace le HTML d un email ou d une landing page existant. Appeler d abord read_entity_content '
            .'pour recuperer le HTML actuel, le retoucher, puis le renvoyer ici en entier (document complet, pas '
            .'un fragment). Fonctionne uniquement pour les objets en mode code source, voir read_entity_content.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'entity';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type'    => ['type' => 'string', 'enum' => self::TYPES],
            'id'      => ['type' => 'integer', 'description' => 'Identifiant de l objet.'],
            'html'    => ['type' => 'string', 'description' => 'Nouveau HTML complet (document entier, pas un fragment).'],
            'subject' => ['type' => 'string', 'description' => 'Nouvel objet de l email. Ignore pour une page.'],
        ], ['type', 'id', 'html']);
    }

    public function execute(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $id   = (int) ($arguments['id'] ?? 0);
        $html = (string) ($arguments['html'] ?? '');

        if (!in_array($type, self::TYPES, true)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s. Types acceptes : %s', $type, implode(', ', self::TYPES))];
        }

        if ('' === trim($html)) {
            return ['status' => 'error', 'error' => 'html est obligatoire.'];
        }

        $model  = $this->catalog->getModel($type);
        $entity = $model?->getEntity($id);

        if (null === $entity || !method_exists($entity, 'setCustomHtml')) {
            return ['status' => 'error', 'error' => sprintf('%s #%d introuvable.', $type, $id)];
        }

        if (!$this->catalog->isAllowed($type, 'edit', $entity)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de modification refusee sur %s #%d.', $type, $id)];
        }

        $template = (string) ($entity->getTemplate() ?? '');

        if (!in_array($template, self::CODE_MODE_TEMPLATES, true)) {
            return [
                'status' => 'error',
                'error'  => sprintf(
                    "Cet objet utilise le theme visuel '%s' : un remplacement integral via update_entity_content "
                        .'risquerait de desynchroniser le rendu de l editeur visuel/MJML a la prochaine ouverture. '
                        .'Pour une retouche ponctuelle (ex. une URL de logo), utiliser replace_entity_content_text a '
                        .'la place : il fonctionne quel que soit le mode et synchronise la source MJML si elle existe.',
                    $template,
                ),
            ];
        }

        $subject = 'email' === $type ? trim((string) ($arguments['subject'] ?? '')) : '';
        $oldHtml = (string) $entity->getCustomHtml();

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            // Pas le HTML complet dans l apercu (potentiellement enorme) :
            // juste de quoi verifier qu on modifie le bon objet et l ampleur
            // du changement.
            return $this->confirmationRequired(array_filter([
                'type'              => $type,
                'id'                => $id,
                'objet'             => $this->catalog->describe($entity),
                'ancienne_longueur' => strlen($oldHtml).' caracteres',
                'nouvelle_longueur' => strlen($html).' caracteres',
                'nouveau_sujet'     => '' !== $subject ? $subject : null,
            ], static fn ($value): bool => null !== $value));
        }

        $entity->setCustomHtml($html);

        if ('' !== $subject && method_exists($entity, 'setSubject')) {
            $entity->setSubject($subject);
        }

        $model->saveEntity($entity);

        return $this->ok([
            'id'   => $id,
            'type' => $type,
            'name' => $this->catalog->describe($entity),
            'url'  => $this->catalog->getUrl($type, $id),
        ]);
    }
}
