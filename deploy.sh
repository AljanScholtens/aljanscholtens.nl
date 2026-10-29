#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"

# Bouw de Hugo site
hugo --minify

# Stop als de build niet alle bestanden van de weerbot heeft meegenomen.
for file in fog-weather.php fog-feedback.php fog-alert.php fog-bot.php weer-data.php; do
    if [ ! -f "public/$file" ] || ! cmp -s "static/$file" "public/$file"; then
        echo "Deploy gestopt: public/$file ontbreekt of is verouderd." >&2
        exit 1
    fi
done

# Synchroniseer de public/ map met je remote server
rsync -avz --delete public/ aljanscholtensnl@aljans.ssh.transip.me:www
