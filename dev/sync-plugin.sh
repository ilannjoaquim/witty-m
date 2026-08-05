#!/usr/bin/env bash
#
# Copie le plugin dans l'instance Mautic locale, vide le cache et recharge les
# plugins. A relancer apres chaque modification du code du plugin.
#
# On copie au lieu de faire un lien symbolique : l'instance vit *dans* le dossier
# du plugin, un lien creerait une boucle plugins/WittyBundle -> ... -> plugins/WittyBundle.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTANCE="${MAUTIC_INSTANCE:-$PLUGIN_DIR/mautic-dev}"
TARGET="$INSTANCE/plugins/WittyBundle"

if [[ ! -f "$INSTANCE/bin/console" ]]; then
    echo "Instance Mautic introuvable dans $INSTANCE (lancer dev/setup-mautic.sh)." >&2
    exit 1
fi

mkdir -p "$TARGET"

rsync -a --delete \
    --exclude '.git/' \
    --exclude '.claude/' \
    --exclude 'mautic-dev/' \
    --exclude 'dev/' \
    --exclude '*.zip' \
    "$PLUGIN_DIR/" "$TARGET/"

cd "$INSTANCE"
php bin/console cache:clear --no-interaction
php bin/console mautic:plugins:reload --no-interaction

echo "Plugin synchronise dans $TARGET"
