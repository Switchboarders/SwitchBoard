#!/bin/bash
# Tests d'installation Docker de SwitchBoard sur plusieurs distributions Linux.
#
# Chaque distribution tourne dans son propre conteneur « machine » (Docker dans Docker), avec
# le Docker de la distribution installé, sur un réseau qui joue le rôle du réseau local. On y
# copie le dossier Docker/ tel qu'un `git clone` le donnerait, puis on lance ./up.sh sans
# question et on vérifie, depuis un autre « appareil » du réseau, que SwitchBoard s'installe,
# répond, accepte un premier compte admin et survit aux redémarrages. Rien ne touche aux
# conteneurs SwitchBoard qui tourneraient déjà sur la machine de test.
#
# Usage : tests/install/run-linux.sh [--distros "debian ubuntu fedora rocky alpine arch pi"] [--keep]
#   pi = Raspberry Pi OS 64 bits (images arm64 sous émulation QEMU ; ~1 h, pas dans la liste par défaut)
#                                    [--publish 18080:18443 --lan-hook commande]   (voir run-lan.sh)
#   --keep : laisse les conteneurs de test en place pour inspection (docker exec -it sbtest-debian bash)
# Prérequis : Docker sur la machine de test, conteneurs privilégiés permis, ~6 Go libres par
# distribution (une à la fois) et un accès Internet (images, dépôt GitHub de SwitchBoard).
set -u
ICI=$(cd "$(dirname "$0")" && pwd)
DOCKER_DIR=$(cd "$ICI/../.." && pwd)
. "$ICI/lib.sh"

DISTROS="debian ubuntu fedora rocky alpine arch"
GARDER=0
PUBLIER=""
CROCHET_LAN=""
while [ $# -gt 0 ]; do
    case "$1" in
        --distros) DISTROS="$2"; shift ;;
        --distros=*) DISTROS="${1#*=}" ;;
        --keep) GARDER=1 ;;
        # Publie les ports 80/443 de la machine sur des ports de l'hôte de test (ex. 18080:18443),
        # pour que de vrais appareils du réseau local puissent l'ouvrir (voir run-lan.sh).
        --publish) PUBLIER="$2"; shift ;;
        # Commande lancée pendant que SwitchBoard tourne en mode autonome ; ses lignes
        # « PASS ... » / « FAIL ... » sont comptées. Reçoit ADMIN et ADMIN_MDP dans l'environnement.
        --lan-hook) CROCHET_LAN="$2"; shift ;;
        *) echo "Option inconnue : $1"; exit 2 ;;
    esac
    shift
done

RESEAU=sbtest-lan
MIROIR=sbtest-mirror
CLIENT=sbtest-client
RESULTATS="$ICI/results"
mkdir -p "$RESULTATS"
ADMIN=admin
ADMIN_MDP="Test-$(date +%s)-mdp"

# --- Images des « machines » --------------------------------------------------------------------
# Docker est installé comme le ferait un administrateur sur cette distribution ; curl et openssl
# ne sont PAS ajoutés exprès quand la distribution ne les a pas déjà (up.sh doit s'en passer).
dockerfile_distro() {
    case "$1" in
        # pi : Raspberry Pi OS 64 bits (Debian 12 arm64). La machine reste x86, mais toutes les
        # images de SwitchBoard y sont construites et lancées en arm64 (émulation QEMU, lent).
        debian|pi) cat <<'EOF'
FROM debian:12
RUN apt-get update && apt-get install -y --no-install-recommends ca-certificates curl iproute2 openssl \
 && curl -fsSL https://get.docker.com | sh && rm -rf /var/lib/apt/lists/*
EOF
        ;;
        ubuntu) cat <<'EOF'
