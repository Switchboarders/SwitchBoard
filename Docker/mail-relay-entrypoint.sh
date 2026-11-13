#!/bin/bash
# Point d'entrée du conteneur sb_mail_relay : même image et mêmes réglages SB_SMTP_* que php, donc
# même msmtp et même jeton OAuth. Attend que php ait installé SwitchBoard (premier démarrage),
# génère /etc/msmtprc, puis lance le relais SMTP (smtprelay.php).
# Entrypoint of the sb_mail_relay container: same image and SB_SMTP_* settings as php, so same
# msmtp and OAuth token. Waits for php to install SwitchBoard (first start), generates
# /etc/msmtprc, then runs the SMTP relay.
cd /var/www/html
MARQUEUR=/var/www/html/SwitchBoard/SwitchBoard/config/configempty.php
until [ -f "$MARQUEUR" ] && [ -f /var/www/html/SwitchBoard/SwitchBoard/vendor/autoload.php ]; do
    echo "En attente de l'installation de SwitchBoard par le conteneur php..."
    sleep 5
done
cd /
/generate_mail_config.sh
exec gosu www-data php /smtprelay.php
