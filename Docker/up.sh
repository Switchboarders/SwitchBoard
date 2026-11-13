#!/bin/bash
# Point d'entrée unique pour installer et démarrer SwitchBoard (Linux, macOS, WSL).
# Sous Windows : up.cmd (ou up.ps1), qui fait la même chose.
#
# Pourquoi ce script : il y a deux fichiers docker-compose possibles
# (docker-compose.standalone.yml en mode autonome avec reverse-proxy
# intégré, ou docker-compose.reverse-proxy.yml si un reverse-proxy externe
# gère déjà les ports 80/443). Après un `git pull`, il est facile d'oublier
# lequel utiliser et de relancer le mauvais par habitude
# (`docker compose up -d`, qui prend "docker-compose.yml" par défaut - un
# fichier factice qui refuse de démarrer, voir plus bas), ce qui peut faire
# démarrer un reverse-proxy en double ou un conflit de port.
#
# Ce script mémorise le mode choisi en ajoutant COMPOSE_FILE=... à .env
# (non versionné, donc jamais modifié par un git pull). Docker Compose lit
# automatiquement cette variable dans .env pour toute commande "docker
# compose ..." lancée sans -f depuis ce dossier : up, mais aussi down, ps,
# logs, restart, exec, etc. fonctionnent alors directement, sans wrapper.
#
# Installation sans aucune question (automatisation, tests) :
#   ./up.sh --non-interactive [--mode=standalone|reverse-proxy] [--host=sb.mondomaine.com]
#           [--email=moi@exemple.com] [--tz=America/Toronto] [--no-wait]
#   --prepare-only : prépare et vérifie la configuration (.env, jeton, nginx.conf, mode) sans
#                    démarrer SwitchBoard.
# Les mêmes réglages peuvent venir de l'environnement : SB_MODE, SB_HOST, SB_EMAIL, SB_TZ,
# SB_GITHUB_TOKEN (facultatif pour le dépôt public, vérifié auprès de GitHub ; jamais en argument : il resterait
# visible dans `ps` et l'historique) et les
# SB_SMTP_* (copiés tels quels dans .env). Sans terminal (stdin redirigé), le mode sans question
# est automatique. Les autres arguments sont transmis à `docker compose up`.
# Lancé avec « sh up.sh » (dash, busybox) : ce script a besoin de bash, on le relance avec lui.
if [ -z "${BASH_VERSION:-}" ]; then
    if command -v bash >/dev/null 2>&1; then exec bash "$0" "$@"; fi
    echo "up.sh a besoin de bash. Installez-le (Alpine : apk add bash), puis relancez ./up.sh."
    exit 1
