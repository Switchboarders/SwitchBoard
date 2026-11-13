#!/bin/bash
# Remplace le vrai msmtp : mail() de PHP et mailcanary.php l'appellent tous deux, donc tout courriel
# finit ici, dans un fichier, sans jamais quitter le conteneur. Un fichier panne-msmtp simule une panne qui
# dure ; panne-msmtp-fois donne le nombre de prochains envois qui échouent.
if [ -e /var/banc-etat/panne-msmtp ]; then
	echo "msmtp: panne simulee" >&2
	exit 1
fi
if [ -s /var/banc-etat/panne-msmtp-fois ]; then
	restants=$(cat /var/banc-etat/panne-msmtp-fois)
	if [ "$restants" -gt 0 ]; then
		# Par un renommage : le fichier a été créé par le scénario, sous un autre compte
		echo $((restants - 1)) > /var/banc-etat/panne-msmtp-fois.tmp
		mv -f /var/banc-etat/panne-msmtp-fois.tmp /var/banc-etat/panne-msmtp-fois
		echo "msmtp: panne simulee" >&2
		exit 1
	fi
fi
cat > "/var/banc-etat/courriel-$(date +%s%N)-$$.eml"
