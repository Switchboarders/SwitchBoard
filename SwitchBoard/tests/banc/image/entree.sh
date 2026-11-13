#!/bin/bash
# Démarre nginx, qui a besoin de root pour ses ports, puis passe la main au vrai entrypoint du serveur
# principal. Celui-ci trouve l'installation déjà en place et ne clone rien.
set -e
nginx
exec /entrypoint.sh "$@"