fi
set -e
# Dossier du script, sans dépendre de dirname
case "$0" in */*) cd "${0%/*}" ;; esac

NON_INTERACTIF="${SB_NON_INTERACTIVE:-0}"
ATTENDRE=1
PREPARER_SEULEMENT=0
MODE="${SB_MODE:-}"
HOTE="${SB_HOST:-}"
COURRIEL="${SB_EMAIL:-}"
FUSEAU="${SB_TZ:-}"
COMPOSE_ARGS=()
for ARG in "$@"; do
    case "$ARG" in
        -y|--yes|--non-interactive) NON_INTERACTIF=1 ;;
        --mode=*) MODE="${ARG#*=}" ;;
        --host=*) HOTE="${ARG#*=}" ;;
        --email=*) COURRIEL="${ARG#*=}" ;;
        --tz=*) FUSEAU="${ARG#*=}" ;;
        --no-wait) ATTENDRE=0 ;;
        --prepare-only) PREPARER_SEULEMENT=1 ;;
        -h|--help)
            sed -n '2,/^set -e/p' "$0" | sed '$d' | sed 's/^# \{0,1\}//'
            exit 0 ;;
        *) COMPOSE_ARGS+=("$ARG") ;;
    esac
done
[ -t 0 ] || NON_INTERACTIF=1
case "$MODE" in
    ""|standalone|autonome|1) MODE_FICHIER="docker-compose.standalone.yml" ;;
    reverse-proxy|proxy|2) MODE_FICHIER="docker-compose.reverse-proxy.yml" ;;
    *) echo "Mode inconnu : $MODE (standalone ou reverse-proxy)"; exit 1 ;;
esac

# Vérification préalable : Docker et le plugin "docker compose" (pas l'ancien
# docker-compose autonome) doivent déjà être installés. On ne les installe jamais
# soi-même (trop risqué de le faire sans confirmation explicite) - seulement des
# instructions claires et copiables, pour quelqu'un qui n'a jamais utilisé Docker.
if ! command -v docker >/dev/null 2>&1; then
    echo "Docker n'est pas installé sur ce serveur."
    echo ""
    echo "Pour l'installer (Linux) :"
    echo "    curl -fsSL https://get.docker.com | sh"
    echo "Sur Windows/Mac, installez Docker Desktop : https://www.docker.com/products/docker-desktop"
    echo ""
    echo "Relancez ensuite ./up.sh."
    exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
    echo "Le plugin \"docker compose\" (intégré à Docker, pas l'ancien script docker-compose) n'est pas disponible."
    echo "Il est inclus avec Docker Desktop et les installations récentes de Docker Engine."
    echo "Voir https://docs.docker.com/compose/install/"
    exit 1
fi
if ! ERREUR_DOCKER=$(docker info 2>&1 >/dev/null); then
    if printf '%s' "$ERREUR_DOCKER" | grep -qi 'permission denied'; then
        echo "Docker refuse l'accès à l'utilisateur « $(id -un) »."
        echo "  Ajoutez-le au groupe docker :  sudo usermod -aG docker $(id -un)"
        echo "  puis déconnectez-vous et reconnectez-vous (ou redémarrez), et relancez ./up.sh."
        echo "  (Ou relancez avec : sudo ./up.sh)"
    elif printf '%s' "$ERREUR_DOCKER" | grep -qiE 'cannot connect|is the docker daemon running|no such file'; then
        echo "Docker est installé mais son service n'est pas démarré."
        echo "  Démarrez-le :  sudo systemctl enable --now docker   (Alpine : sudo service docker start)"
        echo "  ou ouvrez Docker Desktop, puis relancez ./up.sh."
    else
        echo "Docker est installé mais ne répond pas :"
        printf '%s\n' "$ERREUR_DOCKER" | tail -n 3 | sed 's/^/  /'
    fi
    exit 1
fi
# depends_on « restart: true » (php recréé => web redémarré) demande Docker Compose 2.17 ou plus.
VERSION_COMPOSE=$(docker compose version --short 2>/dev/null | sed 's/^v//')
MAJEUR_COMPOSE=${VERSION_COMPOSE%%.*}; MINEUR_COMPOSE=${VERSION_COMPOSE#*.}; MINEUR_COMPOSE=${MINEUR_COMPOSE%%.*}
if [ -n "$VERSION_COMPOSE" ] && { [ "${MAJEUR_COMPOSE:-0}" -lt 2 ] 2>/dev/null \
        || { [ "$MAJEUR_COMPOSE" = 2 ] && [ "${MINEUR_COMPOSE:-0}" -lt 17 ] 2>/dev/null; }; }; then
    echo "Docker Compose $VERSION_COMPOSE est trop ancien : SwitchBoard demande la version 2.17 ou plus récente."
    echo "Mettez Docker à jour (curl -fsSL https://get.docker.com | sh), puis relancez ./up.sh."
    exit 1
fi

# Raspberry Pi et autres cartes ARM : il faut un système 64 bits. MariaDB (et donc SwitchBoard)
# n'existe plus en ARM 32 bits ; c'est le cas d'un Raspberry Pi OS 32 bits, même sur un Pi 4 ou 5.
ARCH_DOCKER=$(docker version --format '{{.Server.Arch}}' 2>/dev/null || true)
case "$ARCH_DOCKER" in
    arm|armhf|armel|386)
        echo "Ce Docker fonctionne en 32 bits ($ARCH_DOCKER) : SwitchBoard demande un système 64 bits."
        echo "Sur un Raspberry Pi (3, 4, 5 ou Zero 2 W), installez « Raspberry Pi OS (64-bit) » avec"
        echo "Raspberry Pi Imager, puis Docker (curl -fsSL https://get.docker.com | sh) et relancez ./up.sh."
        exit 1 ;;
esac

# Une autre copie de SwitchBoard (autre dossier) utilise déjà les mêmes conteneurs (sb_*) : la
# démarrer ici remplacerait ses conteneurs par ceux-ci, avec une autre base de données. Vérifié avant
# tout le reste (.env compris) pour donner la vraie raison.
DOSSIER_ICI=$(pwd -P)
for C in sb_php-fpm sb_mariadb; do
    DOSSIER_AUTRE=$(docker inspect -f '{{ index .Config.Labels "com.docker.compose.project.working_dir" }}' "$C" 2>/dev/null || true)
    [ -n "$DOSSIER_AUTRE" ] || continue
    if [ ! -d "$DOSSIER_AUTRE" ]; then
        echo "Le conteneur $C vient d'une installation dont le dossier n'existe plus ($DOSSIER_AUTRE)."
        echo "Supprimez ses conteneurs :  docker rm -f sb_letsencrypt sb_nginx-proxy sb_web sb_web_server sb_php-fpm sb_mariadb"
        echo "puis relancez ./up.sh."
        exit 1
    fi
    if [ "$(cd "$DOSSIER_AUTRE" && pwd -P)" != "$DOSSIER_ICI" ]; then
        echo "Une autre installation de SwitchBoard existe déjà sur cette machine : $DOSSIER_AUTRE"
        echo "Une seule peut fonctionner à la fois (mêmes noms de conteneurs). Utilisez ce dossier-là,"
        echo "ou arrêtez-la d'abord :  (cd \"$DOSSIER_AUTRE\" && docker compose down)"
        exit 1
    fi
done

ENV_FILE=".env"
ENV_CREATION=".env.creation"
# Une création de .env interrompue (Ctrl+C pendant les questions) ne laisse qu'un fichier
# temporaire, jamais un .env à moitié rempli (mots de passe d'exemple, etc.).
rm -f "$ENV_CREATION"

# .env modifié sous Windows (Bloc-notes) : retire la marque BOM et les fins de ligne CRLF, que
# Docker Compose lirait comme faisant partie des valeurs (« localhost\r », mot de passe faux...).
if [ -f "$ENV_FILE" ]; then
    if [ ! -w "$ENV_FILE" ]; then
        echo "$ENV_FILE n'est pas modifiable par « $(id -un) » (créé avec sudo ?)."
        echo "  Corrigez :  sudo chown $(id -un) $ENV_FILE nginx/nginx.conf   ou relancez avec sudo ./up.sh"
        exit 1
    fi
    BOM=$(head -c 3 "$ENV_FILE" | od -An -tx1 | tr -d ' \n')
    if [ "$BOM" = "efbbbf" ] || grep -q "$(printf '\r')" "$ENV_FILE"; then
        if [ "$BOM" = "efbbbf" ]; then tail -c +4 "$ENV_FILE"; else cat "$ENV_FILE"; fi | tr -d '\r' > "$ENV_CREATION"
        cat "$ENV_CREATION" > "$ENV_FILE" && rm -f "$ENV_CREATION"
        echo "$ENV_FILE : format Windows (BOM / CRLF) corrigé."
    fi
fi

# Échappe une valeur pour l'utiliser en toute sécurité comme partie droite d'une commande
# sed 's|...|VALEUR|' : un mot de passe de courriel ou un jeton GitHub tapé par l'utilisateur
# peut contenir \, & ou le délimiteur | lui-même, ce qui casserait (ou corromprait
# silencieusement) une substitution sed non protégée.
echapper_pour_sed() {
    printf '%s' "$1" | sed -e 's/[\&|]/\\&/g'
}
# Remplace UNE_VARIABLE=... par UNE_VARIABLE=valeur dans .env, seulement si une valeur a été
# fournie (une réponse vide laisse la valeur d'exemple/existante intacte). Ajoute la ligne si
# elle n'existe pas encore.
definir_variable() {
    local cle="$1" valeur="$2"
    if [ -n "$valeur" ]; then
        if grep -q "^${cle}=" "$ENV_FILE"; then
            sed -i.bak "s|^${cle}=.*|${cle}=$(echapper_pour_sed "$valeur")|" "$ENV_FILE" && rm -f "$ENV_FILE.bak"
        else
            printf '%s=%s\n' "$cle" "$valeur" >> "$ENV_FILE"
        fi
    fi
}
valeur_variable() {
    grep "^$1=" "$ENV_FILE" 2>/dev/null | tail -n 1 | cut -d= -f2-
}
# Octets aléatoires en base64 ; openssl n'est pas toujours installé (Alpine, images minimales).
secret_aleatoire() {
    if command -v openssl >/dev/null 2>&1; then
        openssl rand -base64 "$1"
    else
        head -c "$1" /dev/urandom | base64
    fi | tr -d '\n'
}
# Fuseau horaire de l'hôte (America/Toronto, etc.), vide si introuvable.
fuseau_hote() {
    local f=""
    if [ -f /etc/timezone ]; then
        f=$(head -n 1 /etc/timezone)
    elif [ -L /etc/localtime ]; then
        f=$(readlink /etc/localtime | sed 's|.*zoneinfo/||')
    elif command -v timedatectl >/dev/null 2>&1; then
        f=$(timedatectl show -p Timezone --value 2>/dev/null || true)
    fi
    if printf '%s' "$f" | grep -Eq '^[A-Za-z_]+(/[A-Za-z0-9_+-]+)*$'; then
        printf '%s' "$f"
    fi
}
# Adresses IPv4 de cette machine sur le réseau local (sans les ponts Docker ni le loopback).
adresses_lan() {
    {
        if command -v ip >/dev/null 2>&1; then
            ip -4 -o addr show scope global 2>/dev/null \
                | awk '$2 !~ /^(docker|br-|veth|virbr|cni|flannel|tailscale|wg|tun)/ {print $4}' | cut -d/ -f1
        elif command -v hostname >/dev/null 2>&1; then
            hostname -I 2>/dev/null | tr ' ' '\n'
        fi
    } | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | grep -v '^127\.' | sort -u
}

# .env supprimé alors qu'une base de données existe déjà : en générer un nouveau créerait d'autres
# mots de passe (la base les refuserait) et une autre clé de chiffrement (secrets illisibles).
# Nom d'hôte tel qu'on le tape souvent (« https://SB.exemple.com/ ») : minuscules, sans schéma,
# chemin, port ni espaces.
nettoyer_hote() {
    printf '%s' "$1" | tr 'A-Z' 'a-z' | sed -e 's/[[:space:]]//g' -e 's|^[a-z]*://||' -e 's|[/:?#].*$||' -e 's/\.$//'
}
# Affiche le problème d'un nom d'hôte (rien s'il est valide).
probleme_hote() {
    if [ -z "$1" ]; then
        echo "le nom d'hôte est vide"
    elif printf '%s' "$1" | grep -Eq '^[0-9.]+$'; then
        echo "« $1 » est une adresse IP : pour un accès par adresse IP, utilisez localhost (SwitchBoard répond aussi à l'adresse IP de la machine)"
    elif ! printf '%s' "$1" | grep -Eq '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?$'; then
        echo "« $1 » n'est pas un nom de domaine valide (ex. : sb.mondomaine.com, ou localhost)"
    fi
}

# --host donné : vérifié avant de créer quoi que ce soit.
if [ -n "$HOTE" ]; then
    HOTE=$(nettoyer_hote "$HOTE")
    PROBLEME=$(probleme_hote "$HOTE")
    if [ -n "$PROBLEME" ]; then
        echo "--host : $PROBLEME."
        exit 1
    fi
fi

if [ ! -f "$ENV_FILE" ] && [ -d db_data ] && [ -n "$(ls -A db_data 2>/dev/null || echo illisible)" ]; then
    echo "Le fichier $ENV_FILE est introuvable, mais une base de données SwitchBoard existe déjà (db_data/)."
    echo "Un nouveau $ENV_FILE aurait d'autres mots de passe et une autre clé de chiffrement : la base les"
    echo "refuserait et les secrets enregistrés seraient perdus."
    echo "  - Remettez votre sauvegarde de $ENV_FILE dans ce dossier, puis relancez ./up.sh ;"
    echo "  - ou, pour TOUT recommencer en effaçant les données : docker compose down 2>/dev/null;"
    echo "    sudo rm -rf db_data web   puis relancez ./up.sh."
    exit 1
fi

if [ ! -f "$ENV_FILE" ]; then
    if [ ! -f ".env_sample" ]; then
        echo "Erreur : .env_sample introuvable."
        exit 1
    fi
    if [ "$NON_INTERACTIF" = "1" ]; then
        echo "Première utilisation : configuration automatique (sans question)."
    else
        echo "Première utilisation : quelques questions rapides pour configurer SwitchBoard."
    fi
    echo "(Vous pourrez toujours modifier ces réglages plus tard directement dans $ENV_FILE.)"
    echo ""
    # Tout est écrit dans un fichier temporaire, renommé en .env seulement à la fin.
    ENV_FINAL="$ENV_FILE"; ENV_FILE="$ENV_CREATION"
    trap 'rm -f "$ENV_CREATION"' EXIT
    cp .env_sample "$ENV_FILE"
    # Remplace les mots de passe d'exemple par des valeurs aléatoires : ne jamais démarrer
    # avec les mots de passe documentés en clair dans .env_sample (donc publics).
    for VAR in MYSQL_ROOT_PASSWORD MYSQL_PASSWORD; do
        RANDOM_VALUE=$(secret_aleatoire 24 | tr -d '=+/')
        definir_variable "$VAR" "$RANDOM_VALUE"
    done
    # Clé de chiffrement des secrets enregistrés (voip.ms, SBChat, MAS) : 32 octets en base64.
    definir_variable "APP_ENCRYPTION_KEY" "$(secret_aleatoire 32)"
    echo "Mots de passe de la base de données et clé de chiffrement générés aléatoirement."
    echo "Sauvegardez $ENV_FILE avec vos sauvegardes : sans APP_ENCRYPTION_KEY, les secrets enregistrés sont perdus."
    echo ""

    FUSEAU="${FUSEAU:-$(fuseau_hote)}"
    definir_variable "TZ" "$FUSEAU"

    if [ "$NON_INTERACTIF" = "1" ]; then
        definir_variable "VIRTUAL_HOST" "${HOTE:-localhost}"
        definir_variable "DEFAULT_EMAIL" "$COURRIEL"
        for VAR in SB_SMTP_HOST SB_SMTP_PORT SB_SMTP_USER SB_SMTP_PASSWORD SB_SMTP_FROM SB_SMTP_TLS \
                   SB_SMTP_OAUTH_PROVIDER SB_SMTP_OAUTH_CLIENT_ID SB_SMTP_OAUTH_CLIENT_SECRET SB_SMTP_OAUTH_TENANT; do
            definir_variable "$VAR" "${!VAR:-}"
        done
    else
        while :; do
            if [ -z "$HOTE" ]; then
                read -r -p "Nom de domaine (ex.: sb.mondomaine.com), ou Entrée pour 'localhost' (test local ou réseau local) : " HOTE
            fi
            HOTE=$(nettoyer_hote "${HOTE:-localhost}")
            PROBLEME=$(probleme_hote "$HOTE")
            [ -z "$PROBLEME" ] && break
            echo "  $PROBLEME."
            HOTE=""
        done
        definir_variable "VIRTUAL_HOST" "${HOTE:-localhost}"

        if [ -z "$COURRIEL" ]; then
            read -r -p "Adresse courriel pour les certificats TLS (Let's Encrypt) : " COURRIEL
        fi
        definir_variable "DEFAULT_EMAIL" "$COURRIEL"

        echo ""
        echo "Courriel (SwitchBoard s'en sert pour signaler les erreurs affectant le système) :"
        echo "  1) Mot de passe simple (Google, etc.)"
        echo "  2) OAuth2 - Microsoft 365 ou Google (nécessaire si Microsoft 365 refuse les mots"
        echo "     de passe simples pour le SMTP - voir MAIL-OAUTH-SETUP.md)"
        echo "  3) Ignorer pour l'instant"
        read -r -p "Choix [1/2/3] : " CHOIX_COURRIEL
        case "$CHOIX_COURRIEL" in
            2)
                read -r -p "  Fournisseur (microsoft365/google) : " REPONSE_OAUTH_PROVIDER
                definir_variable "SB_SMTP_OAUTH_PROVIDER" "$REPONSE_OAUTH_PROVIDER"
                read -r -p "  Adresse courriel (compte à autoriser) : " REPONSE_SMTP_USER
                definir_variable "SB_SMTP_USER" "$REPONSE_SMTP_USER"
                read -r -p "  Client ID (voir MAIL-OAUTH-SETUP.md pour l'obtenir) : " REPONSE_OAUTH_CLIENT_ID
                definir_variable "SB_SMTP_OAUTH_CLIENT_ID" "$REPONSE_OAUTH_CLIENT_ID"
                if [ "$REPONSE_OAUTH_PROVIDER" = "google" ]; then
                    read -r -s -p "  Client Secret (requis pour Google, pas pour Microsoft 365) : " REPONSE_OAUTH_CLIENT_SECRET
                    echo ""
                    definir_variable "SB_SMTP_OAUTH_CLIENT_SECRET" "$REPONSE_OAUTH_CLIENT_SECRET"
                fi
                echo "  Configuré. Terminez l'autorisation depuis la console admin (Courriel OAuth) une fois SwitchBoard démarré."
                ;;
            1)
                read -r -p "  Serveur SMTP (ex.: smtp.gmail.com) : " REPONSE_SMTP_HOST
                definir_variable "SB_SMTP_HOST" "$REPONSE_SMTP_HOST"
                read -r -p "  Port (Entrée pour 587) : " REPONSE_SMTP_PORT
                definir_variable "SB_SMTP_PORT" "$REPONSE_SMTP_PORT"
                read -r -p "  Nom d'utilisateur : " REPONSE_SMTP_USER
                definir_variable "SB_SMTP_USER" "$REPONSE_SMTP_USER"
                read -r -s -p "  Mot de passe (ex.: mot de passe d'application Google, pas votre mot de passe habituel) : " REPONSE_SMTP_PASSWORD
                echo ""
                definir_variable "SB_SMTP_PASSWORD" "$REPONSE_SMTP_PASSWORD"
                ;;
            *)
                echo "  Courriel ignoré pour l'instant - voir SB_SMTP_*/SB_SMTP_OAUTH_* dans $ENV_FILE."
                ;;
        esac
    fi

    mv "$ENV_FILE" "$ENV_FINAL"; ENV_FILE="$ENV_FINAL"
    trap - EXIT
    echo ""
    echo "Configuration initiale terminée ($ENV_FILE créé)."
    echo ""
