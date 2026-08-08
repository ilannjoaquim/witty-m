<?php

declare(strict_types=1);

namespace MauticPlugin\WittyBundle\Service\Attachment\Exception;

/**
 * Fichier refuse (extension non autorisee, trop lourd) ou tableur illisible
 * (format inattendu, trop de lignes pour un import synchrone).
 */
class AttachmentInvalidException extends \RuntimeException
{
}
