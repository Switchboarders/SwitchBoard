#!/bin/bash
set -e
./prepare_switchboard.sh
./generate_db_config.sh
./generate_mail_config.sh
# Les tâches cron démarrées ci-dessous ne reçoivent AUCUNE des variables d'environnement de
# ce conteneur par défaut - cron n'hérite pas de l'environnement du shell qui l'a lancé. Seul
# /etc/environment, lu par pam_env (voir /etc/pam.d/cron), est propagé aux tâches. Sans ceci,
# APP_ENCRYPTION_KEY (entre autres, réglé via "environment:" dans docker-compose) est
# invisible depuis refreshallringgroups.php etc. quand ils tournent via cron - alors que tout
# fonctionne normalement testé à la main (docker exec hérite bien de l'environnement du
# conteneur) - vérifié en conditions réelles (die() dans Chiffrement.php à chaque exécution
# cron malgré une clé correctement configurée dans .env).
#
# Cron jobs started below receive NONE of this container's environment variables by default -
# cron does not inherit the launching shell's environment. Only /etc/environment, read by
# pam_env (see /etc/pam.d/cron), is propagated to jobs. Without this, APP_ENCRYPTION_KEY
# (among others, set via "environment:" in docker-compose) is invisible from
# refreshallringgroups.php etc. when they run via cron - even though everything works fine
# tested by hand (docker exec correctly inherits the container's environment) - confirmed
# against a real deployment (die() in Chiffrement.php on every cron run despite a correctly
# configured key in .env).
printenv > /etc/environment
# Start cron jobs
service cron start
# Fix ownership of necessary directories/files
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /var/log
# /var/run est un lien symbolique vers /run sur cette image : chown -R sur /var/run ne
# suit PAS le lien par défaut (donc ne changeait rien à /run lui-même) - on cible /run
# directement, requis ci-dessous pour que www-data puisse y écrire son fichier pid.
chown -R www-data:www-data /run
chmod -R 775 /var/www/html
# Set umask
umask 0002

# php-fpm est configuré par défaut (docker.conf, image officielle) pour écrire
# error_log/access.log sur /proc/self/fd/2, afin d'apparaître dans `docker logs`. Ce
# chemin ne peut être RÉOUVERT (contrairement à y écrire directement) que par le
# processus root qui l'a créé - une fois www-data en place via gosu ci-dessous, le
# réouvrir échoue avec "Permission denied" et php-fpm ne démarre pas du tout. On les
# redirige donc vers de vrais fichiers, avant de perdre les privilèges root nécessaires
# pour les créer. On fixe aussi un fichier pid explicite (absent par défaut) : sans lui,
# logrotate (voir utils/logrotate) n'a aucun moyen de signaler à php-fpm de réouvrir ses
# journaux après une rotation.
touch /var/log/php-fpm-error.log /var/log/php-fpm-access.log
chown www-data:www-data /var/log/php-fpm-error.log /var/log/php-fpm-access.log
sed -i 's|/proc/self/fd/2|/var/log/php-fpm-error.log|; s|access.log = /var/log/php-fpm-error.log|access.log = /var/log/php-fpm-access.log|; s|^error_log = /var/log/php-fpm-error.log|pid = /run/php-fpm.pid\nerror_log = /var/log/php-fpm-error.log|' /usr/local/etc/php-fpm.d/docker.conf

# Bascule vers www-data pour le processus principal (php-fpm) : tout ce qui précède
# (chown, cron) nécessite root, mais l'application elle-même n'a pas besoin de tourner
# en root. cron a déjà été démarré comme service détaché ci-dessus et continue de
# fonctionner indépendamment de ce changement d'utilisateur.
exec gosu www-data "$@"
