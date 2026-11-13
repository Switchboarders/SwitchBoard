#!/bin/bash
# Génère /etc/msmtprc à partir des variables d'environnement SB_SMTP_* (voir .env), sur le
# même principe que generate_db_config.sh pour la base de données : plus rien n'est figé dans
# l'image, changer un mot de passe de courriel se fait dans .env puis ./up.sh, sans
# reconstruire l'image.
#
# Contrairement à generate_db_config.sh, l'absence de configuration ne fait PAS échouer le
# conteneur : le courriel est important (SwitchBoard s'en sert pour signaler les erreurs) mais
# ne doit jamais empêcher l'application elle-même de démarrer.
#
# Generates /etc/msmtprc from the SB_SMTP_* environment variables (see .env), on the same
# principle as generate_db_config.sh for the database: nothing is baked into the image,
# changing a mail password happens in .env then ./up.sh, no image rebuild needed.
#
# Unlike generate_db_config.sh, missing configuration does NOT fail the container: mail
# matters (SwitchBoard uses it to report errors) but must never prevent the app itself from
# starting.

SB_SMTP_TLS="${SB_SMTP_TLS:-on}"

# Si .env ne définit pas SB_SMTP_OAUTH_PROVIDER, vérifie les réglages enregistrés depuis
# l'assistant web intégré (console admin -> Courriel OAuth) via la même fonction PHP que
# mailoauthtoken.php et la console admin (reglagesOAuthCourriel() dans lesconstantes.php) -
# permet de tout configurer depuis le navigateur, sans jamais éditer .env, même en Docker.
# Il suffit de redémarrer le conteneur php une fois après avoir utilisé l'assistant pour que
# CE script (qui ne tourne qu'au démarrage) régénère /etc/msmtprc en mode OAuth ; le
# rafraîchissement du jeton ensuite (mailoauthtoken.php) lit toujours la config à jour, sans
# jamais avoir besoin d'un autre redémarrage.
if [ -z "${SB_SMTP_OAUTH_PROVIDER:-}" ]; then
    REGLAGES_ASSISTANT=$(php -r '
        require "/var/www/html/SwitchBoard/SwitchBoard/src/commun/lesconstantes.php";
        $r = reglagesOAuthCourriel();
        echo $r["fournisseur"]."\n".$r["mailbox_user"]."\n".$r["client_id"];
    ' 2>/dev/null)
    FOURNISSEUR_ASSISTANT=$(printf '%s\n' "$REGLAGES_ASSISTANT" | sed -n '1p')
    if [ -n "$FOURNISSEUR_ASSISTANT" ]; then
        SB_SMTP_OAUTH_PROVIDER="$FOURNISSEUR_ASSISTANT"
        : "${SB_SMTP_USER:=$(printf '%s\n' "$REGLAGES_ASSISTANT" | sed -n '2p')}"
        : "${SB_SMTP_OAUTH_CLIENT_ID:=$(printf '%s\n' "$REGLAGES_ASSISTANT" | sed -n '3p')}"
    fi
fi

if [ -n "${SB_SMTP_OAUTH_PROVIDER:-}" ]; then
    # Mode OAuth2 (Microsoft 365 / Google, voir MAIL-OAUTH-SETUP.md) : pas de mot de passe
    # statique - msmtp appelle mailoauthtoken.php (passwordeval) à CHAQUE envoi pour obtenir
    # un jeton d'accès frais à partir du jeton de rafraîchissement stocké par la console admin
    # (?page=mailoauth). SB_SMTP_HOST/PORT restent personnalisables mais ont un défaut correct
    # par fournisseur.
    case "${SB_SMTP_OAUTH_PROVIDER}" in
        microsoft365)
            HOTE_PAR_DEFAUT="smtp.office365.com"
            ;;
        google)
            HOTE_PAR_DEFAUT="smtp.gmail.com"
            ;;
        *)
            echo "Avertissement : SB_SMTP_OAUTH_PROVIDER='${SB_SMTP_OAUTH_PROVIDER}' inconnu (attendu : microsoft365 ou google) - courriel non configuré."
            exit 0
            ;;
    esac
    if [ -z "${SB_SMTP_USER:-}" ] || [ -z "${SB_SMTP_OAUTH_CLIENT_ID:-}" ]; then
        echo "Avertissement : SB_SMTP_USER et SB_SMTP_OAUTH_CLIENT_ID sont requis en mode OAuth - courriel non configuré."
        exit 0
    fi
    SB_SMTP_HOST="${SB_SMTP_HOST:-$HOTE_PAR_DEFAUT}"
    SB_SMTP_PORT="${SB_SMTP_PORT:-587}"
    SB_SMTP_FROM="${SB_SMTP_FROM:-$SB_SMTP_USER}"

    cat > /etc/msmtprc << EOF