fi

# --- Nom d'hôte (VIRTUAL_HOST) : nettoyé et vérifié à chaque lancement (il peut venir de --host, de
# la question ou d'une modification à la main de .env).
HOTE_ENV=$(valeur_variable VIRTUAL_HOST)
HOTE_PROPRE=$(nettoyer_hote "$HOTE_ENV")
PROBLEME=$(probleme_hote "$HOTE_PROPRE")
if [ -n "$PROBLEME" ]; then
    echo "VIRTUAL_HOST dans $ENV_FILE : $PROBLEME."
    echo "Corrigez-le (VIRTUAL_HOST=sb.mondomaine.com ou VIRTUAL_HOST=localhost), puis relancez ./up.sh."
    exit 1
fi
if [ "$HOTE_PROPRE" != "$HOTE_ENV" ]; then
    definir_variable "VIRTUAL_HOST" "$HOTE_PROPRE"
    echo "VIRTUAL_HOST corrigé : « $HOTE_ENV » devient « $HOTE_PROPRE »."
fi

# --- Clé de chiffrement (APP_ENCRYPTION_KEY) : vérifiée à chaque lancement --------------------
# SwitchBoard ignore sans rien dire une clé absente ou mal formée, et enregistre alors les secrets
# (voip.ms, SB Chat, MAS, courriel) en clair. Une clé vide est générée : rien n'a pu être chiffré
# sans clé. Format attendu : 32 octets en base64 (44 caractères).
CLE_GENEREE=0
CLE=$(valeur_variable APP_ENCRYPTION_KEY | tr -d " \"'")
if [ -z "$CLE" ]; then
    definir_variable "APP_ENCRYPTION_KEY" "$(secret_aleatoire 32)"
    CLE_GENEREE=1
    echo "Clé de chiffrement (APP_ENCRYPTION_KEY) absente de $ENV_FILE : une clé aléatoire a été générée."
    echo "Sauvegardez $ENV_FILE avec vos sauvegardes : sans cette clé, les secrets enregistrés sont perdus."
