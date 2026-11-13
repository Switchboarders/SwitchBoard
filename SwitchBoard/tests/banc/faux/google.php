<?php
// Faux oauth2.googleapis.com : l'échange du code d'autorisation de l'assistant Courriel OAuth. Seul le code « bon-code » est accepté.
header('Content-Type: application/json');
if ($chemin === '/token') {
	parse_str($corps, $champs);
	if (($champs['grant_type'] ?? '') === 'authorization_code' && ($champs['code'] ?? '') === 'bon-code') {
		echo json_encode(['access_token' => 'at-banc-google', 'refresh_token' => 'rt-banc-google', 'expires_in' => 3600]);
		return;
	}
	http_response_code(400);
	echo json_encode(['error' => 'invalid_grant', 'error_description' => 'Code refusé (banc)']);
	return;
}
http_response_code(501);
echo json_encode(['querystatus' => 'non simule']);
