<?php
// Faux Matrix Authentication Service (MAS) : l'API d'administration que sbchat.php appelle pour créer un utilisateur,
// changer son mot de passe ou son courriel et lui donner les droits d'administrateur (formulaire « utilisateurs Matrix »).
// L'état (utilisateurs, courriels) est dans ETAT/mas.json, et chaque mot de passe reçu y est gardé pour que le banc vérifie
// ce que MAS a reçu. Les chemins de /.well-known et /_matrix ne sont pas simulés : le nom du serveur Matrix est alors l'hôte.
header('Content-Type: application/json');
$fichier = ETAT.'/mas.json';
$etat = is_readable($fichier) ? (json_decode((string)file_get_contents($fichier), true) ?: []) : [];
$etat += ['utilisateurs' => [], 'courriels' => []];
$enregistrer = function () use (&$etat, $fichier) {
	file_put_contents("$fichier.tmp", json_encode($etat));
	rename("$fichier.tmp", $fichier);
};
$donnees = json_decode($corps, true) ?? [];
$methode = $_SERVER['REQUEST_METHOD'];
$utilisateur = fn(string $id, array $u) => ['type' => 'user', 'id' => $id, 'attributes' => ['username' => $u['username'], 'admin' => $u['admin'] ?? false]];
$courriel = fn(string $id, array $c) => ['type' => 'user-email', 'id' => $id, 'attributes' => ['user_id' => $c['user_id'], 'email' => $c['email']]];

if ($chemin === '/oauth2/token' && $methode === 'POST') {
	echo json_encode(['access_token' => 'at-banc-mas', 'token_type' => 'Bearer', 'expires_in' => 300]);
	return;
}
if (preg_match('#^/api/admin/v1/users/by-username/(.+)$#', $chemin, $m) === 1) {
	$nom = rawurldecode($m[1]);
	foreach ($etat['utilisateurs'] as $id => $u) {
		if ($u['username'] === $nom) {
			echo json_encode(['data' => $utilisateur($id, $u)]);
			return;
		}
	}
	http_response_code(404);
	echo json_encode(['errors' => [['title' => 'User not found']]]);
	return;
}
if ($chemin === '/api/admin/v1/users' && $methode === 'POST') {
	$id = 'U'.(count($etat['utilisateurs']) + 1);
	$etat['utilisateurs'][$id] = ['username' => (string)($donnees['username'] ?? ''), 'admin' => false, 'motdepasse' => null];
	$enregistrer();
	http_response_code(201);
	echo json_encode(['data' => $utilisateur($id, $etat['utilisateurs'][$id])]);
	return;
}
if (preg_match('#^/api/admin/v1/users/([^/]+)$#', $chemin, $m) === 1 && $methode === 'GET') {
	if (isset($etat['utilisateurs'][$m[1]])) {
		echo json_encode(['data' => $utilisateur($m[1], $etat['utilisateurs'][$m[1]])]);
		return;
	}
	http_response_code(404);
	echo json_encode(['errors' => [['title' => 'User not found']]]);
	return;
}
if (preg_match('#^/api/admin/v1/users/([^/]+)/set-password$#', $chemin, $m) === 1 && $methode === 'POST') {
	if (!isset($etat['utilisateurs'][$m[1]])) {
		http_response_code(404);
		echo json_encode(['errors' => [['title' => 'User not found']]]);
		return;
	}
	$etat['utilisateurs'][$m[1]]['motdepasse'] = (string)($donnees['password'] ?? '');
	$enregistrer();
	http_response_code(204);
	return;
}
if (preg_match('#^/api/admin/v1/users/([^/]+)/set-admin$#', $chemin, $m) === 1 && $methode === 'POST') {
	if (isset($etat['utilisateurs'][$m[1]])) {
		$etat['utilisateurs'][$m[1]]['admin'] = (bool)($donnees['admin'] ?? false);
		$enregistrer();
	}
	http_response_code(204);
	return;
}
if ($chemin === '/api/admin/v1/user-emails' && $methode === 'POST') {
	foreach ($etat['courriels'] as $c) {
		if ($c['email'] === ($donnees['email'] ?? '')) {
			http_response_code(409);
			echo json_encode(['errors' => [['title' => 'Email already in use']]]);
			return;
		}
	}
	$id = 'E'.(count($etat['courriels']) + 1);
	$etat['courriels'][$id] = ['user_id' => (string)($donnees['user_id'] ?? ''), 'email' => (string)($donnees['email'] ?? '')];
	$enregistrer();
	http_response_code(201);
	echo json_encode(['data' => $courriel($id, $etat['courriels'][$id])]);
	return;
}
if ($chemin === '/api/admin/v1/user-emails' && $methode === 'GET') {
	$liste = [];
	foreach ($etat['courriels'] as $id => $c) {
		$parUtilisateur = isset($_GET['filter']['user']) && $c['user_id'] === $_GET['filter']['user'];
		$parCourriel = isset($_GET['filter']['email']) && $c['email'] === $_GET['filter']['email'];
		if (!isset($_GET['filter']) || $parUtilisateur || $parCourriel) {
			$liste[] = $courriel($id, $c);
		}
	}
	echo json_encode(['meta' => ['count' => count($liste)], 'data' => $liste]);
	return;
}
if (preg_match('#^/api/admin/v1/user-emails/([^/]+)$#', $chemin, $m) === 1) {
	if (!isset($etat['courriels'][$m[1]])) {
		http_response_code(404);
		echo json_encode(['errors' => [['title' => 'Email not found']]]);
		return;
	}
	if ($methode === 'DELETE') {
		unset($etat['courriels'][$m[1]]);
		$enregistrer();
		http_response_code(204);
		return;
	}
	echo json_encode(['data' => $courriel($m[1], $etat['courriels'][$m[1]])]);
	return;
}
http_response_code(404);
echo json_encode(['faux' => 'non simule', 'chemin' => $chemin]);
