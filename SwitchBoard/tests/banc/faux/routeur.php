<?php
// Point d'arrivée de tous les faux services : note l'appel au journal, simule une panne si un scénario
// en a demandé une, puis passe la main au service de cet hôte. Un hôte non simulé répond 501, pour
// qu'un appel imprévu se voie au lieu de passer pour un succès.
const ETAT = '/var/banc-etat';

header('X-Banc-Faux: 1');
$hote = strtolower(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]);
$chemin = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$corps = file_get_contents('php://input');

file_put_contents(ETAT.'/journal.jsonl', json_encode([
	'hote' => $hote,
	'methode' => $_SERVER['REQUEST_METHOD'],
	'chemin' => $chemin,
	'params' => $_GET,
	'corps' => $corps,
], JSON_UNESCAPED_UNICODE)."\n", FILE_APPEND | LOCK_EX);

// panne-<hôte> : un code HTTP par ligne, un par requête. Les écritures passent par un renommage,
// parce que le scénario qui a créé le fichier ne tourne pas sous le même compte que ce service.
$fichierPanne = ETAT."/panne-$hote";
if (is_readable($fichierPanne)) {
	$codes = file($fichierPanne, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	$code = (int)array_shift($codes);
	file_put_contents("$fichierPanne.tmp", implode("\n", $codes));
	rename("$fichierPanne.tmp", $fichierPanne);
	if ($code !== 0 && $code !== 200) {
		http_response_code($code);
		echo json_encode(['status' => 'panne simulee']);
		return;
	}
}

$services = [
	'voip.ms' => 'voipms.php',
	'agendas.banc.test' => 'agendas.php',
	'sbchat.banc.test' => 'sbchat.php',
	'mas.banc.test' => 'mas.php',
	'hc-ping.com' => 'signaldevie.php',
	'www.bankofcanada.ca' => 'banquecanada.php',
	'docs.google.com' => 'sheets.php',
	'ipecho.net' => 'ipecho.php',
	'api.github.com' => 'github.php',
	'login.microsoftonline.com' => 'microsoft.php',
	'oauth2.googleapis.com' => 'google.php',
	'codeload.github.com' => 'github.php',
];
if (isset($services[$hote])) {
	require __DIR__.'/'.$services[$hote];
	return;
}
http_response_code(501);
header('Content-Type: application/json');
echo json_encode(['faux' => 'non simule', 'hote' => $hote, 'chemin' => $chemin]);
