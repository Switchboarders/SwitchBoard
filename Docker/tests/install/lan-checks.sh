#!/bin/bash
# Vérifications depuis de VRAIS appareils du réseau local (appelé par run-linux.sh --lan-hook,
# voir run-lan.sh). Affiche une ligne PASS/FAIL par vérification.
# Usage : lan-checks.sh <ip-du-serveur> <port-http> <port-https>   (ADMIN et ADMIN_MDP dans l'environnement)
# Appareils : SBTEST_LAN_LINUX (défaut arch-desktop) et SBTEST_LAN_WINDOWS (défaut vieuxpcwindows,
# joint par SSH depuis l'appareil Linux). Une variable vide saute cet appareil.
set -u
IP="$1"; HTTP="$2"; HTTPS="$3"
LINUX="${SBTEST_LAN_LINUX-arch-desktop}"
WINDOWS="${SBTEST_LAN_WINDOWS-vieuxpcwindows}"
URL="https://$IP:$HTTPS/SwitchBoard/SwitchBoard/src/managementportal.php"
filtre() { grep -v -e 'post-quantum' -e 'decrypt later' -e 'may need to be upgraded' -e 'openssh.com/pq'; }
sur_linux() { ssh -o BatchMode=yes -o ConnectTimeout=10 "$LINUX" "$1" 2>&1 | filtre; }
sur_windows() { ssh -o BatchMode=yes -o ConnectTimeout=10 "$LINUX" "ssh -o BatchMode=yes -o ConnectTimeout=10 $WINDOWS $(printf '%q' "$1")" 2>&1 | filtre; }
resultat() { if [ "$2" = 0 ]; then echo "PASS $1"; else echo "FAIL $1"; fi; }

if [ -n "$LINUX" ]; then
    code=$(sur_linux "curl -s --max-time 15 -o /dev/null -w '%{http_code}' http://$IP:$HTTP/")
    [[ "$code" == 30[18] ]]; resultat "réseau local ($LINUX) : http://$IP:$HTTP redirige vers https" $?
    sur_linux "curl -sk --max-time 30 '$URL'" | grep -q 'createormodifyusername\|name="username"'
    resultat "réseau local ($LINUX) : https://$IP:$HTTPS ouvre SwitchBoard" $?
    sur_linux "rm -f /tmp/sbtest-jar; curl -sk --max-time 30 -c /tmp/sbtest-jar -b /tmp/sbtest-jar '$URL' >/dev/null; \
        curl -sk --max-time 30 -c /tmp/sbtest-jar -b /tmp/sbtest-jar --data-urlencode 'username=$ADMIN' \
        --data-urlencode 'password=$ADMIN_MDP' '$URL'; rm -f /tmp/sbtest-jar" | grep -q 'page=logout'
    resultat "réseau local ($LINUX) : connexion admin" $?
fi
if [ -n "$LINUX" ] && [ -n "$WINDOWS" ]; then
    sur_windows "curl.exe -sk --max-time 30 '$URL'" | grep -q 'createormodifyusername\|name="username"'
    resultat "réseau local ($WINDOWS, Windows) : https://$IP:$HTTPS ouvre SwitchBoard" $?
    sur_windows "\$j=Join-Path \$env:TEMP 'sbtest-jar'; curl.exe -sk --max-time 30 -c \$j -b \$j '$URL' | Out-Null; \
        curl.exe -sk --max-time 30 -c \$j -b \$j --data-urlencode 'username=$ADMIN' --data-urlencode 'password=$ADMIN_MDP' '$URL'; \
        Remove-Item -Force \$j" | grep -q 'page=logout'
    resultat "réseau local ($WINDOWS, Windows) : connexion admin" $?
fi