elif ! printf '%s' "$CLE" | grep -Eq '^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=?$'; then
    echo "APP_ENCRYPTION_KEY dans $ENV_FILE n'est pas une clé valide (32 octets en base64, 44 caractères) :"
    echo "SwitchBoard l'ignorerait et enregistrerait les secrets en clair."
    echo "  - si vous avez une sauvegarde de $ENV_FILE, remettez-y la clé d'origine ;"
    echo "  - sinon, videz la ligne (APP_ENCRYPTION_KEY=) et relancez ./up.sh : une clé sera générée."
    exit 1
fi

# --- Jeton GitHub : facultatif pour le dépôt public, vérifié s'il est donné -------------------
# Le dépôt SwitchBoard est public : le conteneur php le télécharge sans jeton. Un jeton ne sert qu'à
# une copie privée du dépôt, ou à lever la limite de GitHub pour les requêtes sans jeton. On vérifie
# l'accès auprès de GitHub avant de construire quoi que ce soit, par la même requête que `git clone`
# (lecture du dépôt), plutôt que de le découvrir dans le journal de php.
DEPOT_REFS="https://github.com/Switchboarders/SwitchBoard.git/info/refs?service=git-upload-pack"
# Lit le dépôt avec ce jeton (vide : sans jeton). Affiche : ok, refuse (401, 403, 404), reseau ou tls.
# Le jeton ne passe jamais en argument (visible dans `ps`) : curl le lit sur son entrée standard.
lecture_depot() {
    local jeton="$1" config="" code rc=0
    [ -z "$jeton" ] || config=$(printf 'user = "x-access-token:%s"' "$jeton")
    if command -v curl >/dev/null 2>&1; then
        code=$(printf '%s\n' "$config" | curl -s -o /dev/null -w '%{http_code}' --max-time 20 -K - "$DEPOT_REFS" 2>/dev/null) || rc=$?
    else
        # Pas de curl sur l'hôte (Alpine, images minimales) : celui de l'image nginx, utilisée de
        # toute façon par SwitchBoard.
        code=$(SB_CONFIG="$config" docker run --rm -e SB_CONFIG nginx:stable-alpine sh -c \
            'printf "%s\n" "$SB_CONFIG" | curl -s -o /dev/null -w "%{http_code}" --max-time 20 -K - "$0"' \
            "$DEPOT_REFS" 2>/dev/null) || rc=$?
    fi
    # curl 35/51/58/60 : échec TLS (date de la machine fausse, proxy qui intercepte le trafic...).
    case "$rc" in 35|51|58|60) echo tls; return ;; esac
    case "$code" in
        200) echo ok ;;
        401|403|404) echo refuse ;;
        *) echo reseau ;;
    esac
}
# Affiche : ok (le jeton lit le dépôt), public (aucun jeton, dépôt public), vide (aucun jeton, dépôt
# privé), refuse_public (jeton refusé, mais le dépôt est public : on continue sans lui), refuse (jeton
# refusé et dépôt privé), reseau ou tls.
etat_jeton() {
    local jeton="$1" etat
    if [ -z "$jeton" ]; then
        etat=$(lecture_depot "")
        case "$etat" in ok) echo public ;; refuse) echo vide ;; *) echo "$etat" ;; esac
        return
    fi
    if printf '%s' "$jeton" | grep -Eq '^[A-Za-z0-9_]+$'; then etat=$(lecture_depot "$jeton"); else etat=refuse; fi
    if [ "$etat" = "refuse" ] && [ "$(lecture_depot "")" = "ok" ]; then etat=refuse_public; fi
    echo "$etat"
}
expliquer_jeton() {
    case "$1" in
        vide) echo "Le dépôt SwitchBoard n'est pas public : un jeton GitHub est nécessaire pour le télécharger." ;;
        refuse) echo "GitHub refuse ce jeton : inconnu, expiré, ou sans accès en lecture au dépôt Switchboarders/SwitchBoard." ;;
        reseau) echo "Impossible de joindre GitHub. Vérifiez la connexion Internet de cette machine." ;;
        tls) echo "La connexion sécurisée à GitHub échoue. Vérifiez la date et l'heure de cette machine (actuellement : $(date '+%Y-%m-%d %H:%M')), ou un proxy/antivirus qui intercepterait le trafic." ;;
    esac
}
aide_jeton() {
    echo "Créez un jeton sur GitHub : photo de profil > Settings > Developer settings > Personal access tokens"
    echo "> Fine-grained tokens, avec l'accès en lecture à « Contents » et « Metadata » sur le dépôt"
    echo "(ou un jeton classique avec le droit « repo »). Voir README.fr.md#before-you-start."
}
# SwitchBoard déjà téléchargé (installation terminée) : le jeton ne sert plus qu'aux mises à jour.
deja_installe() {
    [ -f web/SwitchBoard/SwitchBoard/config/configempty.php ] \
        || docker exec sb_php-fpm test -f /var/www/html/SwitchBoard/SwitchBoard/config/configempty.php 2>/dev/null
}

