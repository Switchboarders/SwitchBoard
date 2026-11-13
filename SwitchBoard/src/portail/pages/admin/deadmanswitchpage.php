<?php
// Réglages des pings de dead-man's-switch (healthchecks.io ou compatible) - voir
// commun/surveillance.php pour le stockage (reglagesDeadManSwitch()/enregistrerReglagesDeadManSwitch()),
// SignalDeVie.php pour l'émission des pings, et DEADMANSWITCH-SETUP.md pour le guide
// complet (créer un compte, choisir un canal de notification - tout ça se fait chez le
// fournisseur externe, rien à faire ici pour ça). Cette page ne fait QUE stocker les URLs de
// ping par check et permettre un envoi de test manuel.
//
// Dead-man's-switch ping settings (healthchecks.io or compatible) - see
// commun/surveillance.php for storage, SignalDeVie.php for emitting pings, and
// DEADMANSWITCH-SETUP.md for the full guide (creating an account, choosing a notification
// channel - all of that happens on the external provider's side, nothing to do here for that).
// This page ONLY stores per-check ping URLs and lets you send a manual test ping.

$checks = [
	'ringgroup' => _("Groupes de sonnerie (refreshallringgroups.php, à toutes les 10 min)"),
	'sbchat' => _("SwitchBoard Chat (refreshsbchat.php, à toutes les 10 min)"),
	'mail' => _("Courriel - test réel de livraison (mailcanary.php, aux 30 min)"),
	'infra' => _("Infrastructure - base de données et espace disque (checkinfra.php, aux 30 min)"),
];

// Ce que l'enregistrement précédent a dit (voir terminerEnregistrement()), une seule fois
$valeurs = valeursDEnregistrement();
$messageErreur = (string)($valeurs['erreur'] ?? '');
$messageSucces = (string)($valeurs['succes'] ?? '');
$messageTest = (string)($valeurs['test'] ?? '');

// Un envoi qui agit répond par une redirection vers la même page (#77) : F5 n'enverrait pas un autre ping de test
$envoiDuFormulaire = in_array($_POST['action'] ?? '', ['enregistrer_deadmanswitch', 'supprimer_deadmanswitch', 'test_ping_deadmanswitch'], true);
if ($envoiDuFormulaire) {
	requireCsrf();
	commencerEnregistrement();
}

if (!empty($_POST['action']) && $_POST['action'] === 'enregistrer_deadmanswitch') {
	requireCsrf();
	$check = $_POST['check'] ?? '';
	if (!array_key_exists($check, $checks)) {
		$messageErreur = _("Check inconnu.");
	} elseif (reglageDeadManSwitchForceParEnv($check)) {
		$messageErreur = _("Ce check est géré par une variable d'environnement - modifiez .env puis redémarrez le conteneur.");
	} else {
		$successUrlSoumise = trim((string)($_POST['success_url'] ?? ''));
		$failUrlSoumise = trim((string)($_POST['fail_url'] ?? ''));
		if ($successUrlSoumise === '') {
			$messageErreur = _("L'URL de ping de succès est requise.");
		} else {
			if (enregistrerReglagesDeadManSwitch($check, $successUrlSoumise, $failUrlSoumise)) {
				$messageSucces = _("Réglages enregistrés pour : ").$checks[$check];
			} else {
				$messageErreur = _("Impossible d'enregistrer les réglages (vérifiez les droits d'écriture sur le dossier config/).");
			}
		}
	}
}

if (!empty($_POST['action']) && $_POST['action'] === 'supprimer_deadmanswitch') {
	requireCsrf();
	$check = $_POST['check'] ?? '';
	if (array_key_exists($check, $checks) && !reglageDeadManSwitchForceParEnv($check)) {
		supprimerReglagesDeadManSwitch($check);
		$messageSucces = _("Réglages retirés pour : ").$checks[$check];
	}
}

if (!empty($_POST['action']) && $_POST['action'] === 'test_ping_deadmanswitch') {
	requireCsrf();
	$check = $_POST['check'] ?? '';
	if (array_key_exists($check, $checks)) {
		$dmsTest = new SignalDeVie($check);
		if (!$dmsTest->estConfigure()) {
			$messageErreur = _("Aucune URL de succès enregistrée pour ce check - enregistrez d'abord les réglages ci-dessous.");
		} else {
			// Un simple ping de succès ne déclenche généralement AUCUNE notification chez le
			// fournisseur externe (rien n'a changé d'état si le check était déjà en bonne
			// santé) - inutile pour confirmer que les alertes fonctionnent vraiment. On envoie
			// donc un ping d'échec explicite (déclenche une vraie alerte, le changement d'état
			// que le fournisseur notifie) suivi immédiatement d'un ping de succès (rétablit
			// l'état normal, pour ne pas laisser le check faussement "en panne" jusqu'au
			// prochain passage réel de la tâche cron).
			$horodatage = date('Y-m-d H:i:s');
			$dmsTest->pingEchec(_("Test manuel depuis la console admin (déclenche volontairement une alerte de test), le ").$horodatage);
			$dmsTest->pingSucces(_("Test manuel depuis la console admin (rétablissement immédiat après le test), le ").$horodatage);
			$messageTest = _("Ping de test envoyé pour : ").$checks[$check].' '._("Un ping d'échec puis un ping de succès ont été envoyés - vous devriez recevoir une vraie notification sous peu (le check redevient normal aussitôt après).");
		}
	}
}
if ($envoiDuFormulaire) {
	terminerEnregistrement(['erreur' => $messageErreur, 'succes' => $messageSucces, 'test' => $messageTest]);
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/deadmanswitchpage.vue.php';
