<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Quickenrich\Exception;

/**
 * Erreur de transport ou reponse d'erreur de l'API QuickEnrich (recherche de
 * contacts). Meme role que les exceptions equivalentes de Prospeo/Apollo.
 *
 * getCode() porte le code HTTP renvoye par QuickEnrich quand il est connu
 * (0 pour un echec de transport, ex. timeout, cf. QuickenrichClient::request()) :
 * permet a un appelant de distinguer une erreur specifique a UNE requete
 * (422, donnee invalide) d'une vraie panne fournisseur.
 */
class QuickenrichException extends \RuntimeException
{
}
