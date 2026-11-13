#!/bin/bash
# Analyse du code sans l'exécuter : trouve une classe, une fonction ou une méthode qui n'existe pas, et un
# mauvais nombre d'arguments. C'est le genre d'erreur qui ne plante qu'en production, sur une page rarement ouverte.
sortie=$(php -d memory_limit=1G /opt/phpstan/vendor/bin/phpstan analyse --no-progress --error-format=raw -c /banc/phpstan.neon 2>&1)
code=$?
if [ "$code" -eq 0 ]; then
	echo "OK: PHPStan ne trouve aucune nouvelle erreur dans src/"
	exit 0
fi
echo "ECHEC: PHPStan a trouvé une erreur dans src/ : il faut la corriger, ou l'ignorer dans phpstan.neon en disant pourquoi."
echo "Chaque ligne donne le fichier, la ligne et le problème :"
echo "$sortie" | sed 's#/var/www/html/SwitchBoard/SwitchBoard/##'
exit 1