FROM ubuntu:24.04
RUN apt-get update && apt-get install -y --no-install-recommends docker.io docker-compose-v2 \
 && rm -rf /var/lib/apt/lists/*
EOF
        ;;
        fedora) cat <<'EOF'
FROM fedora:latest
RUN dnf -y install dnf-plugins-core findutils procps-ng iproute \
 && dnf config-manager addrepo --from-repofile=https://download.docker.com/linux/fedora/docker-ce.repo \
 && dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin iptables-nft && dnf clean all \
 && (alternatives --set iptables /usr/sbin/iptables-nft; alternatives --set ip6tables /usr/sbin/ip6tables-nft; true)
# (iptables « nft » : le noyau de l'hôte de test n'a pas forcément les modules iptables historiques)
EOF
        ;;
        rocky) cat <<'EOF'
FROM rockylinux/rockylinux:9
RUN dnf -y install dnf-plugins-core findutils procps-ng \
 && dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo \
 && dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin && dnf clean all
EOF
        ;;
        alpine) cat <<'EOF'
FROM alpine:3.20
RUN apk add --no-cache bash docker docker-cli-compose
EOF
        ;;
        arch) cat <<'EOF'
FROM archlinux:latest
RUN pacman -Syu --noconfirm docker docker-compose && pacman -Scc --noconfirm
EOF
        ;;
        *) return 1 ;;
    esac
    # Démarrage de dockerd dans la « machine » (cgroup v2 imbriqué, miroir d'images local).
    cat <<'EOF'
RUN printf '%s\n' '#!/bin/sh' \
 'if [ -f /sys/fs/cgroup/cgroup.controllers ]; then' \
 '  mkdir -p /sys/fs/cgroup/init' \
 '  xargs -rn1 < /sys/fs/cgroup/cgroup.procs > /sys/fs/cgroup/init/cgroup.procs 2>/dev/null || :' \
 '  sed -e "s/ / +/g" -e "s/^/+/" < /sys/fs/cgroup/cgroup.controllers > /sys/fs/cgroup/cgroup.subtree_control 2>/dev/null || :' \
 'fi' \
 'dockerd --registry-mirror "$SBTEST_MIRROR" > /var/log/dockerd.log 2>&1 &' \
 'exec sleep infinity' > /sbtest-start && chmod +x /sbtest-start
CMD ["/sbtest-start"]
EOF
}

# --- Infrastructure commune ----------------------------------------------------------------------
preparer_infra() {
    docker network inspect "$RESEAU" >/dev/null 2>&1 || docker network create "$RESEAU" >/dev/null
    # Miroir de Docker Hub partagé par toutes les machines : chaque image n'est téléchargée
    # qu'une fois (et reste en cache d'une exécution à l'autre, volume sbtest-mirror-data).
    if ! docker ps --format '{{.Names}}' | grep -qx "$MIROIR"; then
        docker rm -f "$MIROIR" >/dev/null 2>&1
        docker run -d --name "$MIROIR" --network "$RESEAU" --restart unless-stopped \
            -e REGISTRY_PROXY_REMOTEURL=https://registry-1.docker.io \
            -v sbtest-mirror-data:/var/lib/registry registry:2 >/dev/null
    fi
    if ! docker ps --format '{{.Names}}' | grep -qx "$CLIENT"; then
        docker rm -f "$CLIENT" >/dev/null 2>&1
        printf 'FROM alpine:3.20\nRUN apk add --no-cache curl openssl\n' | docker build -q -t sbtest-client - >/dev/null
        docker run -d --name "$CLIENT" --network "$RESEAU" sbtest-client sleep infinity >/dev/null
    fi
    docker cp "$ICI/client.sh" "$CLIENT:/client.sh" >/dev/null
}

# Le dossier Docker/ tel qu'un clone neuf le donnerait (fichiers suivis et nouveaux, sans les
# fichiers ignorés comme .env, nginx.conf, db_data ou web).
archive_docker() {
    (cd "$DOCKER_DIR/.." && git ls-files -co --exclude-standard -- Docker | grep -v '^Docker/tests/install/results/' \
        | tar -cf - -T -)
}

# Tant que le dépôt de SwitchBoard est privé, l'installation a besoin d'un jeton : il vient de
# SB_GITHUB_TOKEN ou, à défaut, de la session GitHub CLI de la personne qui lance les tests. Il ne
# va que dans les machines de test. Les scénarios sans jeton et avec un jeton refusé attendent un
# refus si le dépôt est privé, et une installation sans jeton s'il est public : on le demande à GitHub.
export SB_GITHUB_TOKEN="${SB_GITHUB_TOKEN:-$(gh auth token 2>/dev/null)}"
DEPOT_PUBLIC=0
[ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 'https://github.com/Switchboarders/SwitchBoard.git/info/refs?service=git-upload-pack')" = "200" ] && DEPOT_PUBLIC=1
g() { docker exec "$MACHINE" "$@"; }
gsh() { docker exec -e SB_GITHUB_TOKEN ${PLATEFORME:+-e DOCKER_DEFAULT_PLATFORM="$PLATEFORME"} "$MACHINE" bash -c "$*"; }
client() { docker exec "$CLIENT" sh /client.sh "$@"; }
env_val() { gsh "grep '^$2=' '$1/.env' | tail -n 1 | cut -d= -f2-"; }

# Nouvelle copie du dossier Docker/ dans la machine ; affiche son chemin.
nouvelle_copie() {
    local dest="/opt/$1"
    gsh "rm -rf '$dest' && mkdir -p '$dest'"
    archive_docker | docker exec -i "$MACHINE" tar -xf - -C "$dest"
    echo "$dest/Docker"
}
arreter_pile() { gsh "cd '$1' && docker compose down -v --remove-orphans" >/dev/null 2>&1; }

conteneurs_ok() {
    local attendus="$1" nom etat
    for nom in $attendus; do
        etat=$(g docker inspect -f '{{.State.Status}} {{.RestartCount}}' "$nom" 2>/dev/null) || return 1
        [ "${etat%% *}" = "running" ] || return 1
    done
}

# --- Scénarios -----------------------------------------------------------------------------------
scenario_autonome() {
    titre "$CONTEXTE : mode autonome (accès réseau local)"
    local dir; dir=$(nouvelle_copie autonome)
    if gsh "cd '$dir' && ./up.sh --non-interactive" > "$LOGS/up-autonome.log" 2>&1; then
        pass "up.sh --non-interactive termine sans erreur et attend que SwitchBoard soit prêt"
    else
        fail "up.sh --non-interactive (voir $LOGS/up-autonome.log)"
        tail -n 30 "$LOGS/up-autonome.log"
        return
    fi
    verifier "aucune question posée" bash -c "! grep -q 'Choix \[' '$LOGS/up-autonome.log'"
    verifier "les 5 conteneurs tournent" conteneurs_ok "sb_letsencrypt sb_nginx-proxy sb_web sb_php-fpm sb_mariadb"
    verifier "réseau docker « web » créé automatiquement" g docker network inspect web

    local root mdp cle mode sans
    root=$(env_val "$dir" MYSQL_ROOT_PASSWORD); mdp=$(env_val "$dir" MYSQL_PASSWORD)
    cle=$(env_val "$dir" APP_ENCRYPTION_KEY); mode=$(env_val "$dir" COMPOSE_FILE); sans=$(env_val "$dir" SB_CERT_SANS)
    verifier ".env : mots de passe de la base aléatoires (pas ceux de .env_sample)" \
        bash -c "[ ${#root} -ge 24 ] && [ ${#mdp} -ge 24 ] && [ '$root' != complexrootpassword ] && [ '$root' != '$mdp' ]"
    verifier ".env : clé APP_ENCRYPTION_KEY de 32 octets" bash -c "[ \$(printf '%s' '$cle' | base64 -d | wc -c) = 32 ]"
    verifier ".env : mode autonome mémorisé" test "$mode" = docker-compose.standalone.yml
    verifier ".env : le certificat couvre l'adresse IP de la machine ($IP)" bash -c "echo '$sans' | tr ',' '\n' | grep -qx '$IP'"

    verifier "http:// redirige vers https://" client redirige_https "$IP"
    verifier "https://localhost répond (page de création du compte admin)" client accueil "$IP" localhost
    verifier "https://$IP répond depuis un autre appareil du réseau" client accueil "$IP"
    verifier "https://<nom de la machine> répond aussi" client accueil "$IP" "$NOM_MACHINE"
    verifier "https://<nom>.local répond (mDNS, ex. Raspberry Pi)" client accueil "$IP" "$NOM_MACHINE.local"
    verifier "certificat valide pour <nom>.local" bash -c "docker exec $CLIENT sh /client.sh san $IP | grep -q 'DNS:$NOM_MACHINE.local'"
    verifier "certificat auto-signé valide pour l'adresse IP" bash -c "docker exec $CLIENT sh /client.sh san $IP | grep -q 'IP Address:$IP'"
    verifier "création du premier compte admin par le navigateur" client creer_admin "$IP" "$ADMIN" "$ADMIN_MDP"
    verifier "connexion avec ce compte" client connexion "$IP" "$ADMIN" "$ADMIN_MDP"
    verifier "mauvais mot de passe refusé" client connexion_refusee "$IP" "$ADMIN"
    if [ -n "$CROCHET_LAN" ]; then
        local ligne
        while IFS= read -r ligne; do
            case "$ligne" in
                PASS\ *) pass "${ligne#PASS }" ;;
                FAIL\ *) fail "${ligne#FAIL }" ;;
                *) printf '    %s\n' "$ligne" ;;
            esac
        done < <(ADMIN="$ADMIN" ADMIN_MDP="$ADMIN_MDP" bash -c "$CROCHET_LAN" 2>&1)
    fi
    verifier "tâches cron actives dans le conteneur php" g docker exec sb_php-fpm sh -c 'cat /proc/[0-9]*/comm 2>/dev/null | grep -qx cron'
    verifier "php-fpm ne tourne pas en root" bash -c "[ \"\$(docker exec $MACHINE docker exec sb_php-fpm sh -c 'stat -c %U /proc/1')\" = www-data ]"

    # Relancer up.sh ne doit rien casser ni changer les secrets.
    if gsh "cd '$dir' && ./up.sh --non-interactive" > "$LOGS/up-autonome-2.log" 2>&1; then
        pass "relancer up.sh fonctionne"
    else
        fail "relancer up.sh (voir $LOGS/up-autonome-2.log)"
    fi
    verifier "relancer up.sh garde les mêmes mots de passe" test "$(env_val "$dir" MYSQL_ROOT_PASSWORD)" = "$root"
    verifier "une seule ligne COMPOSE_FILE dans .env" bash -c "[ \$(docker exec $MACHINE grep -c '^COMPOSE_FILE=' '$dir/.env') = 1 ]"
    # Installation déjà faite, jeton devenu invalide (expiré) : avertissement, mais pas de blocage
    # (dépôt privé : « déjà installé » ; dépôt public : le jeton est ignoré).
    local bon; bon=$(env_val "$dir" GITHUB_TOKEN)
    gsh "sed -i 's|^GITHUB_TOKEN=.*|GITHUB_TOKEN=ghp_sbtestEXPIRE|' '$dir/.env'"
    docker exec -e SB_GITHUB_TOKEN= "$MACHINE" bash -c "cd '$dir' && ./up.sh --non-interactive" > "$LOGS/up-jeton-expire.log" 2>&1
    verifier "jeton devenu invalide : up.sh continue avec un avertissement" \
        bash -c "grep -qE 'déjà installé|il est ignoré' '$LOGS/up-jeton-expire.log' && grep -q 'SwitchBoard est prêt' '$LOGS/up-jeton-expire.log'"
    gsh "sed -i 's|^GITHUB_TOKEN=.*|GITHUB_TOKEN=$bon|' '$dir/.env'"

    if [ "$d" = "debian" ]; then
        # Deuxième copie de SwitchBoard dans un autre dossier, pendant que celle-ci tourne.
        local autre; autre=$(nouvelle_copie deuxieme)
        panne deuxieme "cd '$autre' && ./up.sh -y"
        verifier "deuxième installation dans un autre dossier : refusée, la première n'est pas touchée" \
            bash -c "[ $CODE != 0 ] && grep -q 'autre installation de SwitchBoard' '$LOGS/panne-deuxieme.log' && [ \"\$(docker exec $MACHINE docker inspect -f '{{ index .Config.Labels \"com.docker.compose.project.working_dir\" }}' sb_php-fpm)\" = '$dir' ]"
        gsh "rm -rf /opt/deuxieme"
        # Mot de passe de la base changé dans .env après l'installation.
        local mdp; mdp=$(env_val "$dir" MYSQL_PASSWORD)
        gsh "sed -i 's|^MYSQL_PASSWORD=.*|MYSQL_PASSWORD=change-apres-coup|' '$dir/.env'"
        local debut=$SECONDS
        panne mdp-change "cd '$dir' && timeout 900 ./up.sh -y"
        verifier "mot de passe de la base changé dans .env : up.sh l'explique ($((SECONDS - debut)) s)" \
            bash -c "[ $CODE != 0 ] && grep -q 'refuse les identifiants' '$LOGS/panne-mdp-change.log'"
        gsh "sed -i 's|^MYSQL_PASSWORD=.*|MYSQL_PASSWORD=$mdp|' '$dir/.env'"
        if gsh "cd '$dir' && ./up.sh -y" > "$LOGS/up-mdp-remis.log" 2>&1; then
            pass "ancien mot de passe remis : SwitchBoard redémarre"
        else
            fail "ancien mot de passe remis (voir $LOGS/up-mdp-remis.log)"
        fi
        # Secret enregistré en clair (avant que la clé existe) : chiffré par up.sh. Clé ensuite retirée
        # de .env : avertissement, sans rien rechiffrer ; ancienne clé remise : tout redevient normal.
        sql() { printf '%s\n' "$1" | docker exec -i "$MACHINE" docker exec -i sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -N $MYSQL_DATABASE' 2>&1; }
        local select_secret="SELECT sbchatapitoken FROM sbchat WHERE iduser=999;"
        sql "INSERT INTO sbchat (iduser, sbchatapitoken) VALUES (999, 'jeton-en-clair-test');" >/dev/null
        gsh "cd '$dir' && ./up.sh -y" > "$LOGS/up-secret-clair.log" 2>&1
        local chiffre; chiffre=$(sql "$select_secret")
        verifier "secret enregistré en clair : chiffré par up.sh" \
            bash -c "grep -q '1 ligne(s) maintenant chiffrée' '$LOGS/up-secret-clair.log' && [ '${chiffre:0:7}' = 'enc:v1:' ]"
        local cle0; cle0=$(env_val "$dir" APP_ENCRYPTION_KEY)
        gsh "sed -i 's|^APP_ENCRYPTION_KEY=.*|APP_ENCRYPTION_KEY=|' '$dir/.env'"
        gsh "cd '$dir' && ./up.sh -y" > "$LOGS/up-cle-retiree.log" 2>&1
        local ok=0
        if grep -q 'ancienne clé' "$LOGS/up-cle-retiree.log" && [ "$(env_val "$dir" APP_ENCRYPTION_KEY)" != "$cle0" ] \
                && [ "$(sql "$select_secret")" = "$chiffre" ]; then ok=1; fi
        verifier "clé retirée de .env alors que des secrets sont chiffrés : avertissement, rien de rechiffré" test "$ok" = 1
        gsh "sed -i 's|^APP_ENCRYPTION_KEY=.*|APP_ENCRYPTION_KEY=$cle0|' '$dir/.env'"
        gsh "cd '$dir' && ./up.sh -y" > "$LOGS/up-cle-remise.log" 2>&1
        verifier "ancienne clé remise : plus d'avertissement" bash -c "! grep -q 'ancienne clé' '$LOGS/up-cle-remise.log' && grep -q 'SwitchBoard est prêt' '$LOGS/up-cle-remise.log'"
        sql "DELETE FROM sbchat WHERE iduser=999;" >/dev/null
    fi

    # Diagnostic : rapport complet, sans aucun secret de .env ni le jeton GitHub.
    gsh "cd '$dir' && ./diagnostic.sh" > "$LOGS/diagnostic.log" 2>&1
    g sh -c "cat '$dir'/switchboard-diagnostic-*.txt" > "$LOGS/diagnostic-rapport.txt" 2>&1
    verifier "diagnostic.sh : rapport avec les sections principales" bash -c "grep -q '===== Conteneur php : connexion de PHP' '$LOGS/diagnostic-rapport.txt' && grep -q 'OK, MariaDB' '$LOGS/diagnostic-rapport.txt' && grep -q '===== Journaux : docker logs sb_php-fpm' '$LOGS/diagnostic-rapport.txt'"
    local fuite=0 v
    for v in "$(env_val "$dir" MYSQL_PASSWORD)" "$(env_val "$dir" MYSQL_ROOT_PASSWORD)" "$(env_val "$dir" APP_ENCRYPTION_KEY)" "$SB_GITHUB_TOKEN"; do
        [ -n "$v" ] && grep -qF -- "$v" "$LOGS/diagnostic-rapport.txt" && fuite=1
    done
    verifier "diagnostic.sh : aucun mot de passe, clé ni jeton dans le rapport" test "$fuite" = 0
    gsh "rm -f '$dir'/switchboard-diagnostic-*.txt"

    gsh "cd '$dir' && docker compose restart" > "$LOGS/restart.log" 2>&1
    verifier "après docker compose restart : SwitchBoard répond" attendre 300 client accueil "$IP"
    verifier "après docker compose restart : le compte admin existe toujours" attendre 120 client connexion "$IP" "$ADMIN" "$ADMIN_MDP"

    # Mise à jour : le conteneur php seul est recréé (nouvelle adresse IP) ; nginx doit suivre.
    gsh "cd '$dir' && docker compose up -d --force-recreate --no-deps php" > "$LOGS/recreate-php.log" 2>&1
    verifier "après recréation du conteneur php seul : SwitchBoard répond" attendre 120 client accueil "$IP"

    gsh "cd '$dir' && docker compose down" > "$LOGS/down.log" 2>&1
    if gsh "cd '$dir' && ./up.sh --non-interactive" > "$LOGS/up-autonome-3.log" 2>&1; then
        pass "docker compose down puis up.sh : redémarre"
    else
        fail "docker compose down puis up.sh (voir $LOGS/up-autonome-3.log)"
    fi
    verifier "après down/up : les données sont conservées (connexion admin)" attendre 120 client connexion "$IP" "$ADMIN" "$ADMIN_MDP"

    # Les ports 80/443 occupés par un autre serveur : message clair, rien de démarré.
    arreter_pile "$dir"
    g docker run -d --name sbtest-occupe -p 80:80 nginx:alpine >/dev/null 2>&1
    local dir2; dir2=$(nouvelle_copie occupe)
    gsh "cd '$dir2' && ./up.sh --non-interactive --no-wait" > "$LOGS/up-port-occupe.log" 2>&1
    local code=$?
    verifier "port 80 déjà utilisé : up.sh s'arrête avec une explication" \
        bash -c "[ $code != 0 ] && grep -q 'port 80 est déjà utilisé' '$LOGS/up-port-occupe.log'"
    g docker rm -f sbtest-occupe >/dev/null 2>&1
    gsh "rm -rf /opt/occupe"
    arreter_pile "$dir"
}

