<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\PageBundle\Model\PageModel;

/**
 * Table de correspondance type d'objet -> modele, permissions et URL.
 *
 * Les outils de modification et de suppression acceptent plusieurs types :
 * la permission ne peut donc pas etre declaree une fois pour toutes sur l'outil,
 * elle se verifie objet par objet. Sans cela, un utilisateur autorise sur les
 * segments pourrait supprimer des emails via l'agent.
 */
class EntityCatalog
{
    /** @var array<string, array{model: string, own: string, other: string, url: string}> */
    private const MAP = [
        'email' => [
            'model' => EmailModel::class,
            'own'   => 'email:emails:%sown',
            'other' => 'email:emails:%sother',
            'url'   => '/s/emails/edit/%d',
        ],
        'page' => [
            'model' => PageModel::class,
            'own'   => 'page:pages:%sown',
            'other' => 'page:pages:%sother',
            'url'   => '/s/pages/edit/%d',
        ],
        'segment' => [
            'model' => ListModel::class,
            'own'   => 'lead:lists:%sown',
            'other' => 'lead:lists:%sother',
            'url'   => '/s/segments/edit/%d',
        ],
        'campaign' => [
            'model' => CampaignModel::class,
            'own'   => 'campaign:campaigns:%sown',
            'other' => 'campaign:campaigns:%sother',
            'url'   => '/s/campaigns/edit/%d',
        ],
        'form' => [
            'model' => FormModel::class,
            'own'   => 'form:forms:%sown',
            'other' => 'form:forms:%sother',
            'url'   => '/s/forms/edit/%d',
        ],
    ];

    /** @var array<string, AbstractCommonModel<object>> */
    private array $models;

    public function __construct(
        EmailModel $emailModel,
        PageModel $pageModel,
        ListModel $listModel,
        CampaignModel $campaignModel,
        FormModel $formModel,
        private CorePermissions $security,
    ) {
        $this->models = [
            EmailModel::class    => $emailModel,
            PageModel::class     => $pageModel,
            ListModel::class     => $listModel,
            CampaignModel::class => $campaignModel,
            FormModel::class     => $formModel,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function getTypes(): array
    {
        return array_keys(self::MAP);
    }

    public function supports(string $type): bool
    {
        return isset(self::MAP[$type]);
    }

    public function getModel(string $type): ?object
    {
        if (!isset(self::MAP[$type])) {
            return null;
        }

        return $this->models[self::MAP[$type]['model']] ?? null;
    }

    public function getUrl(string $type, int $id): ?string
    {
        return isset(self::MAP[$type]) ? sprintf(self::MAP[$type]['url'], $id) : null;
    }

    /**
     * @param string $operation 'edit' ou 'delete'
     */
    public function isAllowed(string $type, string $operation, ?object $entity): bool
    {
        if (!isset(self::MAP[$type])) {
            return false;
        }

        $owner = null !== $entity && method_exists($entity, 'getCreatedBy') ? $entity->getCreatedBy() : 0;

        return $this->security->hasEntityAccess(
            sprintf(self::MAP[$type]['own'], $operation),
            sprintf(self::MAP[$type]['other'], $operation),
            $owner ?? 0,
        );
    }

    /**
     * Libelle lisible d'un objet, quel que soit son type.
     */
    public function describe(object $entity): string
    {
        foreach (['getName', 'getTitle', 'getSubject'] as $getter) {
            if (method_exists($entity, $getter)) {
                $value = $entity->{$getter}();

                if (is_string($value) && '' !== $value) {
                    return $value;
                }
            }
        }

        return '#'.(method_exists($entity, 'getId') ? (string) $entity->getId() : '?');
    }
}
