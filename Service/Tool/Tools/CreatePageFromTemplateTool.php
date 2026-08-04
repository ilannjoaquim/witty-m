<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Model\PageModel;
use MauticPlugin\WittyBundle\Service\Template\PageTemplateLibrary;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Cree une landing page a partir d'un template du plugin.
 *
 * A la difference de create_landing_page (HTML libre, theme 'blank'), le
 * template est toujours enregistre avec template='mautic_code_mode' : c'est
 * le mecanisme natif de Mautic pour figer une page en edition "code source"
 * (voir ThemeListType::configureOptions). Certains templates de cette
 * bibliotheque embarquent du JavaScript fonctionnel (compte a rebours, etats
 * dynamiques) ; ouvrir une telle page dans le builder GrapesJS la ferait
 * passer par son moteur de rendu par composants, qui ne garantit pas la
 * survie d'un <script> au prochain enregistrement. Le mode code source est
 * applique uniformement a tous les templates de la bibliotheque, avec ou
 * sans JavaScript : un futur re-enregistrement via le canevas visuel n'est
 * jamais souhaitable pour du contenu genere automatiquement.
 */
class CreatePageFromTemplateTool extends AbstractTool
{
    public function __construct(
        private PageTemplateLibrary $library,
        private PageModel $pageModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_page_from_template';
    }

    public function getDescription(): string
    {
        return 'Cree une landing page a partir d un template du plugin. Appeler d abord list_page_templates pour '
            .'connaitre les emplacements et leurs consignes, puis fournir ici le texte de chaque emplacement '
            .'dans values. La page est enregistree en mode code source (pas de builder visuel) pour que le '
            .'JavaScript du template continue de fonctionner. Ne pas tenter de reecrire le template.';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'page:pages:create';
    }

    public function getObjectType(): ?string
    {
        return 'page';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'template' => ['type' => 'string', 'description' => 'Cle du template, ex. confirmation-webinar.'],
            'title'    => ['type' => 'string', 'description' => 'Titre de la page dans Mautic.'],
            'alias'    => ['type' => 'string', 'description' => 'Slug de l URL. Genere depuis le titre si absent.'],
            'values'   => [
                'type'        => 'object',
                'description' => 'Texte de chaque emplacement, indexe par cle. '
                    .'Texte brut uniquement : le balisage est fourni par le template et toute valeur est echappee '
                    .'selon son contexte (HTML ou chaine JavaScript).',
                'additionalProperties' => ['type' => 'string'],
            ],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['template', 'title', 'values']);
    }

    public function execute(array $arguments): array
    {
        $key      = trim((string) ($arguments['template'] ?? ''));
        $template = $this->library->get($key);

        if (null === $template) {
            return [
                'status' => 'error',
                'error'  => sprintf('Template inconnu : %s. Disponibles : %s', $key, implode(', ', array_keys($this->library->all()))),
            ];
        }

        $title  = trim((string) ($arguments['title'] ?? ''));
        $values = (array) ($arguments['values'] ?? []);

        if ('' === $title) {
            return ['status' => 'error', 'error' => 'title est obligatoire.'];
        }

        $unknown = array_diff(array_map('strtoupper', array_keys($values)), $template->getPlaceholderKeys());

        if ([] !== $unknown) {
            return [
                'status' => 'error',
                'error'  => sprintf('Emplacements inconnus : %s. Utiliser list_page_templates.', implode(', ', $unknown)),
            ];
        }

        // Champs numeriques inseres sans guillemets dans leur contexte (objet JS
        // pour l'un, token Mautic {form=...} pour l'autre) : une valeur non
        // numerique y casserait la syntaxe plutot que d'echouer proprement.
        foreach (['DURATION_MINUTES', 'MAUTIC_FORM_ID'] as $numericKey) {
            $lower = strtolower($numericKey);

            if (isset($values[$numericKey]) || isset($values[$lower])) {
                $raw                  = $values[$numericKey] ?? $values[$lower];
                $values[$numericKey]  = (string) max(1, (int) $raw);
            }
        }

        foreach (['JOIN_LINK', 'PRIVACY_URL', 'TERMS_URL'] as $urlKey) {
            $url = trim((string) ($values[$urlKey] ?? $values[strtolower($urlKey)] ?? ''));

            if ('' !== $url && !preg_match('#^https?://#i', $url)) {
                return ['status' => 'error', 'error' => sprintf('%s doit etre une URL http(s) : %s', $urlKey, $url)];
            }
        }

        $rendered = $this->library->render($template, array_map('strval', $values));

        if ([] !== $rendered['missing']) {
            return [
                'status'  => 'error',
                'error'   => sprintf('Emplacements obligatoires manquants : %s', implode(', ', $rendered['missing'])),
                'missing' => $rendered['missing'],
            ];
        }

        $alias = $this->slugify((string) ($arguments['alias'] ?? $title));

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'     => 'page',
                'template' => $template->name,
                'title'    => $title,
                'alias'    => $alias,
                'apercu'   => array_intersect_key(
                    array_change_key_case($values, CASE_UPPER),
                    // Cle commune plausible selon le template ; celles qui ne
                    // s'appliquent pas au template choisi disparaissent d'elles-memes.
                    array_flip(['EVENT_TITLE', 'HERO_WORD_ONE', 'START_DATE', 'HERO_DATE_DAY', 'TIME_ZONE', 'JOIN_LINK', 'MAUTIC_FORM_ID']),
                ),
            ]);
        }

        $page = new Page();
        $page->setTitle($title);
        $page->setAlias($alias);
        $page->setCustomHtml($rendered['html']);
        // Jamais 'blank' ni un autre theme : mautic_code_mode fige l'edition en
        // code source, garantissant que le <script> du template survit a une
        // reouverture de la page dans l'interface Mautic.
        $page->setTemplate('mautic_code_mode');
        $page->setIsPublished((bool) ($arguments['is_published'] ?? false));

        $this->pageModel->saveEntity($page);

        return $this->ok([
            'id'       => $page->getId(),
            'title'    => $page->getTitle(),
            'alias'    => $page->getAlias(),
            'template' => $template->key,
            'url'      => '/s/pages/edit/'.$page->getId(),
            'note'     => 'Page creee non publiee, en mode code source (le JavaScript du template reste actif).',
        ]);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-') ?: 'page-'.time();
    }
}
