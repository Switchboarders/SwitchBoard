<?php
// Faux voip.ms : un compte par nom d'utilisateur, avec le comportement de tests/stubvoipmscompte.php
// pour les renvois et les groupes de sonnerie. Ce faux service ne reflète que ce qu'on sait de l'API :
// le mode --reel (tâche 2.5) le compare au vrai.
header('Content-Type: application/json');

if ($chemin !== '/api/v1/rest.php') {
	http_response_code(404);
	return;
}

$nomCompte = (string)($_GET['api_username'] ?? '');
if ($nomCompte === '' || ($_GET['api_password'] ?? '') === '') {
	echo json_encode(['status' => 'missing_credentials']);
	return;
}
// Le mot de passe de chaque compte inventé : prouve que le code a bien déchiffré celui de la BD
if ($_GET['api_password'] !== "banc-$nomCompte") {
	echo json_encode(['status' => 'invalid_credentials']);
	return;
}

$donnees = (require __DIR__.'/voipms-donnees.php')[$nomCompte] ?? ['solde' => '0.0000', 'dids' => [], 'cdr' => [], 'transactions' => []];

// Le code demande les appels d'un DID, ou les appels sortants de la ligne provinciale
function appelsDemandes(array $cdr, string $sorte): array {
	if ($sorte === '') {
		return $cdr;
	}
	return array_values(array_filter($cdr, fn($appel) => $sorte === 'outgoing'
		? str_contains($appel['description'], 'Outbound')
		: $appel['destination'] === $sorte));
}

$cdr = appelsDemandes($donnees['cdr'], (string)($_GET['calltype'] ?? ''));
$reponsesFixes = [
	'getLanguages' => ['status' => 'success', 'languages' => [['value' => 'fr', 'description' => 'French']]],
	'getIP' => ['status' => 'success', 'ip' => '203.0.113.10'],
	'sendSMS' => ['status' => 'success', 'sms' => 1],
	'getBalance' => ['status' => 'success', 'balance' => ['current_balance' => $donnees['solde']]],
	'getDIDsInfo' => $donnees['dids'] === [] ? ['status' => 'no_did'] : ['status' => 'success', 'dids' => $donnees['dids']],
	'getCDR' => $cdr === [] ? ['status' => 'no_cdr'] : ['status' => 'success', 'cdr' => $cdr],
	'getTransactionHistory' => $donnees['transactions'] === [] ? ['status' => 'no_transaction'] : ['status' => 'success', 'transactions' => $donnees['transactions']],
	'getVoicemailMessages' => ['status' => 'no_messages'],
];
$methode = (string)($_GET['method'] ?? '');
if (isset($reponsesFixes[$methode])) {
	echo json_encode($reponsesFixes[$methode]);
	return;
}

$fichierEtat = ETAT.'/voipms-'.preg_replace('/[^a-z0-9_-]/i', '', $nomCompte).'.json';
$verrou = fopen("$fichierEtat.verrou", 'c');
flock($verrou, LOCK_EX);
require '/var/www/html/SwitchBoard/SwitchBoard/tests/stubvoipmscompte.php';
