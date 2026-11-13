<?php
ini_set('memory_limit', '128M');
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé partagée
// CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();
require_once SRC_DIRECTORY.'utils/verrouexclusif.php';

// Vérifie que le courriel est réellement livrable, sans jamais dépendre du courriel lui-même
// pour rapporter sa propre panne (le ping part vers le service externe de surveillance, jamais
// par courriel).
//
// Deux modes selon l'installation :
// - msmtp présent (déploiement Docker, ou installation manuelle avec msmtp configuré à la
//   main) : appelle directement le binaire msmtp avec le /etc/msmtprc réel plutôt que mail()
//   de PHP - mail() ne rapporte que "accepté par le processus sendmail/msmtp", jamais le
//   résultat SMTP réel, et avale le texte de diagnostic de msmtp - exactement le genre de
//   détail qui a permis d'identifier les 3 vrais bugs de courriel trouvés cette session
//   (mécanisme SASL, routage IPv6, chemin de passwordeval).
// - msmtp absent (ex.: installation cPanel/hébergement mutualisé où le courriel passe par le
//   sendmail/exim natif du serveur, jamais par msmtp) : teste directement mail() de PHP, le
//   seul mécanisme réellement en jeu sur ce genre d'installation - moins précis que msmtp
//   (mail() ne confirme que la prise en charge par le MTA local, pas la livraison SMTP
//   réelle), mais c'est le seul signal disponible sans msmtp, et bien mieux que de rapporter
//   une fausse panne juste parce que msmtp n'est pas installé alors que le courriel
//   fonctionne très bien autrement (vérifié en conditions réelles : une installation cPanel
//   avec courriel natif fonctionnel déclenchait une fausse alerte avant ce correctif).
//
// Un second essai suit un échec : une coupure TLS ou un rafraîchissement de jeton raté se règlent souvent seuls (24
// échecs sur 4 400 passages en 90 jours sur le serveur principal). Le check n'échoue que si les deux ratent.
$dms = new SignalDeVie('mail');
$dms->armerDetectionEchec();

if (!$dms->estConfigure()) {
	echo "mailcanary: dead-man's-switch non configuré pour 'mail', on ignore.\n";
	$dms->finirAvecSucces();
	exit(0);
}

$verrouMail = new VerrouExclusif('mailcanary');
if (!$verrouMail->acquerirSansAttendre()) {
	$dms->finirAvecEchec("Chevauchement détecté sur mailcanary (exécution précédente toujours en cours).");
	exit(2);
}
register_shutdown_function([$verrouMail, 'liberer']);

$destinataire = getenv('SB_DMS_MAIL_TEST_RECIPIENT');
if (!is_string($destinataire) || $destinataire === '') {
	$databasemanager = new DatabaseManager();
	$destinataire = $databasemanager->getAdminMail();
}
if (!is_string($destinataire) || $destinataire === '') {
	$dms->finirAvecEchec("mailcanary: aucune adresse de test disponible (ni SB_DMS_MAIL_TEST_RECIPIENT, ni courriel admin en BD).");
	echo "mailcanary: aucune adresse de test disponible.\n";
	exit(1);
}

$sujet = "SwitchBoard - test automatique de disponibilité du courriel";
$corps = "Ceci est un courriel de test automatique (mailcanary.php / dead-man's-switch). Aucune action requise. Généré le : ".date('Y-m-d H:i:s');

$msmtpBinaire = '/usr/bin/msmtp';

// Pas de msmtp sur ce serveur : le courriel passe forcément par mail() natif (sendmail/exim de l'hébergeur) - c'est
// donc ce mécanisme qu'on teste, pas une absence de configuration. Retourne null si l'envoi réussit, sinon la raison.
$essayerMailNatif = function () use ($destinataire, $sujet, $corps): ?string {
	if (@mail($destinataire, $sujet, $corps)) {
		return null;
	}
	$erreurDerniere = error_get_last();
	$detailErreur = ($erreurDerniere !== null) ? " : ".$erreurDerniere['message'] : "";
	return "mail() a échoué en tentant d'envoyer vers {$destinataire}{$detailErreur}";
};

// Retourne null si l'envoi réussit, sinon la raison.
$essayerMsmtp = function () use ($msmtpBinaire, $destinataire, $sujet, $corps): ?string {
	$message = "To: {$destinataire}\r\nSubject: {$sujet}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$corps}\r\n";
	$descripteurs = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
	$processus = @proc_open([$msmtpBinaire, '-C', '/etc/msmtprc', '-t'], $descripteurs, $pipes);
	if (!is_resource($processus)) {
		return "mailcanary: impossible de lancer {$msmtpBinaire} malgré sa présence détectée.";
	}
	fwrite($pipes[0], $message);
	fclose($pipes[0]);
	$sortie = stream_get_contents($pipes[1]);
	$erreur = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$codeRetour = proc_close($processus);
	if ($codeRetour === 0) {
		return null;
	}
	return "msmtp a échoué (code={$codeRetour}) en tentant d'envoyer vers {$destinataire} : ".trim($erreur !== '' ? $erreur : $sortie);
};

if (is_executable($msmtpBinaire)) {
	if (!file_exists('/etc/msmtprc')) {
		// msmtp est installé mais /etc/msmtprc n'existe pas : courriel volontairement désactivé
		// (voir generate_mail_config.sh, convention Docker) - PAS une panne.
		$dms->finirAvecSucces("Courriel non configuré (/etc/msmtprc absent) - rien à tester, considéré comme normal.");
		echo "mailcanary: courriel non configuré, rien à tester.\n";
		exit(0);
	}
	$essayer = $essayerMsmtp;
	$succes = "Envoi réussi vers {$destinataire} (msmtp code=0).";
} else {
	$essayer = $essayerMailNatif;
	$succes = "Envoi réussi vers {$destinataire} via mail() natif (aucun msmtp détecté sur ce serveur - installation manuelle/hébergement mutualisé probable).";
}

$premierEchec = $essayer();
if ($premierEchec === null) {
	$dms->finirAvecSucces($succes);
	echo "mailcanary: succès.\n";
	exit(0);
}

echo "mailcanary: premier essai raté, second essai dans ".DELAI_SECOND_ESSAI_CANARI." s - $premierEchec\n";
sleep(DELAI_SECOND_ESSAI_CANARI);
$secondEchec = $essayer();
if ($secondEchec === null) {
	$dms->finirAvecSucces("$succes Réussi au second essai ; le premier avait raté : $premierEchec");
	echo "mailcanary: succès au second essai.\n";
	exit(0);
}
$dms->finirAvecEchec($secondEchec);
echo "mailcanary: échec - $secondEchec\n";
