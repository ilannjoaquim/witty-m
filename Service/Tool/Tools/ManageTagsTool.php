<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool\Tools;

use Mautic\LeadBundle\Entity\Lead;
use Mautic\LeadBundle\Entity\Tag;
use Mautic\LeadBundle\Model\LeadModel;
use Mautic\LeadBundle\Model\TagModel;
use MauticPlugin\WittyBundle\Service\Tool\AbstractTool;
use MauticPlugin\WittyBundle\Service\WittyConfig;

/**
 * Lecture et pose de tags sur un contact.
 *
 * Mautic cree les tags inconnus a la volee lors de l'affectation : c'est le
 * comportement de modifyTags(), on ne le contourne pas.
 */
class ManageTagsTool extends AbstractTool
{
    public function __construct(
        private LeadModel $leadModel,
        private TagModel $tagModel,
        private WittyConfig $config,
    ) {
    }

    public function getName(): string
    {
        return 'manage_tags';
    }

    public function getDescription(): string
    {
        return 'Gere les tags de contacts. action=list pour lister les tags existants, '
            .'action=apply pour ajouter et/ou retirer des tags sur un contact identifie par son id ou son email. '
            .'Un tag inconnu est cree automatiquement lors de son affectation.';
    }

    public function isWriteOperation(): bool
    {
        // Seul apply ecrit ; le flux de confirmation est declenche dans execute()
        // uniquement pour cette action.
        return true;
    }

    public function getRequiredPermission(): ?string
    {
        return 'lead:leads:editown';
    }

    public function getObjectType(): ?string
    {
        return 'contact';
    }

    public function getSchema(): array
    {
        return $this->schema([
            'action' => [
                'type'        => 'string',
                'enum'        => ['list', 'apply'],
                'description' => 'list pour consulter, apply pour modifier les tags d un contact.',
            ],
            'contact_id'    => ['type' => 'integer', 'description' => 'Identifiant du contact, pour action=apply.'],
            'contact_email' => ['type' => 'string', 'description' => 'Alternative a contact_id.'],
            'add'           => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Tags a ajouter.',
            ],
            'remove' => [
                'type'        => 'array',
                'items'       => ['type' => 'string'],
                'description' => 'Tags a retirer.',
            ],
            'limit' => ['type' => 'integer', 'description' => 'Nombre de tags listes (defaut 50).'],
        ], ['action']);
    }

    public function execute(array $arguments): array
    {
        $action = (string) ($arguments['action'] ?? '');

        if ('list' === $action) {
            return $this->listTags(max(1, min(200, (int) ($arguments['limit'] ?? 50))));
        }

        if ('apply' !== $action) {
            return ['status' => 'error', 'error' => 'action doit valoir list ou apply.'];
        }

        $add    = array_values(array_filter(array_map('strval', (array) ($arguments['add'] ?? []))));
        $remove = array_values(array_filter(array_map('strval', (array) ($arguments['remove'] ?? []))));

        if ([] === $add && [] === $remove) {
            return ['status' => 'error', 'error' => 'Fournis au moins un tag dans add ou remove.'];
        }

        $lead = $this->resolveContact($arguments);

        if (!$lead instanceof Lead) {
            return ['status' => 'error', 'error' => 'Contact introuvable : fournis contact_id ou contact_email.'];
        }

        if ($this->config->requiresConfirmation() && true !== ($arguments['confirmed'] ?? false)) {
            return $this->confirmationRequired([
                'type'    => 'tags',
                'contact' => ['id' => $lead->getId(), 'email' => $lead->getEmail()],
                'add'     => $add,
                'remove'  => $remove,
            ]);
        }

        $this->leadModel->modifyTags($lead, $add, [] !== $remove ? $remove : null, true);

        return $this->ok([
            'id'    => $lead->getId(),
            'email' => $lead->getEmail(),
            'tags'  => array_values(array_map(
                static fn (Tag $tag): string => (string) $tag->getTag(),
                $lead->getTags()->toArray(),
            )),
            'url'   => '/s/contacts/view/'.$lead->getId(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listTags(int $limit): array
    {
        $tags = [];

        foreach ($this->tagModel->getEntities(['start' => 0, 'limit' => $limit]) as $tag) {
            if ($tag instanceof Tag) {
                $tags[] = ['id' => $tag->getId(), 'tag' => $tag->getTag()];
            }
        }

        return $this->ok(['count' => count($tags), 'tags' => $tags]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function resolveContact(array $arguments): ?Lead
    {
        if (!empty($arguments['contact_id'])) {
            return $this->leadModel->getEntity((int) $arguments['contact_id']);
        }

        $email = trim((string) ($arguments['contact_email'] ?? ''));

        if ('' === $email) {
            return null;
        }

        $matches = $this->leadModel->getRepository()->findBy(['email' => $email], null, 1);

        return $matches[0] ?? null;
    }
}
