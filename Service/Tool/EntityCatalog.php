<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Tool;

use Mautic\AssetBundle\Model\AssetModel;
use Mautic\CampaignBundle\Model\CampaignModel;
use Mautic\ChannelBundle\Model\MessageModel;
use Mautic\CoreBundle\Model\AbstractCommonModel;
use Mautic\CoreBundle\Security\Permissions\CorePermissions;
use Mautic\DynamicContentBundle\Model\DynamicContentModel;
use Mautic\EmailBundle\Model\EmailModel;
use Mautic\FormBundle\Model\FormModel;
use Mautic\LeadBundle\Model\ListModel;
use Mautic\PageBundle\Model\PageModel;
use Mautic\PointBundle\Model\PointGroupModel;
use Mautic\PointBundle\Model\PointModel;
use Mautic\PointBundle\Model\TriggerModel;
use Mautic\ProjectBundle\Model\ProjectModel;
use Mautic\ReportBundle\Model\ReportModel;
use Mautic\StageBundle\Model\StageModel;

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
    /**
     * 'flat' remplace 'own'/'other' pour les types sans notion de proprietaire
     * (Project : permissions plates edit/delete/create, pas de editown/editother).
     *
     * @var array<string, array{model: string, own?: string, other?: string, flat?: string, url: string}>
     */
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
        'asset' => [
            'model' => AssetModel::class,
            'own'   => 'asset:assets:%sown',
            'other' => 'asset:assets:%sother',
            'url'   => '/s/assets/edit/%d',
        ],
        'dynamic_content' => [
            'model' => DynamicContentModel::class,
            'own'   => 'dynamiccontent:dynamiccontents:%sown',
            'other' => 'dynamiccontent:dynamiccontents:%sother',
            'url'   => '/s/dwc/edit/%d',
        ],
        'stage' => [
            'model' => StageModel::class,
            'own'   => 'stage:stages:%sown',
            'other' => 'stage:stages:%sother',
            'url'   => '/s/stages/edit/%d',
        ],
        'point' => [
            'model' => PointModel::class,
            'own'   => 'point:points:%sown',
            'other' => 'point:points:%sother',
            'url'   => '/s/points/edit/%d',
        ],
        'point_trigger' => [
            'model' => TriggerModel::class,
            'own'   => 'point:triggers:%sown',
            'other' => 'point:triggers:%sother',
            'url'   => '/s/points/triggers/edit/%d',
        ],
        'point_group' => [
            'model' => PointGroupModel::class,
            'own'   => 'point:groups:%sown',
            'other' => 'point:groups:%sother',
            'url'   => '/s/points/groups/edit/%d',
        ],
        'report' => [
            'model' => ReportModel::class,
            'own'   => 'report:reports:%sown',
            'other' => 'report:reports:%sother',
            'url'   => '/s/reports/edit/%d',
        ],
        'project' => [
            'model' => ProjectModel::class,
            'flat'  => 'project:project:%s',
            'url'   => '/s/projects/edit/%d',
        ],
        'message' => [
            'model' => MessageModel::class,
            'own'   => 'channel:messages:%sown',
            'other' => 'channel:messages:%sother',
            'url'   => '/s/messages/edit/%d',
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
        AssetModel $assetModel,
        DynamicContentModel $dynamicContentModel,
        StageModel $stageModel,
        PointModel $pointModel,
        TriggerModel $triggerModel,
        PointGroupModel $pointGroupModel,
        ReportModel $reportModel,
        ProjectModel $projectModel,
        MessageModel $messageModel,
        private CorePermissions $security,
    ) {
        $this->models = [
            EmailModel::class          => $emailModel,
            PageModel::class           => $pageModel,
            ListModel::class           => $listModel,
            CampaignModel::class       => $campaignModel,
            FormModel::class           => $formModel,
            AssetModel::class          => $assetModel,
            DynamicContentModel::class => $dynamicContentModel,
            StageModel::class          => $stageModel,
            PointModel::class          => $pointModel,
            TriggerModel::class        => $triggerModel,
            PointGroupModel::class     => $pointGroupModel,
            ReportModel::class         => $reportModel,
            ProjectModel::class        => $projectModel,
            MessageModel::class        => $messageModel,
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

        if (isset(self::MAP[$type]['flat'])) {
            return $this->security->isGranted(sprintf(self::MAP[$type]['flat'], $operation));
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
