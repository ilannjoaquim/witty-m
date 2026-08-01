<?php

declare(strict_types=1);

/*
 * Routeur pour `php -S` : le serveur integre n'a pas les regles de reecriture du
 * .htaccess de Mautic. Les fichiers existants (css, js, images, media) sont
 * servis tels quels, tout le reste part dans index.php.
 */

$docRoot = $_SERVER['DOCUMENT_ROOT'];
$path    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file    = $docRoot.urldecode($path);

if ('/' !== $path && is_file($file)) {
    return false;
}

$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $docRoot.'/index.php';

require $docRoot.'/index.php';