# Un jeton fourni pour ce lancement (SB_GITHUB_TOKEN) remplace celui de .env.
# Espaces, retours à la ligne et guillemets autour du jeton (copier-coller, GITHUB_TOKEN="...") retirés.
JETON=$(printf '%s' "${SB_GITHUB_TOKEN:-$(valeur_variable GITHUB_TOKEN)}" | tr -d "[:space:]\"'")
echo "Vérification de l'accès au dépôt GitHub..."
ETAT_JETON=$(etat_jeton "$JETON")
while [ "$ETAT_JETON" != "ok" ] && [ "$ETAT_JETON" != "public" ] && [ "$ETAT_JETON" != "refuse_public" ]; do
    INSTALLE=0; deja_installe && INSTALLE=1
    echo ""
    expliquer_jeton "$ETAT_JETON"
    if [ "$NON_INTERACTIF" = "1" ]; then
        if [ "$INSTALLE" = "1" ]; then
            echo "SwitchBoard est déjà installé : on continue, mais les mises à jour automatiques échoueront"
            echo "tant que GITHUB_TOKEN (dans $ENV_FILE) n'est pas valide."
            break
        fi
        aide_jeton
        echo "Mettez-le dans $ENV_FILE (GITHUB_TOKEN=...) ou dans la variable SB_GITHUB_TOKEN, puis relancez ./up.sh."
        exit 1
    fi
    aide_jeton
    if [ "$INSTALLE" = "1" ]; then
        read -r -s -p "Jeton GitHub (Entrée pour continuer sans le changer) : " REPONSE_TOKEN
        echo ""
        if [ -z "$REPONSE_TOKEN" ]; then
            echo "Jeton inchangé : les mises à jour automatiques échoueront tant qu'il n'est pas valide."
            break
        fi
    else
        read -r -s -p "Jeton GitHub (Ctrl+C pour quitter) : " REPONSE_TOKEN
        echo ""
    fi
    JETON=$(printf '%s' "$REPONSE_TOKEN" | tr -d "[:space:]\"'")
    ETAT_JETON=$(etat_jeton "$JETON")