# L'accès au dépôt est vérifié auprès de GitHub avant toute construction, en quelques secondes.
# Dépôt privé : sans jeton ou avec un jeton refusé, up.sh s'arrête sans rien démarrer (au lieu
# d'attendre 15 minutes). Dépôt public : aucun jeton n'est nécessaire, et un jeton refusé est
# ignoré ; on s'arrête après la préparation (--prepare-only), l'installation complète étant
# essayée par le mode autonome.
scenario_sans_jeton() {
    titre "$CONTEXTE : sans jeton GitHub"
    local dir; dir=$(nouvelle_copie sansjeton)
    local debut=$SECONDS
    docker exec -e SB_GITHUB_TOKEN= "$MACHINE" bash -c "cd '$dir' && ./up.sh --non-interactive --prepare-only" \
        > "$LOGS/up-sans-jeton.log" 2>&1
    local code=$? duree=$((SECONDS - debut))
    if [ "$DEPOT_PUBLIC" = "1" ]; then
        verifier "sans jeton, dépôt public : up.sh l'accepte" \
            bash -c "[ $code = 0 ] && grep -q 'aucun jeton GitHub n.est nécessaire' '$LOGS/up-sans-jeton.log'"
    else
        verifier "sans jeton, dépôt privé : up.sh refuse et explique qu'il en faut un" \
            bash -c "[ $code != 0 ] && grep -q 'un jeton GitHub est nécessaire' '$LOGS/up-sans-jeton.log' && grep -q 'GITHUB_TOKEN' '$LOGS/up-sans-jeton.log'"
    fi
    verifier "sans jeton : réponse immédiate (${duree} s, moins de 60 s)" test "$duree" -lt 60
    verifier_echec "sans jeton : rien n'est démarré" g docker inspect sb_php-fpm
    arreter_pile "$dir"
    gsh "rm -rf /opt/sansjeton"
}

