#!/bin/bash
set -e
# Certificat auto-signé pour localhost (mode autonome sans nom de domaine). Il couvre aussi le
# nom de la machine et ses adresses IP sur le réseau local (SB_CERT_SANS, calculé par up.sh), pour
# que SwitchBoard soit joignable depuis un autre appareil : https://192.168.x.y
# Il est refait quand cette liste change (nouvelle adresse IP, par exemple).
NOMS="DNS:localhost,DNS:*.localhost,IP:127.0.0.1"
for NOM in $(echo "${SB_CERT_SANS:-}" | tr ',' ' '); do
  if echo "$NOM" | grep -Eq '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$'; then
    NOMS="$NOMS,IP:$NOM"
  elif echo "$NOM" | grep -Eq '^[A-Za-z0-9.-]+$'; then
    NOMS="$NOMS,DNS:$NOM"
  fi
done
TEMOIN=/etc/nginx/certs/localhost.sans
if [ ! -f /etc/nginx/certs/localhost.key ] || [ "$(cat "$TEMOIN" 2>/dev/null)" != "$NOMS" ]; then
  openssl req -x509 -newkey rsa:2048 -days 3650 \
  -noenc -keyout /etc/nginx/certs/localhost.key -out /etc/nginx/certs/localhost.crt -subj "/CN=localhost" \
  -addext "subjectAltName=$NOMS"
  echo "$NOMS" > "$TEMOIN"
fi

exec "$@"
