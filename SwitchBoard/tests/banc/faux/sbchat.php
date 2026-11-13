<?php
// Faux serveur SwitchBoard Chat : les commandes arrivent en JSON, et le routeur les a déjà notées au
// journal. MAS n'est pas simulé.
header('Content-Type: application/json');
$demande = json_decode($corps, true) ?? [];
$commande = $demande['command'] ?? '';
if ($chemin !== '/sbchat_api') {
	http_response_code(501);
	echo json_encode(['querystatus' => 'non simule']);
	return;
}
switch ($commande) {
	case 'change_respondents':
	case 'send_message':
		echo json_encode(['querystatus' => 'success', 'message' => implode(',', (array)($demande['respondent'] ?? []))]);
		return;
	// Les pages des macros et de l'entente (userpages/sbchatmacros, sbchatagreement) : réponses fixes, seul le journal compte
	case 'list_macros':
		// macros-mode (écrit par le scénario 46) : « vide » = un centre d'appel sans macro ; « panne » = jeton refusé
		$mode = trim((string)@file_get_contents(ETAT.'/macros-mode'));
		if ($mode === 'vide') {
			echo json_encode(['querystatus' => 'Success', 'message' => '[]']);
			return;
		}
		if ($mode === 'panne') {
			echo json_encode(['querystatus' => 'Error', 'message' => 'Invalid token']);
			return;
		}
		if ($mode === 'pieges') {
			echo json_encode(['querystatus' => 'Success', 'message' => json_encode([
				['id_macro' => '7', 'id_call_center' => '1', 'macro_name' => '<b>gras</b>', 'macro_text' => '<script>alert(1)</script>', 'macro_tags' => 'newcomer', 'macro_tags_config' => null],
			])]);
			return;
		}
		echo json_encode(['querystatus' => 'Success', 'message' => json_encode([
			['id_macro' => '1', 'id_call_center' => '1', 'macro_name' => 'accueil', 'macro_text' => 'Bonjour', 'macro_tags' => '', 'macro_tags_config' => null],
		])]);
		return;
	case 'update_macro':
		echo json_encode(['querystatus' => 'Success', 'message' => 'Macro enregistrée (banc)']);
		return;
	case 'list_agreement_signatures':
		echo json_encode(['querystatus' => 'Success', 'message' => json_encode([
			'configured' => true, 'version' => 'banc-1', 'sha256' => str_repeat('0', 64),
			'respondents' => [['signed_current' => true, 'respondent' => '@lita:banc.test']],
		])]);
		return;
	case 'send_agreement':
		echo json_encode(['querystatus' => 'Success', 'message' => json_encode(['sent' => 3])]);
		return;
	default:
		http_response_code(501);
		echo json_encode(['querystatus' => 'non simule']);
}