scenario_jeton_refuse() {
    titre "$CONTEXTE : jeton GitHub refusé"
    local dir; dir=$(nouvelle_copie jetonrefuse)
    local faux="ghp_sbtestREFUSE$(od -An -N8 -tx1 /dev/urandom | tr -d ' \n')"
    local debut=$SECONDS
    docker exec -e SB_GITHUB_TOKEN="$faux" "$MACHINE" bash -c "cd '$dir' && ./up.sh --non-interactive --prepare-only" \
        > "$LOGS/up-jeton-refuse.log" 2>&1
    local code=$? duree=$((SECONDS - debut))
    if [ "$DEPOT_PUBLIC" = "1" ]; then
        verifier "jeton refusé, dépôt public : up.sh le dit et continue sans lui" \
            bash -c "[ $code = 0 ] && grep -q 'GitHub refuse ce jeton' '$LOGS/up-jeton-refuse.log' && grep -q 'il est ignoré' '$LOGS/up-jeton-refuse.log'"
    else
        verifier "jeton refusé, dépôt privé : up.sh refuse et explique pourquoi" \
            bash -c "[ $code != 0 ] && grep -q 'GitHub refuse ce jeton' '$LOGS/up-jeton-refuse.log'"
    fi
    verifier "jeton refusé : réponse immédiate (${duree} s, moins de 60 s)" test "$duree" -lt 60
    verifier_echec "jeton refusé : rien n'est démarré" g docker inspect sb_php-fpm
    verifier_echec "jeton refusé : le jeton n'est ni affiché ni enregistré" \
        bash -c "grep -qF '$faux' '$LOGS/up-jeton-refuse.log' || docker exec '$MACHINE' grep -qF '$faux' '$dir/.env'"
    arreter_pile "$dir"
    gsh "rm -rf /opt/jetonrefuse"
}

