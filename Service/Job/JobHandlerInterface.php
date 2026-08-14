<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Job;

use MauticPlugin\WittyBundle\Entity\WittyBackgroundJob;

/**
 * Traite UN job de type volumineux (enrichissement/recherche a l'echelle,
 * cf. WittyBackgroundJob) par petits lots successifs, jamais d'un bloc.
 *
 * Une classe = un type de job, identifie par getType() (ex.
 * "apollo_bulk_enrich_people") : Command/ProcessBackgroundJobsCommand.php
 * choisit le handler a appeler en comparant WittyBackgroundJob::getType() a
 * cette valeur, exactement comme ToolRegistry choisit un outil par son nom.
 * Auto-enregistre via le tag witty.job_handler (meme mecanisme que
 * witty.tool/witty.mcp_client, cf. Config/services.php) : ajouter un type de
 * job = deposer une classe, rien a cabler ailleurs.
 */
interface JobHandlerInterface
{
    public function getType(): string;

    /**
     * true UNIQUEMENT si ce handler n'appelle AUCUNE API externe a debit
     * limite (Apollo/QuickEnrich/un serveur MCP) : dans ce cas,
     * Command/ProcessBackgroundJobsCommand.php peut appeler processChunk()
     * plusieurs fois de suite sur ce meme job, tant qu'il reste du budget de
     * temps sur ce passage de cron, au lieu d'un seul lot. C'est ainsi que
     * Mautic gere lui-meme un import CSV volumineux (LeadBundle\Model\ImportModel::process(),
     * aucune coupure de temps, boucle jusqu'a la fin du fichier en un seul
     * appel CLI) — sauf que la, c'est borne au budget commun du cron partage
     * par tous les types de job, jamais un import qui monopolise
     * indefiniment le creneau. Pour un handler qui appelle un fournisseur
     * externe, renvoyer false : plusieurs passages rapproches dans le meme
     * cron risqueraient de taper la limite de debit du fournisseur bien plus
     * vite qu'un rythme d'un lot par minute.
     */
    public function allowsMultiplePassesPerTick(): bool;

    /**
     * Traite UN lot borne (quelques dizaines d'elements maximum, une poignee
     * d'appels API) a partir de l'etat courant du job (getParams() figes a la
     * creation, getCursor() qui avance a chaque appel) :
     * - persiste un WittyBackgroundJobItem par element traite dans ce lot
     *   (succeeded/failed/skipped) ;
     * - avance getCursor() jusqu'au point de reprise pour le prochain appel ;
     * - incremente processedItems/succeededItems/failedItems ;
     * - bascule le statut sur completed (plus rien a traiter) via
     *   setStatus()+setDateCompleted(), ou laisse running si le lot suivant
     *   reste a faire.
     *
     * Ne doit JAMAIS lever d'exception pour une erreur normale (ex. l'API
     * distante indisponible pour un lot) : c'est au handler de decider si
     * c'est irrecuperable (job -> failed, avec setErrorMessage()) ou
     * transitoire (laisser running, le prochain tick de cron reessaiera). Une
     * exception qui s'echappe est traitee par le runner comme un echec dur du
     * job (cf. Command/ProcessBackgroundJobsCommand.php), a n'utiliser que
     * pour un bug reellement inattendu.
     */
    public function processChunk(WittyBackgroundJob $job): void;
}