# Généré automatiquement par generate_mail_config.sh à partir de .env - ne pas éditer ici, ce
# fichier est recréé à chaque démarrage du conteneur.
account default
host ${SB_SMTP_HOST}
port ${SB_SMTP_PORT}
from ${SB_SMTP_FROM}
user ${SB_SMTP_USER}
auth xoauth2
# Chemin absolu requis : passwordeval est exécuté par msmtp via un sous-shell dont le PATH
# n'inclut pas toujours /usr/local/bin (ex.: lorsque déclenché par une tâche cron), ce qui
# donnait "sh: 1: php: not found" et un échec silencieux de l'envoi - vérifié en conditions
# réelles (le rapport d'erreur pour un numéro de téléphone invalide ne partait jamais).
#
# Absolute path required: passwordeval is run by msmtp through a sub-shell whose PATH
# doesn't always include /usr/local/bin (e.g. when triggered by a cron job), which produced
# "sh: 1: php: not found" and a silent send failure - confirmed against a real deployment
# (the error report for an invalid phone number was never actually sent).
passwordeval "/usr/local/bin/php /var/www/html/SwitchBoard/SwitchBoard/src/mailoauthtoken.php"
tls ${SB_SMTP_TLS}
tls_starttls on
EOF

    if ! chmod 600 /etc/msmtprc || ! chown www-data:www-data /etc/msmtprc; then
        echo "Avertissement : impossible de fixer les droits sur /etc/msmtprc."
    fi
    echo "Configuration de courriel générée avec succès (OAuth ${SB_SMTP_OAUTH_PROVIDER}, hôte : ${SB_SMTP_HOST})."
    echo "Si l'autorisation n'a pas encore été faite, terminez-la depuis la console admin (Courriel OAuth)."
    exit 0
fi

if [ -z "${SB_SMTP_HOST:-}" ]; then
    echo "Avertissement : SB_SMTP_HOST n'est pas défini - le courriel n'est pas configuré."
    echo "Les notifications d'erreurs/avertissements de SwitchBoard ne seront pas envoyées."
    echo "Voir SB_SMTP_* dans .env, puis testez depuis la console admin (Courriel de test)."
    exit 0
fi

SB_SMTP_PORT="${SB_SMTP_PORT:-587}"
SB_SMTP_FROM="${SB_SMTP_FROM:-$SB_SMTP_USER}"

cat > /etc/msmtprc << EOF
# Généré automatiquement par generate_mail_config.sh à partir de .env - ne pas éditer ici, ce
# fichier est recréé à chaque démarrage du conteneur.
account default
host ${SB_SMTP_HOST}
port ${SB_SMTP_PORT}
from ${SB_SMTP_FROM}
user ${SB_SMTP_USER}
password ${SB_SMTP_PASSWORD}
tls ${SB_SMTP_TLS}
tls_starttls on
auth on
EOF

if ! chmod 600 /etc/msmtprc || ! chown www-data:www-data /etc/msmtprc; then
    echo "Avertissement : impossible de fixer les droits sur /etc/msmtprc."
fi

echo "Configuration de courriel générée avec succès (hôte : ${SB_SMTP_HOST})."