# panne <nom> <commande> : lance la commande dans la machine (jeton valide dans SB_GITHUB_TOKEN),
# journal dans $LOGS/panne-<nom>.log, code de sortie dans $CODE.
panne() {
    docker exec -e SB_GITHUB_TOKEN "$MACHINE" bash -c "$2" > "$LOGS/panne-$1.log" 2>&1; CODE=$?
}
# dit <nom> <motif> : le journal de la panne contient le motif.
dit() { grep -qiE "$2" "$LOGS/panne-$1.log"; }
arreter_dockerd() {
    # Attend la fin du processus (pas seulement de l'API) : sinon le prochain dockerd refuse de démarrer.
    g timeout 120 sh -c 'p=$(cat /var/run/docker.pid 2>/dev/null) || exit 0; kill $p; while kill -0 $p 2>/dev/null; do sleep 1; done' >/dev/null 2>&1
}
demarrer_dockerd() {
    g sh -c 'dockerd --registry-mirror "$SBTEST_MIRROR" >> /var/log/dockerd.log 2>&1 &' >/dev/null 2>&1
    attendre 90 g docker info
}

# Erreurs d'utilisation et pannes du système : chaque cas doit s'arrêter avec un message clair,
# sans laisser l'installation dans un état cassé (pas de .env à moitié écrit, rien de démarré).
scenario_pannes() {
    titre "$CONTEXTE : erreurs d'utilisation et pannes"
    local dir
    dir=$(nouvelle_copie pannes)

    panne sh "cd '$dir' && sh ./up.sh --help"
    verifier "« sh up.sh » : relancé avec bash" bash -c "[ $CODE = 0 ] && grep -q 'prepare-only' '$LOGS/panne-sh.log'"

    # Questions interrompues (fin de saisie, comme une fenêtre fermée ou Ctrl+D) : aucun .env.
    panne interrompu "cd '$dir' && timeout 60 script -qec ./up.sh /dev/null < /dev/null"
    verifier "questions interrompues : aucun .env à moitié écrit" \
        bash -c "! docker exec $MACHINE test -e '$dir/.env' && ! docker exec $MACHINE test -e '$dir/.env.creation'"

    panne hote-ip "cd '$dir' && ./up.sh -y --prepare-only --host=192.168.1.20"
    verifier "--host=adresse IP : refusé, avec le conseil d'utiliser localhost" bash -c "[ $CODE != 0 ] && grep -q 'localhost' '$LOGS/panne-hote-ip.log'"
    panne hote-invalide "cd '$dir' && ./up.sh -y --prepare-only --host=sb_exemple.org"
    verifier "--host invalide : refusé" bash -c "[ $CODE != 0 ] && grep -q 'pas un nom de domaine valide' '$LOGS/panne-hote-invalide.log'"
    verifier_echec "--host refusé : aucun .env créé" g test -e "$dir/.env"

    # .env supprimé alors qu'une base existe : on ne doit PAS en créer un nouveau.
    gsh "mkdir -p '$dir/db_data' && touch '$dir/db_data/ibdata1'"
    panne env-perdu "cd '$dir' && ./up.sh -y --prepare-only"
    verifier ".env perdu mais base existante : refusé avec explication" bash -c "[ $CODE != 0 ] && grep -q 'existe déjà' '$LOGS/panne-env-perdu.log'"
    verifier_echec ".env perdu : aucun nouveau .env créé" g test -e "$dir/.env"
    gsh "rm -rf '$dir/db_data'"

    # .env enregistré par le Bloc-notes de Windows (BOM, CRLF), jeton entre guillemets, nom de
    # domaine copié depuis le navigateur.
    gsh "cd '$dir' && { printf '\\357\\273\\277'; sed -e 's|^GITHUB_TOKEN=.*|GITHUB_TOKEN=\"'\"\$SB_GITHUB_TOKEN\"'\"|' -e 's|^VIRTUAL_HOST=.*|VIRTUAL_HOST=HTTPS://Sb.Exemple.org/|' .env_sample | sed 's/\$/\r/'; } > .env"
    panne env-windows "cd '$dir' && SB_GITHUB_TOKEN= ./up.sh -y --prepare-only"
    verifier ".env au format Windows + jeton entre guillemets : accepté" bash -c "[ $CODE = 0 ] && grep -q 'Jeton GitHub valide' '$LOGS/panne-env-windows.log'"
    verifier ".env : BOM et CRLF retirés" bash -c "[ \"\$(docker exec $MACHINE head -c 3 '$dir/.env' | od -An -tx1 | tr -d ' \\n')\" != efbbbf ] && ! docker exec $MACHINE grep -q \$'\\r' '$dir/.env'"
    verifier "VIRTUAL_HOST « HTTPS://Sb.Exemple.org/ » corrigé en sb.exemple.org" test "$(env_val "$dir" VIRTUAL_HOST)" = "sb.exemple.org"
    gsh "rm -f '$dir/.env'"

    # Clé de chiffrement vide (.env écrit à la main) : générée ; mal formée : refusée sans rien changer.
    gsh "cd '$dir' && sed 's|^APP_ENCRYPTION_KEY=.*|APP_ENCRYPTION_KEY=|' .env_sample > .env"
    panne cle-vide "cd '$dir' && ./up.sh -y --prepare-only"
    local cle; cle=$(env_val "$dir" APP_ENCRYPTION_KEY)
    verifier "APP_ENCRYPTION_KEY vide : une clé est générée" \
        bash -c "[ $CODE = 0 ] && grep -q 'clé aléatoire a été générée' '$LOGS/panne-cle-vide.log' && [ \$(printf '%s' '$cle' | base64 -d | wc -c) = 32 ]"
    gsh "cd '$dir' && sed 's|^APP_ENCRYPTION_KEY=.*|APP_ENCRYPTION_KEY=ma-cle-maison|' .env_sample > .env"
    panne cle-invalide "cd '$dir' && ./up.sh -y --prepare-only"
    verifier "APP_ENCRYPTION_KEY mal formée : refusée, .env inchangé" \
        bash -c "[ $CODE != 0 ] && grep -q 'pas une clé valide' '$LOGS/panne-cle-invalide.log' && [ '$(env_val "$dir" APP_ENCRYPTION_KEY)' = ma-cle-maison ]"
    gsh "rm -f '$dir/.env'"

    # Utilisateur hors du groupe docker, puis .env créé avec sudo (appartient à root).
    g sh -c 'id sbtestuser >/dev/null 2>&1 || useradd -m sbtestuser' >/dev/null 2>&1
    panne groupe "su sbtestuser -s /bin/bash -c 'cd \"$dir\" && ./up.sh -y --prepare-only'"
    verifier "utilisateur hors du groupe docker : explique usermod -aG docker" bash -c "[ $CODE != 0 ] && grep -q 'usermod -aG docker' '$LOGS/panne-groupe.log'"
    g sh -c "usermod -aG docker sbtestuser && cp '$dir/.env_sample' '$dir/.env'" >/dev/null 2>&1
    panne env-root "su sbtestuser -s /bin/bash -c 'cd \"$dir\" && ./up.sh -y --prepare-only'"
    verifier ".env non modifiable (créé avec sudo) : explique chown" bash -c "[ $CODE != 0 ] && grep -q 'chown' '$LOGS/panne-env-root.log'"
    gsh "rm -f '$dir/.env'"

    # Pas d'accès à GitHub (réseau coupé), puis connexion sécurisée impossible (date fausse, proxy).
    g iptables -I OUTPUT -p tcp --dport 443 -j REJECT >/dev/null 2>&1
    panne reseau "cd '$dir' && ./up.sh -y --prepare-only"
    g iptables -D OUTPUT -p tcp --dport 443 -j REJECT >/dev/null 2>&1
    verifier "GitHub injoignable : arrêt avec explication (connexion Internet)" bash -c "[ $CODE != 0 ] && grep -q 'Impossible de joindre GitHub' '$LOGS/panne-reseau.log'"
    # github.com détourné vers un serveur au faux certificat (proxy qui intercepte, ou date fausse :
    # pour curl, c'est la même erreur de certificat).
    gsh "rm -f '$dir/.env' && openssl req -x509 -newkey rsa:2048 -nodes -subj /CN=faux -keyout /tmp/faux.key -out /tmp/faux.pem -days 1 2>/dev/null \
        && cp /etc/hosts /tmp/hosts.orig && echo '127.0.0.1 github.com' >> /etc/hosts"
    docker exec -d "$MACHINE" sh -c 'openssl s_server -quiet -accept 443 -cert /tmp/faux.pem -key /tmp/faux.key -www'
    sleep 2
    panne tls "cd '$dir' && ./up.sh -y --prepare-only"
    gsh "cat /tmp/hosts.orig > /etc/hosts; kill \$(pidof openssl) 2>/dev/null; true"
    verifier "connexion sécurisée refusée : explique la date/l'heure ou un proxy" bash -c "[ $CODE != 0 ] && grep -q \"date et l'heure\" '$LOGS/panne-tls.log'"
    gsh "rm -f '$dir/.env'"

    # Service Docker arrêté.
    arreter_dockerd
    panne docker-arrete "cd '$dir' && ./up.sh -y --prepare-only"
    demarrer_dockerd
    verifier "service Docker arrêté : explique comment le démarrer" bash -c "[ $CODE != 0 ] && grep -q 'systemctl enable --now docker' '$LOGS/panne-docker-arrete.log'"
    verifier "Docker redémarré pour la suite des tests" g docker info
    gsh "rm -rf '/opt/pannes'"
}

