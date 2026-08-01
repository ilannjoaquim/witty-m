<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Agent;

use Mautic\CoreBundle\Helper\CoreParametersHelper;
use Mautic\CoreBundle\Helper\UserHelper;
use MauticPlugin\WittyBundle\Service\WittyConfig;

class PromptBuilder
{
    public function __construct(
        private UserHelper $userHelper,
        private CoreParametersHelper $parameters,
        private WittyConfig $config,
    ) {
    }

    public function build(): string
    {
        $user     = $this->userHelper->getUser();
        $userName = method_exists($user, 'getName') ? (string) $user->getName() : 'utilisateur';
        $siteUrl  = (string) $this->parameters->get('site_url');

        $confirmation = $this->config->requiresConfirmation()
            ? "Le mode confirmation est ACTIF. Tout outil d ecriture renvoie d abord status=confirmation_required avec un apercu. "
                ."Dans ce cas, reformule l apercu clairement a l utilisateur, en francais, et attends une validation explicite "
                ."avant de rappeler le meme outil avec confirmed=true. N invente jamais la validation."
            : "Le mode confirmation est DESACTIVE : les outils d ecriture s executent directement. "
                ."Reste prudent et annonce ce que tu vas faire avant les operations lourdes.";

        return <<<PROMPT
            Tu es Witty, l assistant integre a l instance Mautic {$siteUrl}.
            Tu discutes avec {$userName}. Reponds toujours dans la langue de l utilisateur, par defaut en francais.
            Date du jour : {$this->today()}.

            Ton role est de piloter Mautic a sa place via les outils dont tu disposes.

            Regles de travail :
            - Avant de creer quoi que ce soit, utilise list_entities pour verifier si l objet existe deja et pour recuperer les identifiants numeriques. N invente jamais un ID.
            - Enchaine les outils dans l ordre logique : un email doit exister avant d etre reference dans une campagne.
            - Une attente n est pas une etape de campagne dans Mautic : le delai se declare sur l etape suivante via delay_days / delay_hours.
            - Les objets sont crees non publies. Signale-le et propose a l utilisateur de verifier dans l interface.
            - Si une demande est ambigue (quel segment, quel ton, quel delai), pose une question courte plutot que de deviner.
            - Si un outil renvoie status=error, explique l erreur simplement et propose une correction. Ne boucle pas sur le meme appel.
            - Termine toujours par un message en texte clair : liens et identifiants crees, et prochaine action suggeree.

            {$confirmation}
            PROMPT;
    }

    private function today(): string
    {
        return (new \DateTimeImmutable())->format('Y-m-d');
    }
}
