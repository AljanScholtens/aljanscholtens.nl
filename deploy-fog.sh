#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"

if [ "$#" -gt 1 ] || { [ "$#" -eq 1 ] && [ "$1" != '--check' ] && [ "$1" != '--verify' ]; }; then
    echo 'Gebruik: ./deploy-fog.sh [--check|--verify]' >&2
    exit 2
fi

host='aljanscholtensnl@aljans.ssh.transip.me'
stage='private/fog-bot-stage'
# Publieke bestanden gaan naar ~/www, private taken naar ~/private.
www_files='fog-weather.php fog-feedback.php fog-alert.php fog-bot.php fog-web.php weer-data.php'
private_files='fog-morning-cron.php fog-evaluate.php fog-web-cron.php'

# Controleer eerst een volledige kopie buiten de publieke website.
ssh -o BatchMode=yes "$host" "mkdir -p $stage/www $stage/private"
rsync -az $(for file in $www_files; do echo "static/$file"; done) "$host:$stage/www/"
rsync -az $(for file in $private_files; do echo "ops/$file"; done) "$host:$stage/private/"
ssh -o BatchMode=yes "$host" "
    set -e
    for file in $www_files; do php -l $stage/www/\$file; done
    for file in $private_files; do php -l $stage/private/\$file; done
"
for test in fog-morning-decision fog-feedback-history fog-forecast-smoke fog-evaluate fog-web; do
    ssh -o BatchMode=yes "$host" "FOG_BOT_ROOT=\$HOME/$stage php" < "tests/$test.php"
done

verify_live() {
    ssh -o BatchMode=yes "$host" "
        set -e
        same() {
            php -r 'exit(is_file(\$argv[1]) && is_file(\$argv[2]) && hash_file(\"sha256\", \$argv[1]) === hash_file(\"sha256\", \$argv[2]) ? 0 : 1);' \"\$1\" \"\$2\"
        }
        for file in $www_files; do same $stage/www/\$file www/\$file; done
        for file in $private_files; do same $stage/private/\$file private/\$file; done
    "
}

if [ "${1:-}" = '--check' ]; then
    echo 'Bot gecontroleerd; de live bestanden zijn niet gewijzigd.'
    exit
fi
if [ "${1:-}" = '--verify' ]; then
    verify_live
    echo 'Live botbestanden komen exact overeen met de geteste versie.'
    exit
fi

# Bewaar de vorige live versie en vervang alleen de botbestanden.
ssh -o BatchMode=yes "$host" "
    set -e
    mkdir -p private/fog-bot-previous
    for file in $www_files; do
        if [ -f www/\$file ]; then cp www/\$file private/fog-bot-previous/; fi
        cp $stage/www/\$file www/
    done
    for file in $private_files; do
        if [ -f private/\$file ]; then cp private/\$file private/fog-bot-previous/; fi
        cp $stage/private/\$file private/\$file
        chmod 600 private/\$file
    done
"
verify_live
echo 'Botbestanden bijgewerkt; planning en privéconfiguratie zijn niet gewijzigd.'