# Docker s'arrête en pleine construction des images : message clair ; relancer up.sh termine
# l'installation.
scenario_docker_meurt() {
    titre "$CONTEXTE : Docker s'arrête pendant l'installation"
    local dir; dir=$(nouvelle_copie meurt)
    g sh -c 'docker image rm -f switchboarddocker-php switchboarddocker-nginx-proxy; docker builder prune -af' >/dev/null 2>&1
    docker exec -d -e SB_GITHUB_TOKEN "$MACHINE" bash -c "cd '$dir' && ./up.sh -y > /tmp/up-meurt.log 2>&1; echo \"code=\$?\" >> /tmp/up-meurt.log"
    attendre 300 g grep -qiE 'building|load build definition|\[internal\]' /tmp/up-meurt.log
    sleep 5
    arreter_dockerd
    attendre 120 g grep -q '^code=' /tmp/up-meurt.log
    g cat /tmp/up-meurt.log > "$LOGS/panne-docker-meurt.log" 2>&1
    demarrer_dockerd
    verifier "Docker arrêté pendant la construction : up.sh le dit" bash -c "grep -q \"Docker s'est arrêté\" '$LOGS/panne-docker-meurt.log' && ! grep -q '^code=0' '$LOGS/panne-docker-meurt.log'"
    if gsh "cd '$dir' && ./up.sh -y" > "$LOGS/up-apres-docker-meurt.log" 2>&1; then
        pass "après redémarrage de Docker, relancer up.sh termine l'installation"
    else
        fail "relancer up.sh après l'arrêt de Docker (voir $LOGS/up-apres-docker-meurt.log)"
    fi
    arreter_pile "$dir"
    gsh "rm -rf '/opt/meurt'"
}

