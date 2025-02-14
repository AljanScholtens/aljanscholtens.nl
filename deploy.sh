#!/bin/bash

# Bouw de Hugo site
hugo --minify

# Synchroniseer de public/ map met je remote server
rsync -avz --delete public/ aljanscholtensnl@aljans.ssh.transip.me:www