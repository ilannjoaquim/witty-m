<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use MauticPlugin\WittyBundle\Entity\WittyTemplate;
use MauticPlugin\WittyBundle\Service\Template\TemplateManager;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree un nouveau template email/landing page dans la bibliotheque partagee
 * (section Witty > Templates), disponible ensuite pour TOUTES les
 * conversations futures via list_email_templates/create_email_from_template
 * et leurs equivalents page — exactement les 4 templates livres a l'origine
 * (webinar, webinar-day0, confirmation-webinar, webinar-landing) ont ete
 * produits ainsi : contenu/exemple fourni par un humain, structure et HTML
 * ecrits par le modele.
 *
 * A n'appeler QUE si l'utilisateur demande explicitement de creer/enregistrer
 * un template (jamais de sa propre initiative en redigeant un simple email ou
 * une page ponctuelle) : voir PromptBuilder. Ecrire le HTML/le JSON des
 * emplacements a la main serait fastidieux pour un humain — c'est le travail
 * de l'agent, a partir d'un exemple ou d'une consigne donnee par l'utilisateur.
 */
class CreateTemplateTool extends AbstractTool
{
    public function __construct(
        private TemplateManager $manager,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_template';
    }

    public function getDescription(): string
    {
        return 'Cree un nouveau template email ou landing page dans la bibliotheque partagee (section Witty > Templates). '
            .'A utiliser uniquement si l utilisateur demande explicitement de creer/enregistrer un template pour usage '
            .'futur, jamais de ta propre initiative pendant que tu rediges un email ou une page ponctuelle. Decoupe le '
            .'contenu/exemple fourni par l utilisateur en emplacements {{CLE}} (majuscules) avec une consigne de '
            .'redaction par emplacement (label/guidance/exemple/default) : c est ce qui permettra ensuite a '
            .'create_email_from_template/create_page_from_template de le remplir correctement.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getObjectType(): ?string
    {
        return 'template';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'type'        => ['type' => 'string', 'enum' => [WittyTemplate::TYPE_EMAIL, WittyTemplate::TYPE_PAGE]],
            'name'        => ['type' => 'string', 'description' => 'Nom du template.'],
            'key'         => ['type' => 'string', 'description' => 'Identifiant court (slug), utilise ensuite par create_*_from_template. Genere depuis le nom si absent.'],
            'description' => ['type' => 'string', 'description' => 'Description courte, montree dans la liste des templates.'],
            'goal'        => ['type' => 'string', 'description' => 'Objectif du template : ce qu il doit accomplir pour le lecteur/visiteur.'],
            'rules'       => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Regles de redaction a respecter a chaque utilisation (ex. un seul CTA, jamais de fausse urgence).',
            ],
            'placeholders' => [
                'type'        => 'array',
                'description' => 'Un objet par emplacement {{CLE}} present dans html.',
                'items'       => [
                    'type'       => 'object',
                    'properties' => [
                        'key'      => ['type' => 'string', 'description' => 'Cle en majuscules, ex. HEADLINE.'],
                        'label'    => ['type' => 'string'],
                        'guidance' => ['type' => 'string', 'description' => 'Consigne de redaction pour cet emplacement.'],
                        'example'  => ['type' => 'string'],
                        'default'  => ['type' => 'string', 'description' => 'Valeur par defaut : rend l emplacement facultatif.'],
                        'context'  => ['type' => 'string', 'enum' => ['html', 'html_br', 'js'], 'description' => 'Contexte d echappement. Defaut html.'],
                    ],
                    'required' => ['key'],
                ],
            ],
            'html' => ['type' => 'string', 'description' => 'Code HTML complet du template (document entier), avec les emplacements en {{CLE}}.'],
        ], ['type', 'name', 'html']);
    }

    public function execute(array $arguments): array
    {
        $type = WittyTemplate::TYPE_PAGE === ($arguments['type'] ?? '') ? WittyTemplate::TYPE_PAGE : WittyTemplate::TYPE_EMAIL;
        $name = trim((string) ($arguments['name'] ?? ''));
        $html = (string) ($arguments['html'] ?? '');

        if ('' === $name) {
            return ['status' => 'error', 'error' => 'name est obligatoire.'];
        }

        if ('' === trim($html)) {
            return ['status' => 'error', 'error' => 'html est obligatoire.'];
        }

        $rules = TemplateManager::normalizeRules((array) ($arguments['rules'] ?? []));

        $placeholders = TemplateManager::normalizePlaceholders((array) ($arguments['placeholders'] ?? []));

        if (is_string($placeholders)) {
            return ['status' => 'error', 'error' => $placeholders];
        }

        $key         = trim((string) ($arguments['key'] ?? ''));
        $description = trim((string) ($arguments['description'] ?? ''));

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'               => $type,
                'name'               => $name,
                'description'        => '' !== $description ? $description : mb_substr($name, 0, 190),
                'placeholders_count' => count($placeholders),
                'html_longueur'      => strlen($html).' caracteres',
            ]);
        }

        $template = new WittyTemplate();
        $template->setType($type);

        if ('' !== $key) {
            $template->setKey($key);
        }

        $template->setName($name);
        $template->setDescription('' !== $description ? $description : mb_substr($name, 0, 190));
        $template->setGoal((string) ($arguments['goal'] ?? ''));
        $template->setRules($rules);
        $template->setPlaceholders($placeholders);
        $template->setHtml($html);

        $this->manager->save($template);

        return $this->ok([
            'id'   => $template->getId(),
            'type' => $template->getType(),
            'key'  => $template->getKey(),
            'name' => $template->getName(),
            'url'  => '/s/witty/templates',
        ]);
    }

}
