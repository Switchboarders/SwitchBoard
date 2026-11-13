#!/bin/bash
# Diagnostic complet d'une installation Docker de SwitchBoard (Linux, Raspberry Pi, macOS).
#   ./diagnostic.sh        (ou : bash diagnostic.sh ; sudo ./diagnostic.sh si Docker le demande)
#
# Produit un rapport texte (switchboard-diagnostic-AAAAMMJJ-HHMM.txt dans ce dossier) à coller tel
# quel dans une conversation d'aide (Claude, courriel...). Les mots de passe, jetons et clés sont
# masqués : le rapport ne contient aucun secret.
#
# Pour une erreur précise (ex. : erreur 500 en créant le compte admin), reproduisez-la d'abord dans
# le navigateur, puis lancez le diagnostic tout de suite après : les journaux contiendront l'erreur.
# Ne modifie rien (lecture seule), sauf le fichier du rapport.
if [ -z "${BASH_VERSION:-}" ]; then exec bash "$0" "$@"; fi
case "$0" in */*) cd "${0%/*}" ;; esac
ENV_FILE=".env"
RAPPORT="switchboard-diagnostic-$(date +%Y%m%d-%H%M).txt"
CLES_SECRETES='PASSWORD|TOKEN|SECRET|KEY|CLIENT_ID|_URL$'
section() { printf '\n===== %s =====\n' "$*"; }
commande() { echo "--- $*"; "$@" 2>&1; }

# Valeurs secrètes de .env, remplacées partout dans le rapport (avec les motifs de jetons).
SECRETS=()
if [ -f "$ENV_FILE" ]; then
    while IFS= read -r ligne; do
        ligne=${ligne%$'\r'}
        cle=${ligne%%=*}; valeur=${ligne#*=}
        [ "$cle" = "$ligne" ] && continue
        valeur=${valeur#\"}; valeur=${valeur%\"}; valeur=${valeur#\'}; valeur=${valeur%\'}
        if printf '%s' "$cle" | grep -Eq "$CLES_SECRETES" && [ ${#valeur} -ge 4 ]; then SECRETS+=("$valeur"); fi
    done < "$ENV_FILE"
fi
masquer() {
    local texte; texte=$(cat)
    local s
    for s in "${SECRETS[@]}"; do texte=${texte//"$s"/***}; done
    printf '%s\n' "$texte" | sed -E \
        -e 's/(ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{10,}/***jeton***/g' \
        -e 's/([Pp][Aa][Ss][Ss][Ww][Oo][Rr][Dd]|[Pp][Ww][Dd]|[Ss][Ee][Cc][Rr][Ee][Tt]|[Tt][Oo][Kk][Ee][Nn])([[:space:]]*[=:][[:space:]]*)[^[:space:](*][^[:space:]]*/\1\2***/g' \
        -e 's/x-access-token:[^@[:space:]]+@/x-access-token:***@/g' \
        -e 's/[A-Za-z0-9._%+-]+@([A-Za-z0-9-]+\.[A-Za-z])/***@\1/g'
}

echo "Diagnostic de SwitchBoard en cours (1 à 2 minutes)..."
{
section "Système"
echo "Rapport : $(date '+%Y-%m-%d %H:%M:%S %z')  (diagnostic.sh)"
[ -f /etc/os-release ] && . /etc/os-release && echo "Système : ${PRETTY_NAME:-?}"
echo "Noyau : $(uname -srm) ; machine : $(uname -n)"
[ -f /proc/device-tree/model ] && echo "Carte : $(tr -d '\0' < /proc/device-tree/model)"
command -v free >/dev/null && free -h | sed -n 1,2p
df -h . 2>/dev/null | tail -n 1 | awk '{print "Disque (ce dossier) : " $4 " libres sur " $2}'
echo "Utilisateur : $(id -un) ($(id -Gn))"
command -v ip >/dev/null && ip -4 -o addr show scope global | awk '{print "Adresse IP : " $4 " (" $2 ")"}'

section "Dossier SwitchBoard"
echo "Dossier : $(pwd -P)"
if command -v git >/dev/null && [ -d ../.git ]; then
    echo "Git : $(git -C .. log -1 --format='%h %ad %s' --date=iso 2>&1)"
    echo "Branche : $(git -C .. rev-parse --abbrev-ref HEAD 2>&1)"
    echo "Fichiers modifiés localement :"; git -C .. status --short 2>&1 | head -n 30
else
    echo "Git : pas un clone git (ZIP ?)"
fi
for f in up.sh entrypoint.sh prepare_switchboard.sh nginx/nginx.conf; do
    if [ -f "$f" ]; then
        crlf=""; grep -q $'\r' "$f" && crlf=", ATTENTION fins de ligne CRLF"
        echo "$f : $(wc -c < "$f") octets, modifié $(date -r "$f" '+%Y-%m-%d %H:%M' 2>/dev/null)$crlf"
    else
        echo "$f : ABSENT"
    fi
done
[ -d db_data ] && echo "db_data/ : présent" || echo "db_data/ : absent"

section ".env (secrets masqués)"
if [ -f "$ENV_FILE" ]; then
    [ "$(head -c 3 "$ENV_FILE" | od -An -tx1 | tr -d ' \n')" = "efbbbf" ] && echo "ATTENTION : .env commence par une marque BOM"
    grep -q $'\r' "$ENV_FILE" && echo "ATTENTION : .env a des fins de ligne CRLF"
    grep -E '^[A-Za-z_][A-Za-z0-9_]*=' "$ENV_FILE" | tr -d '\r' | while IFS= read -r ligne; do
        cle=${ligne%%=*}; valeur=${ligne#*=}
        if printf '%s' "$cle" | grep -Eq "$CLES_SECRETES"; then
            if [ -n "$valeur" ]; then echo "$cle=(défini, ${#valeur} caractères)"; else echo "$cle=(vide)"; fi
        elif [ "$cle" = DEFAULT_EMAIL ] || [ "$cle" = SB_SMTP_USER ] || [ "$cle" = SB_SMTP_FROM ]; then
            echo "$cle=$(printf '%s' "${valeur:-(vide)}" | sed 's/^[^@]*@/***@/')"
        else
            echo "$cle=${valeur:-(vide)}"
        fi
    done
else
    echo ".env : ABSENT"
fi

section "Docker"
if ! command -v docker >/dev/null; then
    echo "Docker : introuvable"
else
    commande docker version
    commande docker compose version
    echo "--- docker info (résumé)"
    docker info --format 'OS={{.OSType}} CPU={{.NCPU}} RAM={{.MemTotal}} Root={{.DockerRootDir}} Server={{.ServerVersion}} Arch={{.Architecture}}' 2>&1
    if docker info >/dev/null 2>&1; then
        commande docker ps -a --filter 'name=^sb_' --format 'table {{.Names}}\t{{.Status}}\t{{.Image}}\t{{.Ports}}'
        for c in sb_nginx-proxy sb_letsencrypt sb_web sb_web_server sb_php-fpm sb_mariadb; do
            etat=$(docker inspect -f '{{.State.Status}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}} started={{.State.StartedAt}}' "$c" 2>/dev/null) \
                && echo "$c : $etat"
        done
        echo "--- stockage (volumes Docker ou dossiers)"
        for c in sb_mariadb sb_php-fpm; do
            docker inspect -f '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{println}}{{end}}' "$c" 2>/dev/null | sed "/^$/d; s/^/$c : /"
        done
        commande docker images --format '{{.Repository}}:{{.Tag}} {{.CreatedSince}} {{.Size}}'
        commande docker compose ps -a
        commande docker system df

        if [ "$(docker inspect -f '{{.State.Status}}' sb_php-fpm 2>/dev/null)" = "running" ] && [ -f diagnostic-conteneur.sh ]; then
            tr -d '\r' < diagnostic-conteneur.sh | docker exec -i sb_php-fpm sh -c 'cat > /tmp/sb-diagnostic.sh'
            docker exec sb_php-fpm bash /tmp/sb-diagnostic.sh 2>&1
            docker exec sb_php-fpm rm -f /tmp/sb-diagnostic.sh
        else
            section "Conteneur php"
            echo "sb_php-fpm ne tourne pas : vérifications internes impossibles (voir son journal plus bas)."
        fi
        section "Base de données (identifiants de .env, depuis sb_mariadb)"
        docker exec sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -e status $MYSQL_DATABASE 2>&1 | grep -E "Current database|Server version|Uptime|ERROR"' 2>&1

        section "Tests HTTP"
        PORTAIL=/SwitchBoard/SwitchBoard/src/managementportal.php
        if command -v curl >/dev/null; then
            for u in "https://localhost$PORTAIL" "http://localhost/"; do
                echo "$u -> $(curl -sk -o /dev/null -w 'HTTP %{http_code}, %{time_total} s, redirection : %{redirect_url}' --max-time 30 "$u" 2>&1)"
            done
        fi
        WEB=sb_web; [ "$(docker inspect -f '{{.State.Status}}' sb_web 2>/dev/null)" = running ] || WEB=sb_web_server
        echo "depuis $WEB (sans le reverse-proxy) : $(docker exec "$WEB" sh -c "wget -S -q -O /dev/null --header X-Forwarded-Proto:https http://127.0.0.1$PORTAIL 2>&1 | head -n 1")"

        section "Journaux : erreurs de MariaDB (tout le journal)"
        erreurs_db=$(docker logs sb_mariadb 2>&1 | grep -E '\[ERROR\]|lower_case_table_names' | tail -n 40)
        echo "${erreurs_db:-aucune}"

        for c in sb_php-fpm sb_web sb_web_server sb_nginx-proxy sb_mariadb sb_letsencrypt; do
            docker inspect "$c" >/dev/null 2>&1 || continue
            section "Journaux : docker logs $c (80 dernières lignes)"
            docker logs --tail 80 "$c" 2>&1
        done
    else
        echo "ATTENTION : le moteur Docker ne répond pas (service arrêté, ou droits : groupe docker / sudo)."
    fi
fi
} 2>&1 | masquer > "$RAPPORT"

echo ""
echo "Rapport enregistré : $(pwd -P)/$RAPPORT"
echo "Copiez tout son contenu (par exemple : cat $RAPPORT) et collez-le dans la conversation d'aide."
echo "Les mots de passe, jetons et clés y sont masqués."
