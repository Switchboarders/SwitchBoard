<?php

// Remplace testmail.php (utilisable seulement en CLI, via docker exec) comme façon normale de
// vérifier que le courriel fonctionne : un administrateur qui n'a jamais touché à Docker ne
// devrait pas avoir à ouvrir un terminal dans le conteneur pour savoir si les notifications
// d'erreurs partent réellement.
//
// Contrairement à PHP mail() (qui ne renvoie qu'un booléen, sans aucun détail en cas d'échec),
// on appelle msmtp directement et on capture sa sortie d'erreur : un mauvais mot de passe, un
// mauvais port ou un problème TLS s'affichent alors tels quels, au lieu d'un simple « échec ».
//
// Replaces testmail.php (CLI-only, via docker exec) as the normal way to check that mail
// works: an administrator who has never touched Docker shouldn't need to open a terminal
// inside the container to find out whether error notifications actually go out.
//
// Unlike PHP mail() (which only returns a boolean, with no detail on failure), this calls
// msmtp directly and captures its error output: a wrong password, wrong port, or TLS problem
// shows up as-is, instead of a bare "failed."

$dbmanager = new DatabaseManager();
$currentuserid = $dbmanager->getcurrentuserid();
$userinfo = $dbmanager->getuserinfobyid($currentuserid);
$adresseParDefaut = $userinfo['validationfeedbackemail'] ?? '';

// Ce que l'envoi précédent a dit (voir terminerEnregistrement()), une seule fois
$valeurs = valeursDEnregistrement();
$messageErreur = (string)($valeurs['erreur'] ?? '');
$messageSucces = (string)($valeurs['succes'] ?? '');
$sortieDetail = (string)($valeurs['detail'] ?? '');

// Envoie un courriel de test via msmtp, en évitant tout passage par un shell (proc_open avec
// un tableau de commande n'interprète pas de métacaractères) : l'adresse soumise par
// l'utilisateur n'a donc pas besoin d'échappement supplémentaire.
function envoyerCourrielTest($adresse) {
	$corps = "Subject: SwitchBoard - courriel de test\r\n"
		. "Content-Type: text/plain; charset=UTF-8\r\n"
		. "\r\n"
		. _("Ceci est un courriel de test envoyé depuis la console d'administration de SwitchBoard.")."\r\n"
		. _("Si vous recevez ce message, la configuration de courriel fonctionne correctement.")."\r\n";

	$descripteurs = [
		0 => ['pipe', 'r'],
		1 => ['pipe', 'w'],
		2 => ['pipe', 'w'],
	];
	$processus = @proc_open(['msmtp', '-a', 'default', $adresse], $descripteurs, $pipes);
	if (!is_resource($processus)) {
		return ['succes' => false, 'detail' => _("Impossible de lancer msmtp.")];
	}
	fwrite($pipes[0], $corps);
	fclose($pipes[0]);
	$sortie = stream_get_contents($pipes[1]);
	$erreur = stream_get_contents($pipes[2]);
	fclose($pipes[1]);
	fclose($pipes[2]);
	$code = proc_close($processus);

	return [
		'succes' => $code === 0,
		'detail' => trim($erreur."\n".$sortie),
	];
}

if (!empty($_POST['action']) && $_POST['action'] === 'tester') {
	requireCsrf();
	commencerEnregistrement();
	$adresse = trim($_POST['adresse'] ?? '');
	if (!filter_var($adresse, FILTER_VALIDATE_EMAIL)) {
		$messageErreur = _("Adresse courriel non valide.");
	} else {
		$resultat = envoyerCourrielTest($adresse);
		if ($resultat['succes']) {
			$messageSucces = sprintf(_("Courriel de test envoyé avec succès à %s."), htmlspecialchars($adresse));
		} else {
			$messageErreur = sprintf(_("Échec de l'envoi à %s."), htmlspecialchars($adresse));
		}
		$sortieDetail = $resultat['detail'];
	}
	// Redirection vers la même page (#77) : F5 n'enverrait pas un autre courriel
	terminerEnregistrement(['erreur' => $messageErreur, 'succes' => $messageSucces, 'detail' => $sortieDetail]);
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/mailtestpage.vue.php';
