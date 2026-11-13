#!/bin/sh
# Exécuté dans le conteneur « client » des tests : un autre appareil du réseau local qui ouvre
# SwitchBoard dans un navigateur. Usage : client.sh <commande> <ip> [arguments]
set -u
CMD="$1"; IP="$2"; shift 2
CHEMIN="/SwitchBoard/SwitchBoard/src/managementportal.php"

# GET de la page d'accueil de SwitchBoard ; $1 = nom d'hôte demandé (par défaut l'adresse IP)
page() {
    NOM="${1:-$IP}"
    if [ "$NOM" = "$IP" ]; then
        curl -sk --max-time 30 "https://$IP$CHEMIN"
    else
        curl -sk --max-time 30 --resolve "$NOM:443:$IP" "https://$NOM$CHEMIN"
    fi
}

case "$CMD" in
    # http:// doit rediriger vers https://
    redirige_https)
        curl -s --max-time 15 -o /dev/null -w '%{http_code} %{redirect_url}' "http://$IP/" | grep -Eq '^30[18] https://'
        ;;
    # la page d'accueil répond 200 et montre la création du compte admin ou la connexion
    accueil)
        CODE=$(curl -sk --max-time 30 -o /tmp/page.html -w '%{http_code}' ${1:+--resolve "$1:443:$IP"} "https://${1:-$IP}$CHEMIN")
        [ "$CODE" = "200" ] && grep -Eq 'createormodifyusername|name="username"' /tmp/page.html \
            && ! grep -Eqi 'Fatal error|Connection failed|Parse error' /tmp/page.html
        ;;
    # noms et adresses du certificat présenté (une ligne)
    san)
        echo | openssl s_client -connect "$IP:443" -servername "${1:-localhost}" 2>/dev/null \
            | openssl x509 -noout -ext subjectAltName 2>/dev/null | tail -n 1
        ;;
    # crée le premier compte administrateur par le formulaire, comme le ferait une personne
    creer_admin)
        UTIL="$1"; MDP="$2"
        rm -f /tmp/jar
        curl -sk --max-time 30 -c /tmp/jar -b /tmp/jar "https://$IP$CHEMIN" > /tmp/form.html
        grep -q createormodifyusername /tmp/form.html || exit 1
        CSRF=$(grep -o "name='csrf_token' value='[^']*'" /tmp/form.html | head -n 1 | sed "s/.*value='//; s/'$//")
        curl -sk --max-time 30 -c /tmp/jar -b /tmp/jar \
            --data-urlencode "createormodifyusername=$UTIL" --data-urlencode "createormodifypassword=$MDP" \
            --data-urlencode "createormodifypasswordverification=$MDP" --data-urlencode "csrf_token=$CSRF" \
            "https://$IP$CHEMIN" > /tmp/apres.html
        grep -q 'page=logout' /tmp/apres.html
        ;;
    # connexion avec un compte existant, dans une nouvelle session
    connexion)
        UTIL="$1"; MDP="$2"
        rm -f /tmp/jar2
        curl -sk --max-time 30 -c /tmp/jar2 -b /tmp/jar2 "https://$IP$CHEMIN" > /dev/null
        curl -sk --max-time 30 -c /tmp/jar2 -b /tmp/jar2 --data-urlencode "username=$UTIL" \
            --data-urlencode "password=$MDP" "https://$IP$CHEMIN" > /tmp/connecte.html
        grep -q 'page=logout' /tmp/connecte.html && ! grep -q 'name="password"' /tmp/connecte.html
        ;;
    # un mauvais mot de passe doit être refusé
    connexion_refusee)
        UTIL="$1"
        rm -f /tmp/jar3
        curl -sk --max-time 30 -c /tmp/jar3 -b /tmp/jar3 --data-urlencode "username=$UTIL" \
            --data-urlencode "password=mauvais-mot-de-passe" "https://$IP$CHEMIN" > /tmp/refuse.html
        ! grep -q 'page=logout' /tmp/refuse.html
        ;;
    # le port est fermé (mode reverse-proxy : rien ne doit écouter sur 80/443)
    port_ferme)
        ! curl -s --max-time 5 -o /dev/null "http://$IP:$1/"
        ;;
    *) echo "commande inconnue : $CMD"; exit 2 ;;
esac
