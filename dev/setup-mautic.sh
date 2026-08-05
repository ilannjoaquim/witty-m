#!/usr/bin/env bash
#
# Installe l'instance Mautic locale de test dans mautic-dev/ puis y deploie le plugin.
# Idempotent : relancable sans risque, l'installation deja faite est detectee.
#
# Prerequis (a installer une fois, avec les droits root) :
#   sudo apt-get install -y php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring \
#        php8.4-curl php8.4-zip php8.4-intl php8.4-gd php8.4-bcmath mariadb-server
#   sudo systemctl enable --now mariadb
#   sudo mariadb -e "CREATE DATABASE IF NOT EXISTS mautic_dev CHARACTER SET utf8mb4;
#                    CREATE USER IF NOT EXISTS 'mautic'@'localhost' IDENTIFIED BY 'mautic';
#                    GRANT ALL ON mautic_dev.* TO 'mautic'@'localhost';"

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
INSTANCE="${MAUTIC_INSTANCE:-$PLUGIN_DIR/mautic-dev}"

MAUTIC_VERSION="${MAUTIC_VERSION:-7.1.3}"
SITE_URL="${SITE_URL:-http://127.0.0.1:8000}"

DB_HOST=127.0.0.1
DB_PORT=3306
DB_NAME="${DB_NAME:-mautic_dev}"
DB_USER="${DB_USER:-mautic}"
DB_PASS="${DB_PASS:-mautic}"

ADMIN_USER="${ADMIN_USER:-admin}"
# Mautic 7 refuse la connexion si zxcvbn note le mot de passe en dessous de 3
# (PasswordStrengthEstimatorModel), et "mautic" fait partie de son dictionnaire.
# Un "Mautic123!" s'installe sans broncher puis rend la connexion impossible.
ADMIN_PASS="${ADMIN_PASS:-WittyLocal-2026-Ardoise}"
ADMIN_EMAIL="${ADMIN_EMAIL:-admin@witty.test}"

command -v php >/dev/null || { echo "php absent : voir les prerequis en tete de script." >&2; exit 1; }

# 1. Sources Mautic
if [[ ! -f "$INSTANCE/bin/console" ]]; then
    echo "== Telechargement de Mautic $MAUTIC_VERSION"
    mkdir -p "$INSTANCE"
    tmp="$(mktemp -d)"
    curl -sL -o "$tmp/mautic.zip" \
        "https://github.com/mautic/mautic/releases/download/$MAUTIC_VERSION/$MAUTIC_VERSION.zip"
    unzip -q -o "$tmp/mautic.zip" -d "$INSTANCE"
    rm -rf "$tmp"
fi

# 2. Installation
if grep -q "'site_url'" "$INSTANCE/app/config/local.php" 2>/dev/null; then
    echo "== Mautic deja installe, etape ignoree"
else
    echo "== Installation de Mautic ($SITE_URL)"
    php "$INSTANCE/bin/console" mautic:install "$SITE_URL" \
        --force \
        --db_driver=pdo_mysql \
        --db_host="$DB_HOST" \
        --db_port="$DB_PORT" \
        --db_name="$DB_NAME" \
        --db_user="$DB_USER" \
        --db_password="$DB_PASS" \
        --admin_username="$ADMIN_USER" \
        --admin_password="$ADMIN_PASS" \
        --admin_email="$ADMIN_EMAIL" \
        --admin_firstname=Witty \
        --admin_lastname=Dev
fi

# 3. Plugin
"$PLUGIN_DIR/dev/sync-plugin.sh"

cat <<EOF

Instance prete : $INSTANCE
  URL      : $SITE_URL   (dev/serve.sh pour demarrer le serveur)
  Login    : $ADMIN_USER / $ADMIN_PASS
  Plugin   : Parametres > Plugins > Witty

Apres chaque modification du plugin : dev/sync-plugin.sh
EOF
