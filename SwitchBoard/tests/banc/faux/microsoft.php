<?php
// Faux login.microsoftonline.com : le flux « device code » de l'assistant Courriel OAuth (oauthcourriel.php).
// Le code est donné à chaque demande ; le jeton n'est donné qu'une fois le fichier ms-autorise écrit par le scénario
// (la personne a alors fini l'étape dans son navigateur), sinon « authorization_pending ».
header('Content-Type: application/json');
if (preg_match('#^/[^/]+/oauth2/v2\.0/devicecode$#', $chemin) === 1) {
	echo json_encode([
		'device_code' => 'dc-banc',
		'user_code' => 'BANC-1234',
		'verification_uri' => 'https://microsoft.com/devicelogin',
		'interval' => 5,
		'expires_in' => 900,
	]);
	return;
}
if (preg_match('#^/[^/]+/oauth2/v2\.0/token$#', $chemin) === 1) {
	parse_str($corps, $champs);
	if (is_file(ETAT.'/ms-autorise') && ($champs['device_code'] ?? '') === 'dc-banc') {
		echo json_encode(['access_token' => 'at-banc-ms', 'refresh_token' => 'rt-banc-ms', 'expires_in' => 3600]);
		return;
	}
	http_response_code(400);
	echo json_encode(['error' => 'authorization_pending', 'error_description' => 'En attente (banc)']);
	return;
}
http_response_code(501);
echo json_encode(['querystatus' => 'non simule']);