done
case "$ETAT_JETON" in
    ok)
        echo "Jeton GitHub valide."
        [ "$JETON" = "$(valeur_variable GITHUB_TOKEN)" ] || definir_variable "GITHUB_TOKEN" "$JETON"
        ;;
    public)
        echo "Dépôt public : aucun jeton GitHub n'est nécessaire."
        ;;
    refuse_public)
        # Jamais enregistré : un jeton refusé ne sert à rien
        echo "GitHub refuse ce jeton (inconnu, expiré ou révoqué) : il est ignoré, le dépôt est public."
        echo "Retirez-le de $ENV_FILE (GITHUB_TOKEN=) ou remplacez-le."
        ;;
esac
echo ""

NGINX_CONF="nginx/nginx.conf"
if [ ! -f "$NGINX_CONF" ]; then
    if [ ! -f "nginx/nginx.conf.sample" ]; then
        echo "Erreur : nginx/nginx.conf.sample introuvable."
        exit 1
    fi
    cp nginx/nginx.conf.sample "$NGINX_CONF"
    echo "$NGINX_CONF créé à partir de nginx.conf.sample (gitignoré, jamais recréé après ce premier"
    echo "démarrage — un \`git pull\` ne l'écrasera donc jamais). Pensez à y changer server_name si"
    echo "vous n'utilisez pas localhost (voir README.md)."
fi

if ! grep -q '^COMPOSE_FILE=' "$ENV_FILE"; then
    if [ "$NON_INTERACTIF" != "1" ] && [ -z "$MODE" ]; then
        echo "Premier démarrage : quel mode utiliser ?"
        echo "  1) Autonome - SwitchBoard gère son propre reverse-proxy + TLS, accessible sur le réseau local (docker-compose.standalone.yml)"
        echo "  2) Reverse-proxy externe - un autre service gère déjà les ports 80/443 (docker-compose.reverse-proxy.yml)"
        read -r -p "Choix [1/2] : " CHOICE
        case "$CHOICE" in
            2) MODE_FICHIER="docker-compose.reverse-proxy.yml" ;;
            *) MODE_FICHIER="docker-compose.standalone.yml" ;;
        esac
    fi
    echo "COMPOSE_FILE=$MODE_FICHIER" >> "$ENV_FILE"
    echo "Mode enregistré dans $ENV_FILE (COMPOSE_FILE=$MODE_FICHIER)."
    echo "Pour changer de mode : éditez ou retirez cette ligne dans $ENV_FILE."
fi
MODE_ACTIF=$(valeur_variable COMPOSE_FILE)
echo "Mode actif : $MODE_ACTIF"
case "$MODE_ACTIF" in
    *standalone*) AUTONOME=1; CONTENEUR_WEB="sb_web" ;;
    *) AUTONOME=0; CONTENEUR_WEB="sb_web_server" ;;
esac

if [ "$PREPARER_SEULEMENT" = "1" ]; then
    echo "Configuration prête (--prepare-only : SwitchBoard n'est pas démarré)."
    exit 0
fi

# Réseau partagé "web" (déclaré external dans les fichiers compose) : on le crée au besoin
# au lieu de demander un `docker network create web` manuel.
if ! docker network inspect web >/dev/null 2>&1; then
    docker network create web >/dev/null
    echo "Réseau Docker « web » créé."
fi

if [ "$AUTONOME" = "1" ]; then
    # Accès depuis le réseau local : le certificat auto-signé couvre aussi le nom de cette
    # machine et ses adresses IP (recalculées à chaque ./up.sh, au cas où le DHCP en change).
    # nom.local : annoncé sur le réseau par mDNS (Avahi sur Raspberry Pi OS, Bonjour chez Apple)
    NOMS_CERT="$(uname -n),$(uname -n | cut -d. -f1).local"
    for IP in $(adresses_lan); do NOMS_CERT="$NOMS_CERT,$IP"; done
    definir_variable "SB_CERT_SANS" "$NOMS_CERT"

    # Les ports 80/443 doivent être libres (sauf s'ils sont déjà à notre propre reverse-proxy).
    if ! docker ps --format '{{.Names}}' | grep -qx 'sb_nginx-proxy'; then
        for PORT in 80 443; do
            if (exec 3<>"/dev/tcp/127.0.0.1/$PORT") 2>/dev/null; then
                echo "Le port $PORT est déjà utilisé sur cette machine (un autre serveur web ?)."
                echo "Libérez-le, ou utilisez le mode reverse-proxy externe (retirez COMPOSE_FILE de $ENV_FILE"
                echo "puis relancez : ./up.sh --mode=reverse-proxy)."
                exit 1
            fi
        done
    fi
fi

