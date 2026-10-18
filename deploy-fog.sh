#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"

if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--check' ]; }; then
    echo 'Gebruik: ./deploy-fog.sh [--check]' >&2
    exit 2
fi

host='aljanscholtensnl@aljans.ssh.transip.me'
stage='private/fog-bot-stage'

# Controleer eerst een volledige kopie buiten de publieke website.
ssh -o BatchMode=yes "$host" "mkdir -p $stage/www $stage/private"
rsync -az static/fog-weather.php static/fog-feedback.php \
    static/fog-alert.php static/fog-bot.php "$host:$stage/www/"
rsync -az ops/fog-morning-cron.php "$host:$stage/private/"
ssh -o BatchMode=yes "$host" "php -l $stage/www/fog-weather.php && php -l $stage/www/fog-feedback.php && php -l $stage/www/fog-alert.php && php -l $stage/www/fog-bot.php && php -l $stage/private/fog-morning-cron.php"
ssh -o BatchMode=yes "$host" "FOG_BOT_ROOT=\$HOME/$stage php" < tests/fog-morning-decision.php
ssh -o BatchMode=yes "$host" "FOG_BOT_ROOT=\$HOME/$stage php" < tests/fog-forecast-smoke.php

if [ "${1:-}" = '--check' ]; then
    echo 'Bot gecontroleerd; de live bestanden zijn niet gewijzigd.'
    exit
fi

# Bewaar de vorige live versie en vervang alleen de vijf botbestanden.
ssh -o BatchMode=yes "$host" '
    set -e
    mkdir -p private/fog-bot-previous
    cp www/fog-weather.php www/fog-feedback.php www/fog-alert.php www/fog-bot.php private/fog-morning-cron.php private/fog-bot-previous/
    cp private/fog-bot-stage/www/fog-weather.php private/fog-bot-stage/www/fog-feedback.php private/fog-bot-stage/www/fog-alert.php private/fog-bot-stage/www/fog-bot.php www/
    cp private/fog-bot-stage/private/fog-morning-cron.php private/fog-morning-cron.php
    chmod 600 private/fog-morning-cron.php
    cmp private/fog-bot-stage/www/fog-weather.php www/fog-weather.php
    cmp private/fog-bot-stage/www/fog-feedback.php www/fog-feedback.php
    cmp private/fog-bot-stage/www/fog-alert.php www/fog-alert.php
    cmp private/fog-bot-stage/www/fog-bot.php www/fog-bot.php
    cmp private/fog-bot-stage/private/fog-morning-cron.php private/fog-morning-cron.php
'
echo 'Botbestanden bijgewerkt; planning en privéconfiguratie zijn niet gewijzigd.'
