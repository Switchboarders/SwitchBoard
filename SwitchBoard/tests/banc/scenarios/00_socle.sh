#!/bin/bash
# Le banc lui-même : isolement du réseau, faux services, BD, portail, et un vrai cron.
# Ce scénario tourne en premier, sur une BD vide.
ECHECS=0
verifie() {
	if [ "$1" = "$2" ]; then
		echo "OK: $3"
	else
		echo "ECHEC: $3 (attendu : $2, obtenu : $1)"
		ECHECS=$((ECHECS + 1))
	fi
}
code_http() {
	curl -s -o /dev/null --max-time 10 -w '%{http_code}' "$@"
}
requete_sql() {
	MYSQL_PWD=banc php -r '$c = new mysqli("db", "banc", getenv("MYSQL_PWD"), "switchboard"); echo implode(",", $c->query($argv[1])->fetch_row());' -- "$1"
}

APPLI=/var/www/html/SwitchBoard/SwitchBoard
PORTAIL=http://127.0.0.1/SwitchBoard/SwitchBoard/src

# --- Aucune sortie vers Internet ---
curl -s -o /dev/null --max-time 5 http://1.1.1.1/; verifie "$([ $? -ne 0 ] && echo bloque)" bloque "aucune sortie par adresse IP"
curl -s -o /dev/null --max-time 5 https://example.com/; verifie "$([ $? -ne 0 ] && echo bloque)" bloque "aucune sortie par nom d'hôte"

# --- Chaque service externe aboutit au faux service, avec un certificat valide ---
while read -r hote; do
	entete=$(curl -s -o /dev/null --max-time 10 -D - "https://$hote/" | tr -d '\r' | grep -i '^x-banc-faux:' | tr 'A-Z' 'a-z')
	verifie "$entete" "x-banc-faux: 1" "$hote aboutit au faux service par curl, certificat vérifié"
done < /etc/banc/hotes-faux.txt
reponse=$(php -r '$c = curl_init("https://voip.ms/api/v1/rest.php"); curl_setopt($c, CURLOPT_RETURNTRANSFER, true); $r = curl_exec($c); echo $r === false ? curl_error($c) : json_decode($r)->status;')
verifie "$reponse" "missing_credentials" "le curl de PHP reconnaît l'autorité de test"
verifie "$(curl -s --max-time 10 http://ipecho.net/plain)" "203.0.113.10" "un faux service répond aussi en HTTP"
verifie "$(diff <(sort /etc/banc/hotes-faux.txt) <(awk '{print $2}' /etc/hosts | grep -Fxf /etc/banc/hotes-faux.txt | sort) && echo pareil)" pareil "compose.yml et hotes-faux.txt ont la même liste"

# --- L'image ---
verifie "$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')" "8.4" "PHP de l'image du serveur principal"
verifie "$(crontab -l 2>/dev/null | wc -l)" "0" "aucun cron ne part tout seul"
verifie "$(php -r "require '$APPLI/vendor/autoload.php'; echo class_exists('ICal\ICal') ? 'oui' : 'non';")" "oui" "dépendances extraites du zip"
verifie "$(stat -c %U $APPLI/config/config.php)" "www-data" "config.php généré par le vrai entrypoint, à www-data"

# --- Portail et installation neuve ---
verifie "$(requete_sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='switchboard'")" "0" "la BD part vide"
# En production, le proxy devant nginx termine le TLS et annonce le HTTPS par cet en-tête
COMME_LE_PROXY="X-Forwarded-Proto: https"
page=$(curl -s --max-time 30 -H "$COMME_LE_PROXY" "$PORTAIL/managementportal.php")
verifie "$(code_http -H "$COMME_LE_PROXY" "$PORTAIL/managementportal.php")" "200" "le portail répond derrière le proxy"
verifie "$(code_http "$PORTAIL/managementportal.php")" "302" "le portail redirige le HTTP vers le HTTPS"
verifie "$(grep -c 'Nouvelle Authentification' <<<"$page")" "1" "sur une BD neuve, le portail demande de créer le compte admin"
verifie "$(grep -ciE 'fatal error|uncaught' <<<"$page")" "0" "le portail n'affiche aucune erreur fatale"
verifie "$(requete_sql "SELECT COUNT(*) > 0 FROM parameters WHERE paramname='db version'")" "1" "le portail a créé les tables"
verifie "$(code_http http://127.0.0.1/SwitchBoard/SwitchBoard/config/config.php)" "403" "config/ est bloqué depuis le web"
verifie "$(code_http http://127.0.0.1/SwitchBoard/SwitchBoard/calendarcache/x.ical)" "403" "calendarcache/ est bloqué depuis le web"
verifie "$(code_http http://127.0.0.1/SwitchBoard/SwitchBoard/logs/x.txt)" "403" "logs/ est bloqué depuis le web"

# --- Un vrai cron, avec sa vraie ligne ---
ligne=$(grep -m1 'refreshallringgroups.php' /etc/banc/cronjobs | sed -E 's/^([^ ]+ +){5}//')
: > /var/log/cron.log
sh -c "$ligne"
verifie "$?" "0" "la ligne de cron du groupe de sonnerie sort sans erreur"
verifie "$(grep -c 'Fin du traitement' /var/log/cron.log)" "1" "le cron va jusqu'au bout"

[ "$ECHECS" -eq 0 ]
