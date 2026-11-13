<?php
// Petit serveur "php -S" qui simule l'API voip.ms pour les tests de reprise de VoipmsManager,
// sans réseau ni identifiants réels. Le chemin décrit la suite de codes HTTP à renvoyer, une
// requête après l'autre : /cas3/522,200 répond 522 à la 1re requête du cas 3, puis 200.
// Un code 200 renvoie {"status":"success"}. Le nombre de requêtes reçues par cas est gardé
// dans stub-voipms-<cas>.compteur, à côté de ce fichier.
//
// Usage : php -S 127.0.0.1:8998 stubvoipmsserver.php
$morceaux = explode('/', trim(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH), '/'));
$cas = preg_replace('/[^a-z0-9]/i', '', $morceaux[0] ?? 'inconnu');
$codes = explode(',', $morceaux[1] ?? '200');

$fichierCompteur = __DIR__."/stub-voipms-$cas.compteur";
$numeroRequete = (int) @file_get_contents($fichierCompteur);
file_put_contents($fichierCompteur, $numeroRequete + 1);

// Au-delà de la suite prévue, on répète le dernier code
$code = (int) ($codes[min($numeroRequete, count($codes) - 1)]);
http_response_code($code);
header('Content-Type: application/json');
echo $code === 200 ? '{"status":"success"}' : '{"status":"erreur simulée"}';