scenario_reverse_proxy() {
    titre "$CONTEXTE : mode reverse-proxy externe"
    # Dossier avec espaces et accents (« Mes documents », prénoms...).
    local dir; dir=$(nouvelle_copie "Mon dossier é")
    if gsh "cd '$dir' && ./up.sh --non-interactive --mode=reverse-proxy" > "$LOGS/up-proxy.log" 2>&1; then
        pass "up.sh --mode=reverse-proxy termine sans erreur"
    else
        fail "up.sh --mode=reverse-proxy (voir $LOGS/up-proxy.log)"
        tail -n 30 "$LOGS/up-proxy.log"
        return
    fi
    verifier "les 3 conteneurs tournent" conteneurs_ok "sb_web_server sb_php-fpm sb_mariadb"
    verifier_echec "pas de reverse-proxy intégré" g docker inspect sb_nginx-proxy
    verifier "les ports 80/443 restent libres pour le reverse-proxy externe" client port_ferme "$IP" 80
    verifier "sb_web_server joignable sur le réseau « web » (comme par un reverse-proxy)" \
        g docker run --rm --network web curlimages/curl:latest -sf -H 'X-Forwarded-Proto: https' \
        -o /dev/null http://sb_web_server/SwitchBoard/SwitchBoard/src/managementportal.php
    arreter_pile "$dir"
}

