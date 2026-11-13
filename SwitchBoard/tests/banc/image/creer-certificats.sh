#!/bin/bash
# Crée l'autorité de test, un certificat qui couvre tous les faux hôtes, et leur liste pour nginx.
set -e
cd /etc/banc

noms=""
while read -r hote; do
	[ -n "$hote" ] && noms="${noms:+$noms,}DNS:$hote"
done < hotes-faux.txt
echo "server_name $(tr '\n' ' ' < hotes-faux.txt);" > noms-faux.conf

openssl req -x509 -newkey rsa:2048 -nodes -days 3650 -subj "/CN=Autorite de test du banc SwitchBoard" \
	-keyout autorite.key -out autorite.crt 2>/dev/null
openssl req -newkey rsa:2048 -nodes -subj "/CN=faux-services" \
	-keyout faux.key -out faux.csr 2>/dev/null
openssl x509 -req -days 3650 -in faux.csr -CA autorite.crt -CAkey autorite.key -CAcreateserial \
	-extfile <(echo "subjectAltName=$noms") -out faux.crt 2>/dev/null

cp autorite.crt /usr/local/share/ca-certificates/banc-switchboard.crt
