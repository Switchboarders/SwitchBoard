#!/bin/bash
# Branche MAS (Matrix Authentication Service) sur le relais SMTP de SwitchBoard (conteneur
# sb_mail_relay) : MAS envoie alors ses courriels (récupération de mot de passe...) avec le
# compte courriel déjà configuré dans SwitchBoard, sans nouveau mot de passe ni réglage OAuth.
# À lancer une seule fois, sur le serveur où tourne MAS, après ./up.sh de SwitchBoard :
#   ./utils/connect-mas-mail.sh /chemin/vers/dossier-compose-de-MAS [chemin/config.yaml] [service]
# par défaut : mas/config.yaml et le service « mas ». Répétable sans risque.
# Ce que le script fait : sauvegarde config.yaml, remplace son bloc « email: » (transport smtp
# vers sb_mail_relay:2525, adresse d'expédition = celle de SwitchBoard), ajoute un fichier
# docker-compose.override.yml qui joint MAS au réseau « web », puis recrée le conteneur MAS.
#
# Connects MAS to SwitchBoard's SMTP relay (sb_mail_relay) so MAS mail uses the mail account
# SwitchBoard already has. Run once on the MAS server after SwitchBoard's ./up.sh. Safe to repeat.
set -e
DIR="${1:?Usage: $0 <MAS compose directory> [config.yaml path] [service]}"
CONFIG="${2:-mas/config.yaml}"
SERVICE="${3:-mas}"
cd "$DIR"
[ -f "$CONFIG" ] || { echo "Introuvable : $DIR/$CONFIG"; exit 1; }

DEPUIS=$(docker exec sb_mail_relay awk '$1=="from"{print $2}' /etc/msmtprc 2>/dev/null || true)
if [ -z "$DEPUIS" ]; then
    echo "Le relais sb_mail_relay n'est pas prêt ou le courriel n'est pas configuré dans SwitchBoard"
    echo "(SB_SMTP_* dans .env, ou console admin → Courriel OAuth puis docker compose restart php mail-relay)."
    exit 1
fi

cp -p "$CONFIG" "$CONFIG.before-mailrelay-$(date +%Y%m%d-%H%M%S)"
python3 -I - "$CONFIG" "$DEPUIS" <<'PY'
import re, sys
chemin, depuis = sys.argv[1], sys.argv[2]
s = open(chemin).read()
bloc = ("email:\n"
        f"  from: '\"Authentication Service\" <{depuis}>'\n"
        f"  reply_to: '\"Authentication Service\" <{depuis}>'\n"
        "  transport: smtp\n"
        "  mode: plain\n"
        "  hostname: sb_mail_relay\n"
        "  port: 2525\n")
# Le bloc « email: » s'étend jusqu'à la prochaine clé de premier niveau (ou la fin du fichier).
nouveau, n = re.subn(r"(?m)^email:\n(?:[ \t]+.*\n|[ \t]*\n|[ \t]*#.*\n)*", bloc + "\n", s, count=1)
if n == 0:
    nouveau = s.rstrip("\n") + "\n\n" + bloc
open(chemin, "w").write(nouveau)
PY

OVERRIDE=docker-compose.override.yml
if [ ! -f "$OVERRIDE" ]; then
    cat > "$OVERRIDE" <<YML
# Ajouté par SwitchBoard (utils/connect-mas-mail.sh) : joint MAS au réseau "web" pour joindre
# sb_mail_relay. Le réseau "web" doit aussi être déclaré (external) dans docker-compose.yml.
services:
  $SERVICE:
    networks:
      - web
networks:
  web:
    name: web
    external: true
YML
elif ! grep -q "sb_mail_relay\|ajouté par switchboard\|Ajouté par SwitchBoard" "$OVERRIDE"; then
    echo "$OVERRIDE existe déjà : ajoutez-y le réseau « web » au service $SERVICE, puis relancez ce script."
    exit 1
fi

docker compose up -d --force-recreate "$SERVICE"
echo "MAS recréé. Il enverra maintenant ses courriels via sb_mail_relay (expéditeur : $DEPUIS)."
