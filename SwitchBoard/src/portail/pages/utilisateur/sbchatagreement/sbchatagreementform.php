<?php
// Entente de confidentialité des bénévoles (Loi 25). Les bénévoles la lisent (!entente) et la signent
// (!signer Prénom Nom) dans leur « Salle Utilisateur » d'Element ; SwitchBoard Chat garde la preuve
// (voir switchboard-chat src/agreement.rs). Cette page montre qui a signé, permet d'envoyer l'entente
// maintenant et d'exporter le registre. $dbmanager et $currentuserid viennent de sbchatmacrospage.php.
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

$sbchatinfo = $dbmanager->getSbchatinfobyId($currentuserid);
if(empty($sbchatinfo['sbchatapitoken'])){
	echo "<H1>"._("Cet utilisateur n'a pas de token SB Chat. Veuillez compléter la configuration du compte.")."</H1>";
	exit(1);
}
if (empty($sbchatinfo['sbchatserveraddress'])){
	echo "<H1>"._("Aucun adresse de serveur SB Chat configurée !")."</H1>";
	exit(1);
}
$callcenterapitoken = $sbchatinfo['sbchatapitoken'];
$sbchat = new ClientSwitchBoardChat($sbchatinfo['sbchatserveraddress']);

function sbagreement_date($unix){
	if(empty($unix)) return "";
	$date = new DateTime("@".intval($unix));
	$date->setTimezone(new DateTimeZone('America/Toronto'));
	return $date->format('Y-m-d H:i');
}
$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

// Envoyer l'entente maintenant (le bouton de la page)
if(!empty($_POST['send_agreement'])){
	requireCsrf();
	commencerEnregistrement();
	[$ok, $result] = $sbchat->send_agreement($callcenterapitoken);
	if($ok){
		echo "<div class='alert alert-success'>".sprintf(_("Entente envoyée à %d bénévole(s) qui ne l'avaient pas encore signée."), intval($result))."</div>";
	}else{
		echo "<div class='alert alert-error'>"._("L'envoi a échoué.")." ".$h($result)."</div>";
	}
	// Redirection vers la même page (#77) : F5 n'enverrait pas l'entente une deuxième fois
	terminerEnregistrement();
}

// Ce que la vue montre : l'état de SwitchBoard Chat ('injoignable', 'inactive' ou 'ok'), puis les lignes du registre
$data = $sbchat->list_agreement_signatures($callcenterapitoken);
$etat = 'ok';
if($data === null){
	$etat = 'injoignable';
}elseif(empty($data['configured'])){
	$etat = 'inactive';
}else{
	$people = $data['respondents'] ?? [];
	$signed = count(array_filter($people, fn($p) => !empty($p['signed_current'])));
	$total = count($people);
	$lignes = []; // une ligne du tableau : bénévole, statut, nom tapé, date, appareil, version
	$csv = [[_("Bénévole"), _("Statut"), _("Nom tapé"), _("Date de signature (heure de l'Est)"), _("Appareil"), _("Appareil vérifié"), _("Version signée"), _("Empreinte du texte signé"), _("Référence du message")]];
	foreach($people as $p){
		$latest = $p['latest'] ?? null;
		if(!empty($p['signed_current'])){
			$status = "✅ "._("Signée");
		}elseif(!empty($latest)){
			$status = "⚠️ "._("Ancienne version");
		}else{
			$status = "❌ "._("À signer");
		}
		$device = $latest ? (($latest['device_id'] ?? '?').(!empty($latest['device_verified']) ? " ("._("vérifié").")" : " ("._("non vérifié").")")) : "";
		$lignes[] = [$p['respondent'], $status, $latest['typed_name'] ?? '', sbagreement_date($latest['signed_at'] ?? null), $device, $latest['version'] ?? ''];
		$csv[] = [$p['respondent'], $status, $latest['typed_name'] ?? '', sbagreement_date($latest['signed_at'] ?? null), $latest['device_id'] ?? '', $latest ? (!empty($latest['device_verified']) ? _("oui") : _("non")) : '', $latest['version'] ?? '', $latest['text_sha256'] ?? '', $latest['event_id'] ?? ''];
	}
	$out = fopen('php://temp', 'r+');
	fwrite($out, "\xEF\xBB\xBF"); // BOM : Excel lit correctement les accents
	foreach($csv as $line){ fputcsv($out, $line, ';', '"', '\\'); }
	rewind($out);
	$csvdata = stream_get_contents($out);
	fclose($out);
	$filename = "entente-confidentialite-".date('Y-m-d').".csv";
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/sbchatagreement/sbchatagreementform.vue.php';
