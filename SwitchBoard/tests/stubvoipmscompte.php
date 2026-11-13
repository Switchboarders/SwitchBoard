<?php
// Petit serveur "php -S" qui simule un compte voip.ms complet (renvois d'appel, groupes de
// sonnerie, messageries vocales) pour le test de la liste conservée (listeconservee_test.php),
// sans réseau ni identifiants réels. L'état du compte est gardé dans stub-compte.json, à côté
// de ce fichier, avec le journal des méthodes appelées.
//
// Usage : php -S 127.0.0.1:8996 stubvoipmscompte.php
// Le faux voip.ms du banc d'essai (banc/faux/voipms.php) inclut ce fichier et choisit son propre fichier d'état
$fichierEtat = $fichierEtat ?? __DIR__."/stub-compte.json";
$etat = json_decode((string) @file_get_contents($fichierEtat), true)
	?? ['renvois' => [], 'groupes' => [], 'prochainId' => 101, 'journal' => []];

$p = $_GET;
$methode = $p['method'] ?? '';
$etat['journal'][] = $methode;

function trouverRenvoiParNumero(array $etat, string $numero): ?string {
	foreach ($etat['renvois'] as $id => $renvoi) {
		if ($renvoi['phone_number'] === $numero) return (string)$id;
	}
	return null;
}

switch ($methode) {
	case 'getForwardings':
		$liste = [];
		foreach ($etat['renvois'] as $id => $renvoi) {
			$liste[] = ['forwarding' => (string)$id] + $renvoi;
		}
		$reponse = empty($liste) ? ['status' => 'no_forwarding'] : ['status' => 'success', 'forwardings' => $liste];
		break;

	case 'setForwarding':
		$renvoi = ['phone_number' => $p['phone_number'], 'description' => $p['description'], 'callerid_override' => $p['callerid_override']];
		if (!empty($p['forwarding'])) {
			if (isset($etat['renvois'][$p['forwarding']])) {
				$etat['renvois'][$p['forwarding']] = $renvoi;
				$reponse = ['status' => 'success', 'forwarding' => $p['forwarding']];
			} else {
				$reponse = ['status' => 'invalid_forwarding'];
			}
		} elseif (trouverRenvoiParNumero($etat, $p['phone_number']) !== null) {
			$reponse = ['status' => 'used_number'];
		} else {
			$id = (string)$etat['prochainId']++;
			$etat['renvois'][$id] = $renvoi;
			$reponse = ['status' => 'success', 'forwarding' => $id];
		}
		break;

	case 'delForwarding':
		if (isset($etat['renvois'][$p['forwarding']])) {
			unset($etat['renvois'][$p['forwarding']]);
			$reponse = ['status' => 'success'];
		} else {
			$reponse = ['status' => 'invalid_forwarding'];
		}
		break;

	case 'getVoicemails':
		$reponse = ['status' => 'success', 'voicemails' => [['mailbox' => '5145550000']]];
		break;

	case 'getRingGroups':
		$liste = [];
		foreach ($etat['groupes'] as $id => $groupe) {
			$liste[] = ['ring_group' => (string)$id] + $groupe;
		}
		$reponse = empty($liste) ? ['status' => 'no_ring_group'] : ['status' => 'success', 'ring_groups' => $liste];
		break;

	case 'setRingGroup':
		// Le vrai voip.ms refuse un nom trop long (vu le 2026-11-06 : la limite est entre 16 et 25 caractères). Le faux
		// ne refuse que ce qui dépasse 25 caractères, la limite la plus haute possible.
		if (strlen((string)($p['name'] ?? '')) > 25) {
			$reponse = ['status' => 'name_toolong'];
			break;
		}
		preg_match_all('/fwd:([^,]+),/', $p['members'] ?? '', $trouves);
		$membres = $trouves[1];
		if (empty($membres)) {
			$reponse = ['status' => 'missing_members'];
			break;
		}
		foreach ($membres as $id) {
			if (!isset($etat['renvois'][$id])) {
				// Statut du vrai voip.ms, vérifié par banc/reel/essai_voipms.py
				$reponse = ['status' => 'invalid_forwarding'];
				break 2;
			}
		}
		$id = !empty($p['ring_group']) ? $p['ring_group'] : (string)(900 + count($etat['groupes']));
		// texte_membres : la chaîne reçue telle quelle, avec durée de sonnerie et « Faire le 1 »
		$etat['groupes'][$id] = ['name' => $p['name'], 'members' => $membres, 'texte_membres' => $p['members'], 'voicemail' => $p['voicemail'] ?? ''];
		$reponse = ['status' => 'success', 'ring_group' => $id];
		break;

	default:
		$reponse = ['status' => 'invalid_method'];
}

file_put_contents($fichierEtat, json_encode($etat));
header('Content-Type: application/json');
echo json_encode($reponse);
