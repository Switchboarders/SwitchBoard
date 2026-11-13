<?php
// Faux GitHub : le dépôt distant est ce que le scénario dépose dans <état>/github/. Sans fichier
// release.json, il n'y a aucune version publiée et le code se rabat sur sa branche, comme aujourd'hui.
// Comme le vrai, api.github.com redirige le téléchargement de l'archive vers codeload.github.com.
header('Content-Type: application/json');
$depot = ETAT.'/github';

// Le jeton de chaque requête, noté dans <état>/github-jetons.txt : sans, accepte ou refuse. Un jeton qui contient
// « revoque » est refusé comme le vrai GitHub refuse un jeton révoqué ou expiré (401, « Bad credentials »).
$autorisation = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$jetonRefuse = str_contains($autorisation, 'revoque');
file_put_contents(ETAT.'/github-jetons.txt', ($autorisation === '' ? 'sans' : ($jetonRefuse ? 'refuse' : 'accepte'))."\n", FILE_APPEND | LOCK_EX);
if ($jetonRefuse) {
	http_response_code(401);
	echo json_encode(['message' => 'Bad credentials']);
	return;
}

if (preg_match('#^/repos/[^/]+/[^/]+/releases/latest$#', $chemin)) {
	if (!is_readable("$depot/release.json")) {
		http_response_code(404);
		echo json_encode(['message' => 'Not Found']);
		return;
	}
	readfile("$depot/release.json");
	return;
}

if (preg_match('#^/repos/[^/]+/[^/]+/contents/(.+)$#', $chemin, $trouve)) {
	$fichier = "$depot/depot/".str_replace('..', '', rawurldecode($trouve[1]));
	if (!is_file($fichier)) {
		http_response_code(404);
		echo json_encode(['message' => 'Not Found']);
		return;
	}
	$contenu = file_get_contents($fichier);
	echo json_encode(['type' => 'file', 'encoding' => 'base64', 'name' => basename($fichier), 'content' => base64_encode($contenu)]);
	return;
}

if (preg_match('#^/repos/[^/]+/[^/]+/git/ref(s)?/tags/#', $chemin) && is_readable("$depot/release.json")) {
	$release = json_decode(file_get_contents("$depot/release.json"), true);
	echo json_encode(['object' => ['type' => 'commit', 'sha' => $release['sha_du_banc']]]);
	return;
}

if (preg_match('#^/repos/[^/]+/[^/]+/zipball/#', $chemin) && is_readable("$depot/depot.zip")) {
	header('Location: https://codeload.github.com/banc/depot/zip/reference', true, 302);
	return;
}

if ($hote === 'codeload.github.com' && is_readable("$depot/depot.zip")) {
	header('Content-Type: application/zip');
	readfile("$depot/depot.zip");
	return;
}

http_response_code(404);
echo json_encode(['message' => 'Not Found']);
