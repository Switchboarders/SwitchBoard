#!/bin/bash
# Test sur le vrai réseau local : installe SwitchBoard (mode autonome) dans une machine de test sur
# CET ordinateur, publie ses ports 80/443 sur des ports libres de l'hôte, puis l'ouvre depuis
# d'autres ordinateurs du réseau (Linux et Windows, par SSH) : redirection https, page, connexion.
#
# Usage : tests/install/run-lan.sh [--distros debian] [--ports 18080:18443] [--ip 192.168.0.223]
#   Appareils clients : SBTEST_LAN_LINUX (défaut arch-desktop), SBTEST_LAN_WINDOWS (défaut
#   vieuxpcwindows, joint depuis l'appareil Linux). Le pare-feu de cet ordinateur doit laisser
#   entrer les ports choisis.
set -u
ICI=$(cd "$(dirname "$0")" && pwd)
DISTROS=debian
PORTS=18080:18443
IP=""
while [ $# -gt 0 ]; do
    case "$1" in
        --distros) DISTROS="$2"; shift ;;
        --ports) PORTS="$2"; shift ;;
        --ip) IP="$2"; shift ;;
        *) echo "Option inconnue : $1"; exit 2 ;;
    esac
    shift
done
if [ -z "$IP" ]; then
    # Adresse de cet ordinateur sur le réseau local (celle de la route par défaut).
    IP=$(ip -4 route get 1.1.1.1 2>/dev/null | awk '{for(i=1;i<NF;i++) if($i=="src"){print $(i+1); exit}}')
fi
[ -n "$IP" ] || { echo "Adresse IP locale introuvable : utilisez --ip"; exit 2; }
echo "Serveur de test : $IP (ports $PORTS)"
exec "$ICI/run-linux.sh" --distros "$DISTROS" --publish "$PORTS" \
    --lan-hook "$(printf '%q ' "$ICI/lan-checks.sh" "$IP" "${PORTS%%:*}" "${PORTS##*:}")"
