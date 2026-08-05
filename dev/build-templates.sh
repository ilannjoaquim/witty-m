#!/usr/bin/env bash
#
# Recompile les templates d'email : Templates/Email/<cle>/template.mjml -> template.html
#
# Le HTML est versionne avec le plugin parce que PHP ne sait pas compiler du
# MJML : le compilateur officiel est en Node. A relancer apres toute
# modification d'un .mjml, sinon le plugin continue de livrer l'ancien HTML.
#
# keepComments=false : les consignes de redaction restent dans le .mjml pour
# celui qui edite le template, mais ne partent pas chez le destinataire.

set -euo pipefail

PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
TEMPLATES="$PLUGIN_DIR/Templates/Email"
MJML_VERSION="${MJML_VERSION:-5}"

command -v npx >/dev/null || { echo "npx absent : installer Node.js." >&2; exit 1; }

built=0

for source in "$TEMPLATES"/*/template.mjml; do
    [ -e "$source" ] || continue

    target="$(dirname "$source")/template.html"

    npx --yes "mjml@$MJML_VERSION" "$source" -o "$target" \
        --config.keepComments false \
        --config.validationLevel strict

    # Un placeholder mal ferme passerait inapercu jusqu'a l'envoi.
    if grep -qE '\{[A-Z0-9_]+\}\}|\{\{[A-Z0-9_]+\}' "$target" && ! grep -qE '\{\{[A-Z0-9_]+\}\}' "$target"; then
        echo "Placeholder mal forme dans $target" >&2
        exit 1
    fi

    echo "compile : $(basename "$(dirname "$source")") ($(wc -c < "$target") octets)"
    built=$((built + 1))
done

echo "$built template(s) compile(s)."
