<?php
// Petit serveur "php -S" servant à simuler un service de dead-man's-switch (healthchecks.io ou
// compatible) pour les tests de SignalDeVie, sans dépendre d'un vrai service externe
// ni de secrets réels. Journalise chaque requête reçue (méthode, chemin, corps) dans
// stub-requests.log, à côté de ce fichier.
//
// Usage : php -S 127.0.0.1:8999 stubheartbeatserver.php
file_put_contents(
	__DIR__.'/stub-requests.log',
	date('H:i:s')." {$_SERVER['REQUEST_METHOD']} {$_SERVER['REQUEST_URI']} ".file_get_contents('php://input')."\n",
	FILE_APPEND
);
http_response_code(200);
echo "OK";