# Le complément Windows (volumes Docker au lieu des dossiers) se vérifie aussi sous Linux.
scenario_volumes_windows() {
    titre "$CONTEXTE : complément docker-compose.windows.yml (volumes)"
    local dir; dir=$(nouvelle_copie windows)
    if gsh "cd '$dir' && COMPOSE_FILE=docker-compose.standalone.yml:docker-compose.windows.yml ./up.sh --non-interactive" \
        > "$LOGS/up-windows-volumes.log" 2>&1; then
        pass "up.sh avec docker-compose.windows.yml"
    else
        fail "up.sh avec docker-compose.windows.yml (voir $LOGS/up-windows-volumes.log)"
        return
    fi
    verifier "SwitchBoard répond" client accueil "$IP"
    verifier "base de données dans un volume Docker" bash -c "docker exec $MACHINE docker volume ls --format '{{.Name}}' | grep -q '_sb_db$'"
    verifier "dossier db_data non utilisé" bash -c "[ -z \"\$(docker exec $MACHINE sh -c 'ls -A $dir/db_data 2>/dev/null')\" ]"
    gsh "cd '$dir' && COMPOSE_FILE=docker-compose.standalone.yml:docker-compose.windows.yml docker compose down -v" >/dev/null 2>&1
}

# --- Une distribution ----------------------------------------------------------------------------
tester_distro() {
    local d="$1"
    CONTEXTE="$d"
    MACHINE="sbtest-$d"
    NOM_MACHINE="sbsrv-$d"
    LOGS="$RESULTATS/$d"
    rm -rf "$LOGS"; mkdir -p "$LOGS"
    titre "$d : préparation de la machine"
    local df; df=$(dockerfile_distro "$d") || { fail "distribution inconnue : $d"; return; }
    PLATEFORME=""
    if [ "$d" = "pi" ]; then
        PLATEFORME=linux/arm64
        # L'émulation arm64 doit être enregistrée dans le noyau de l'hôte (binfmt_misc).
        if [ ! -e /proc/sys/fs/binfmt_misc/qemu-aarch64 ]; then
            docker run --privileged --rm tonistiigi/binfmt --install arm64 > "$LOGS/binfmt.log" 2>&1
            BINFMT_AJOUTE=1
        fi
        verifier "émulation arm64 disponible (binfmt_misc)" test -e /proc/sys/fs/binfmt_misc/qemu-aarch64
    fi
    if ! printf '%s\n' "$df" | docker build -t "sbtest-machine-$d" - > "$LOGS/build-machine.log" 2>&1; then
        fail "image de la machine $d (voir $LOGS/build-machine.log)"; return
    fi
    docker rm -f "$MACHINE" >/dev/null 2>&1
    local ports=()
    [ -n "$PUBLIER" ] && ports=(-p "${PUBLIER%%:*}:80" -p "${PUBLIER##*:}:443")
    docker run -d --privileged --name "$MACHINE" --hostname "$NOM_MACHINE" --network "$RESEAU" "${ports[@]}" \
        -e SBTEST_MIRROR="http://$MIROIR:5000" -v /var/lib/docker sbtest-machine-"$d" >/dev/null
    if ! attendre 90 g docker info; then
        fail "dockerd démarre dans la machine $d"; docker logs "$MACHINE" > "$LOGS/machine.log" 2>&1
        g cat /var/log/dockerd.log > "$LOGS/dockerd.log" 2>&1; return
    fi
    IP=$(docker inspect -f "{{(index .NetworkSettings.Networks \"$RESEAU\").IPAddress}}" "$MACHINE")
    pass "machine $d prête ($(g docker version --format '{{.Server.Version}}'), compose $(g docker compose version --short), IP $IP)"

    # Sans Docker accessible : message clair (PATH sans docker).
    local dir; dir=$(nouvelle_copie sansdocker)
    gsh "cd '$dir' && PATH=/nonexistent /bin/bash ./up.sh --non-interactive" > "$LOGS/up-sans-docker.log" 2>&1
    verifier "sans Docker : up.sh explique comment l'installer" grep -q "get.docker.com" "$LOGS/up-sans-docker.log"
    gsh "rm -rf /opt/sansdocker"

    if [ "$d" = "pi" ]; then
        # Émulation lente : le scénario complet du mode autonome seulement, et l'architecture.
        scenario_autonome
        verifier "Raspberry Pi : image php construite en arm64" \
            bash -c "[ \"\$(docker exec $MACHINE docker image inspect -f '{{.Architecture}}' switchboarddocker-php)\" = arm64 ]"
        verifier "Raspberry Pi : MariaDB arm64 démarre (uname -m = aarch64)" \
            bash -c "[ \"\$(docker exec -e DOCKER_DEFAULT_PLATFORM=linux/arm64 $MACHINE docker run --rm --entrypoint uname mariadb:12 -m)\" = aarch64 ]"
    else
        scenario_sans_jeton
        [ "$d" = "debian" ] && scenario_jeton_refuse
        [ "$d" = "debian" ] && scenario_pannes
        scenario_autonome
        scenario_reverse_proxy
        [ "$d" = "debian" ] && scenario_volumes_windows
        [ "$d" = "debian" ] && scenario_docker_meurt
    fi

    g docker ps -a > "$LOGS/docker-ps.txt" 2>&1
    if [ "$GARDER" != 1 ]; then docker rm -f -v "$MACHINE" >/dev/null 2>&1; fi
}

# Tout le programme est dans une fonction : bash lit alors le fichier en entier avant de commencer,
# et modifier ce script pendant qu'il tourne (une heure ou plus) ne peut plus casser l'exécution.
principal() {
    preparer_infra
    for D in $DISTROS; do
        tester_distro "$D"
    done
    CONTEXTE=""
    if [ "$GARDER" != 1 ]; then docker rm -f "$CLIENT" >/dev/null 2>&1; fi
    # Remet l'hôte comme avant si on y a ajouté l'émulation arm64.
    if [ "${BINFMT_AJOUTE:-0}" = 1 ]; then docker run --privileged --rm tonistiigi/binfmt --uninstall qemu-aarch64 >/dev/null 2>&1; fi
    bilan
}
principal; exit $?
