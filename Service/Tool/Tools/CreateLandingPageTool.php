<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\PageBundle\Entity\Page;
use Mautic\PageBundle\Model\PageModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class CreateLandingPageTool extends AbstractTool
{
    public function __construct(
        private PageModel $pageModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'create_landing_page';
    }

    public function getDescription(): string
    {
        return 'Cree une landing page a partir de HTML complet. '
            .'Le HTML peut contenir des tokens Mautic ({form=ID} pour embarquer un formulaire).';
    }

    public function isWriteOperation(): bool
    {
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'page:pages:create';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'title'        => ['type' => 'string', 'description' => 'Titre de la page.'],
            'alias'        => ['type' => 'string', 'description' => 'Slug de l URL. Genere depuis le titre si absent.'],
            'html'         => ['type' => 'string', 'description' => 'Document HTML complet.'],
            'meta_description' => ['type' => 'string'],
            'is_published' => ['type' => 'boolean', 'description' => 'Defaut false.'],
        ], ['title', 'html']);
    }

    public function execute(array $arguments): array
    {
        $title = trim((string) ($arguments['title'] ?? ''));
        $html  = (string) ($arguments['html'] ?? '');

        if ('' === $title || '' === $html) {
            return ['status' => 'error', 'error' => 'title et html sont obligatoires.'];
        }

        $alias = $this->slugify((string) ($arguments['alias'] ?? $title));

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'         => 'landing_page',
                'title'        => $title,
                'alias'        => $alias,
                'html_length'  => strlen($html),
                'html_excerpt' => mb_substr(strip_tags($html), 0, 300),
            ]);
        }

        $page = new Page();
        $page->setTitle($title);
        $page->setAlias($alias);
        $page->setCustomHtml($html);
        $page->setTemplate('blank');
        $page->setIsPublished((bool) ($arguments['is_published'] ?? false));

        if (!empty($arguments['meta_description'])) {
            $page->setMetaDescription((string) $arguments['meta_description']);
        }

        $this->pageModel->saveEntity($page);

        return $this->ok([
            'id'    => $page->getId(),
            'title' => $page->getTitle(),
            'alias' => $page->getAlias(),
            'url'   => '/s/pages/edit/'.$page->getId(),
        ]);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? $value;

        return trim($value, '-') ?: 'page-'.time();
    }
}