# Construction d'abord, avec un second essai : certaines versions de Docker (dont le paquet docker.io
# d'Ubuntu) échouent parfois en construisant deux images en même temps (« parent snapshot ... does
# not exist »), alors qu'un nouvel essai réussit.
# La sortie de Docker est gardée pour expliquer un échec en mots simples.
JOURNAL_DOCKER=$(mktemp "${TMPDIR:-/tmp}/switchboard-up.XXXXXX")
trap 'rm -f "$JOURNAL_DOCKER"' EXIT
expliquer_erreur_docker() {
    echo ""
    if ! docker info >/dev/null 2>&1; then
        echo "Docker s'est arrêté pendant l'installation. Redémarrez-le (sudo systemctl restart docker, ou"
        echo "Docker Desktop), puis relancez ./up.sh : l'installation reprend où elle en était."
    elif grep -qi 'no space left on device' "$JOURNAL_DOCKER"; then
        echo "Le disque est plein. Libérez de l'espace (df -h montre l'espace libre ; docker system prune -a"
        echo "supprime les images Docker inutilisées), puis relancez ./up.sh."
    elif grep -qiE 'toomanyrequests|rate limit' "$JOURNAL_DOCKER"; then
        echo "Docker Hub limite le nombre de téléchargements depuis votre adresse. Attendez une heure, ou"
        echo "connectez-vous avec un compte Docker Hub gratuit (docker login), puis relancez ./up.sh."
    elif grep -qiE 'x509|certificate (has expired|is not yet valid)' "$JOURNAL_DOCKER"; then
        echo "Erreur de certificat pendant un téléchargement. Vérifiez la date et l'heure de cette machine"
        echo "(actuellement : $(date '+%Y-%m-%d %H:%M')) ou un proxy qui intercepterait le trafic, puis relancez ./up.sh."
    elif grep -qiE 'no such host|i/o timeout|TLS handshake timeout|network is unreachable|temporary failure in name resolution|could not resolve|connection reset|unexpected EOF' "$JOURNAL_DOCKER"; then
        echo "Un téléchargement a échoué (réseau). Vérifiez la connexion Internet de cette machine, puis relancez"
        echo "./up.sh : ce qui est déjà téléchargé est gardé."
    elif grep -qiE 'port is already allocated|address already in use' "$JOURNAL_DOCKER"; then
        echo "Le port 80 ou 443 est déjà utilisé par un autre programme. Libérez-le, ou utilisez le mode"
        echo "reverse-proxy externe (retirez COMPOSE_FILE de $ENV_FILE puis : ./up.sh --mode=reverse-proxy)."
    elif grep -qiE 'is already in use by container|Conflict\. The container name' "$JOURNAL_DOCKER"; then
        echo "Des conteneurs SwitchBoard (sb_*) d'une autre installation existent déjà. Supprimez-les :"
        echo "  docker rm -f sb_letsencrypt sb_nginx-proxy sb_web sb_web_server sb_php-fpm sb_mariadb"
        echo "puis relancez ./up.sh (les données de ce dossier-ci ne sont pas touchées)."
    else
        echo "Voyez les messages ci-dessus. Relancez ./up.sh ; si l'erreur revient, joignez ces messages à"
        echo "votre demande d'aide."
    fi
}
construire() { docker compose build 2>&1 | tee "$JOURNAL_DOCKER"; return "${PIPESTATUS[0]}"; }
if ! construire; then
    # Pas de second essai quand il ne peut pas réussir.
    if ! docker info >/dev/null 2>&1 || grep -qiE 'no space left on device|toomanyrequests' "$JOURNAL_DOCKER" \
            || ! { echo "La construction des images a échoué ; nouvel essai..."; construire; }; then
        echo ""
        echo "La construction des images de SwitchBoard a échoué."
        expliquer_erreur_docker
        exit 1
    fi
fi
docker compose up -d "${COMPOSE_ARGS[@]}" 2>&1 | tee "$JOURNAL_DOCKER"; RC_UP=${PIPESTATUS[0]}
if [ "$RC_UP" != 0 ]; then
    echo ""
    echo "Le démarrage des conteneurs de SwitchBoard a échoué."
    expliquer_erreur_docker
    exit 1
fi

