<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\Tool\EntityCatalog;

/**
 * Lit le HTML actuel d'un email ou d'une landing page existant.
 *
 * Sans cet outil, modifier un email/page revenait a le supprimer et le
 * recreer a l'aveugle (perte de l'id, des statistiques, des references dans
 * une campagne...). Utiliser avec update_entity_content pour une vraie
 * retouche en place : lire, modifier le HTML recupere, renvoyer le tout.
 *
 * Limite au mode code source (template 'blank' ou 'mautic_code_mode', celui
 * qu'utilisent systematiquement create_email/create_landing_page et les
 * outils create_*_from_template) : un email/page construit avec un theme
 * visuel stocke son contenu bloc par bloc (Email::getContent() /
 * Page::getContent()), pas dans un unique champ HTML — celui retourne ici
 * serait alors incomplet ou perime. On le renvoie quand meme, a titre
 * informatif, avec un avertissement explicite plutot qu'un refus silencieux.
 */
class ReadEntityContentTool extends AbstractTool
{
    private const CODE_MODE_TEMPLATES = ['', 'blank', 'mautic_code_mode'];

    private const TYPES = ['email', 'page'];

    public function __construct(private EntityCatalog $catalog)
    {
    }

    public function getName(): string
    {
        return 'read_entity_content';
    }

    public function getDescription(): string
    {
        return 'Lit le HTML actuel d un email ou d une landing page existant, pour le modifier ensuite avec '
            .'update_entity_content plutot que de supprimer/recreer. Utiliser list_entities au prealable pour '
            .'recuperer l identifiant.';
    }

    public function getObjectType(): ?string
    {
        return 'entity';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type' => ['type' => 'string', 'enum' => self::TYPES],
            'id'   => ['type' => 'integer', 'description' => 'Identifiant de l objet.'],
        ], ['type', 'id']);
    }

    public function execute(array $arguments): array
    {
        $type = (string) ($arguments['type'] ?? '');
        $id   = (int) ($arguments['id'] ?? 0);

        if (!in_array($type, self::TYPES, true)) {
            return ['status' => 'error', 'error' => sprintf('Type inconnu : %s. Types acceptes : %s', $type, implode(', ', self::TYPES))];
        }

        $model  = $this->catalog->getModel($type);
        $entity = $model?->getEntity($id);

        if (null === $entity || !method_exists($entity, 'getCustomHtml')) {
            return ['status' => 'error', 'error' => sprintf('%s #%d introuvable.', $type, $id)];
        }

        if (!$this->catalog->isAllowed($type, 'view', $entity)) {
            return ['status' => 'denied', 'error' => sprintf('Permission de lecture refusee sur %s #%d.', $type, $id)];
        }

        $template   = (string) ($entity->getTemplate() ?? '');
        $isCodeMode = in_array($template, self::CODE_MODE_TEMPLATES, true);

        $result = [
            'id'           => $id,
            'type'         => $type,
            'name'         => $this->catalog->describe($entity),
            'template'     => '' !== $template ? $template : 'blank',
            'code_mode'    => $isCodeMode,
            'html'         => (string) $entity->getCustomHtml(),
            'is_published' => method_exists($entity, 'isPublished') ? $entity->isPublished() : null,
            'url'          => $this->catalog->getUrl($type, $id),
        ];

        if ('email' === $type && method_exists($entity, 'getSubject')) {
            $result['subject'] = (string) $entity->getSubject();
        }

        if ('page' === $type && method_exists($entity, 'getAlias')) {
            $result['alias'] = (string) $entity->getAlias();
        }

        if (!$isCodeMode) {
            $result['warning'] = sprintf(
                "Cet objet utilise le theme visuel '%s'. Le html retourne ici est neanmoins ce qui part reellement "
                    .'au destinataire/visiteur (MailHelper::setEmail() ne retombe sur le rendu theme+blocs que si '
                    .'le HTML enregistre est vide, un cas quasi inexistant). update_entity_content refusera un '
                    .'remplacement integral (risque de desynchronisation avec l editeur visuel/MJML a la prochaine '
                    .'ouverture) : pour une retouche ponctuelle (ex. une URL), utiliser replace_entity_content_text '
                    .'a la place, qui fonctionne quel que soit le mode et synchronise la source MJML si elle existe.',
                $template,
            );
        }

        return $this->ok($result);
    }
}
