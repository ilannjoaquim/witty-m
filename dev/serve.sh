#!/usr/bin/env bash
#
# Sert l'instance Mautic locale avec le serveur PHP integre.
# Plusieurs workers : l'interface Mautic enchaine des requetes ajax concurrentes,
# un serveur mono-processus se bloque lui-meme.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTANCE="${MAUTIC_INSTANCE:-$PLUGIN_DIR/mautic-dev}"
ADDRESS="${ADDRESS:-127.0.0.1:8000}"

cd "$INSTANCE"
PHP_CLI_SERVER_WORKERS="${PHP_CLI_SERVER_WORKERS:-6}" \
    exec php -S "$ADDRESS" -t . "$PLUGIN_DIR/dev/router.php"