# Premier démarrage : le conteneur php télécharge SwitchBoard et ses dépendances, ce qui prend
# quelques minutes. On attend que ce soit prêt pour ne pas laisser croire que l'installation a
# échoué (erreur 502 dans le navigateur entre-temps).
# La base accepte-t-elle les identifiants de .env ? Affiche ok, refus ou attente (pas encore prête).
# Ils ne servent qu'à la création de la base : les changer ensuite dans .env (ou recréer .env) les
# rend faux. Vérifié seulement une fois le vrai serveur prêt : pendant sa création, un serveur
# temporaire sans réseau (« port: 0 ») peut refuser une connexion.
etat_db() {
    docker logs sb_mariadb 2>&1 | grep -q '^Version: .*port: 3306' || { echo attente; return; }
    local sortie
    # Même commande que up.ps1 (sans guillemets internes, que Windows PowerShell transmet mal).
    sortie=$(docker exec sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -e status $MYSQL_DATABASE' 2>&1 || true)
    if printf '%s' "$sortie" | grep -qE 'Access denied|Unknown database'; then echo refus
    elif printf '%s' "$sortie" | grep -q 'Current database'; then echo ok
    else echo attente; fi
}
expliquer_refus_db() {
    echo ""
    echo "La base de données refuse les identifiants de $ENV_FILE (MYSQL_USER, MYSQL_PASSWORD ou"
    echo "MYSQL_DATABASE). Ils ne servent qu'à la création de la base : s'ils ont été changés depuis,"
    echo "ou si $ENV_FILE a été recréé, remettez les anciennes valeurs (sauvegarde de $ENV_FILE),"
    echo "puis relancez ./up.sh."
}

if [ "$ATTENDRE" = "1" ]; then
    echo ""
    echo "Préparation de SwitchBoard (le premier démarrage peut prendre quelques minutes)..."
    PRET=0
    # On attend tant que le conteneur php avance (clone, dépendances : nouvelles lignes dans son
    # journal) ; abandon après 15 minutes sans aucun progrès, ou 60 minutes au total (un Raspberry
    # Pi ou une connexion lente peuvent dépasser 15 minutes sans problème).
    DERNIER_ETAT=""
    INACTIF=0
    for _ in $(seq 1 720); do
        if docker exec sb_php-fpm test -f /var/www/html/SwitchBoard/SwitchBoard/config/config.php 2>/dev/null \
           && docker exec "$CONTENEUR_WEB" wget -q -O /dev/null --header "X-Forwarded-Proto: https" \
                http://127.0.0.1/SwitchBoard/SwitchBoard/src/managementportal.php 2>/dev/null; then
            # La page répond ; la base doit aussi accepter les identifiants (une page peut s'afficher
            # sans elle).
            ETAT_DB=$(etat_db)
            if [ "$ETAT_DB" = "ok" ]; then PRET=1; break; fi
            if [ "$ETAT_DB" = "refus" ]; then expliquer_refus_db; exit 1; fi
        fi
        # Dépôt privé sans jeton (ou jeton refusé) : inutile d'attendre 15 minutes.
        if docker logs --tail 40 sb_php-fpm 2>&1 | grep -Eq 'could not read Username|could not read Password|Authentication failed|Invalid username or (password|token)|Repository not found'; then
            echo ""
            echo "Le téléchargement de SwitchBoard depuis GitHub est refusé : le dépôt n'est pas public."
            echo "Mettez un jeton GitHub valide (droit de lecture « Contents » sur le dépôt)"
            echo "dans $ENV_FILE (GITHUB_TOKEN=...), puis relancez ./up.sh. Voir README.fr.md#before-you-start."
            exit 1
        fi
        if ! docker info >/dev/null 2>&1; then
            echo ""
            echo "Docker s'est arrêté pendant le premier démarrage. Redémarrez-le (sudo systemctl restart docker,"
            echo "ou Docker Desktop), puis relancez ./up.sh : l'installation reprend."
            exit 1
        fi
        if [ "$(docker inspect -f '{{.State.OOMKilled}}' sb_php-fpm 2>/dev/null)" = "true" ] \
                || docker logs --tail 20 sb_php-fpm 2>&1 | grep -Eq '(^|[^a-z])Killed$|Allowed memory size'; then
            echo ""
            echo "Le conteneur php manque de mémoire. Fermez d'autres programmes ou ajoutez de la mémoire (sur un"
            echo "Raspberry Pi : 2 Go ou plus ; dans Docker Desktop : Settings > Resources), puis relancez ./up.sh."
            exit 1
        fi
        # Toutes les 30 s : la base accepte-t-elle les identifiants de .env ? Ils ne servent qu'à sa
        # création : les changer ensuite dans .env (ou recréer .env) les rend faux pour toujours.
        TOUR=$((${TOUR:-0} + 1))
        if [ $((TOUR % 6)) = 0 ]; then
            # Deux refus de suite (30 s d'écart) avant de conclure.
            if [ "$(etat_db)" = "refus" ]; then REFUS_DB=$((${REFUS_DB:-0} + 1)); else REFUS_DB=0; fi
            if [ "$REFUS_DB" -ge 2 ]; then expliquer_refus_db; exit 1; fi
            if [ "$(docker inspect -f '{{.RestartCount}}' sb_mariadb 2>/dev/null || echo 0)" -ge 3 ]; then
                echo ""
                echo "La base de données (sb_mariadb) redémarre sans arrêt. Ses derniers messages :"
                docker logs --tail 15 sb_mariadb 2>&1 | sed 's/^/  | /'
                exit 1
            fi
        fi
        ETAT=$(docker logs --tail 1 sb_php-fpm 2>&1 | tail -c 300; docker inspect -f '{{.RestartCount}}' sb_php-fpm 2>/dev/null)
        if [ "$ETAT" != "$DERNIER_ETAT" ]; then
            DERNIER_ETAT="$ETAT"
            INACTIF=0
        else
            INACTIF=$((INACTIF + 5))
            [ "$INACTIF" -ge 900 ] && break
        fi
        sleep 5
    done
    if [ "$PRET" != "1" ]; then
        echo "SwitchBoard ne répond pas (plus aucun progrès depuis 15 minutes, ou plus d'une heure d'attente)."
        echo "Derniers messages du conteneur php :"
        docker logs --tail 20 sb_php-fpm 2>&1 | sed 's/^/  | /'
        echo "Journal complet : docker compose logs php. Relancez ./up.sh une fois le problème corrigé."
        exit 1
    fi
    echo "SwitchBoard est prêt."
fi

# --- Secrets enregistrés : chiffrés avec la clé de .env ---------------------------------------
# Nombre de secrets déjà chiffrés (base et fichiers de config/). Requête par l'entrée standard :
# elle contient des guillemets.
secrets_chiffres() {
    local n f
    n=$(printf '%s\n' "SELECT (SELECT COUNT(*) FROM userinfo WHERE voipmsapipassword LIKE 'enc:v1:%' OR calendarpassword LIKE 'enc:v1:%') + (SELECT COUNT(*) FROM sbchat WHERE sbchatapitoken LIKE 'enc:v1:%' OR masclientid LIKE 'enc:v1:%' OR massecret LIKE 'enc:v1:%');" \
        | docker exec -i sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -N $MYSQL_DATABASE' 2>/dev/null | tail -n 1)
    f=$(docker exec sb_php-fpm sh -c 'grep -l enc:v1: /var/www/html/SwitchBoard/SwitchBoard/config/* 2>/dev/null | wc -l' 2>/dev/null || true)
    case "$n" in ''|*[!0-9]*) n=0 ;; esac
    case "$f" in ''|*[!0-9]*) f=0 ;; esac
    echo $((n + f))
}
if docker exec sb_php-fpm test -f /var/www/html/SwitchBoard/SwitchBoard/config/config.php 2>/dev/null; then
    if [ "$CLE_GENEREE" = "1" ] && [ "$(secrets_chiffres)" -gt 0 ]; then
        # Chiffrés avec une ancienne clé, retirée de .env : la nouvelle ne peut pas les lire. On ne
        # chiffre rien de plus avec elle, pour que remettre l'ancienne clé suffise.
        echo ""
        echo "ATTENTION : des secrets enregistrés ont été chiffrés avec une ancienne clé, qui n'est plus dans"
        echo "$ENV_FILE. La nouvelle clé générée ne peut pas les lire. Remettez l'ancienne APP_ENCRYPTION_KEY"
        echo "(sauvegarde de $ENV_FILE) puis relancez ./up.sh, ou ressaisissez ces secrets dans SwitchBoard"
        echo "(voip.ms, SB Chat / MAS, courriel)."
    else
        # Secrets enregistrés en clair (avant que la clé existe) : chiffrés maintenant.
        SORTIE=$(docker exec -u www-data sb_php-fpm php /var/www/html/SwitchBoard/SwitchBoard/src/reencryptsecrets.php 2>&1) && RC=0 || RC=$?
        N=$(printf '%s\n' "$SORTIE" | sed -n 's/^Terminé : \([0-9][0-9]*\) .*/\1/p')
        if [ "$RC" = "0" ] && [ "${N:-0}" -gt 0 ]; then
            echo "Secrets enregistrés en clair : $N ligne(s) maintenant chiffrée(s) avec APP_ENCRYPTION_KEY."
        elif printf '%s' "$SORTIE" | grep -q 'aucune clé de chiffrement'; then
            echo "Avertissement : SwitchBoard ne voit pas APP_ENCRYPTION_KEY ; les secrets restent en clair."
            echo "Relancez ./up.sh ; si le message revient, envoyez le rapport de ./diagnostic.sh."
        fi
    fi
fi

echo ""
HOTE_ACTIF=$(valeur_variable VIRTUAL_HOST)
if [ "$AUTONOME" = "1" ]; then
    echo "Ouvrez : https://$HOTE_ACTIF"
    if [ "$HOTE_ACTIF" = "localhost" ]; then
        for IP in $(adresses_lan); do
            echo "     ou, depuis un autre appareil du réseau local : https://$IP"
        done
        echo "(Certificat auto-signé : le navigateur affichera un avertissement à accepter une fois.)"
    fi
else
    echo "Mode reverse-proxy externe : faites pointer votre reverse-proxy vers http://sb_web_server:80 (réseau Docker « web »)."
fi
