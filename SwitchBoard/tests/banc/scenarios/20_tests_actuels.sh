#!/bin/bash
# Les tests de tests/ tournent aussi dans le banc : même PHP et mêmes dépendances que le serveur principal.
exec su -s /bin/bash www-data -c 'cd /var/www/html/SwitchBoard/SwitchBoard/tests && ./run_tests.sh'
