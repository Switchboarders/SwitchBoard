<?php
// Diagnostic d'un comité : on fait les tests ici (voip.ms, téléchargement de l'agenda), la vue ne fait qu'afficher les résultats
$infos = $this->webpageparent->userinformations;
$ligneNomUtilisateur = ligneNomUtilisateur($this->webpageparent->databasemanager,$_GET['iduser']);
$infos->getUserinfofromdatabase($_GET['iduser']);
$lienInfosUtilisateur = "<a href='?page=userinfo&iduser=".intval($_GET['iduser'])."'>"._("Éditer informations utilisateur")."</a>";

// Tester la connexion vers voip.ms (sans identifiants, inutile d'interroger voip.ms : la vue dit quoi remplir)
$voipms = new ClientVoipms($infos);
$identifiantsVides = ($infos->voipmsusername === "" || $infos->voipmsapipassword === "");
$diagnosticVoipms = $identifiantsVides ? '' : $voipms->diagnostiquer();

// Même téléchargement que le cron (TraitementComite::telechargerAgenda()) : le diagnostic
// montre exactement ce que le cron rencontrera, pour une URL secrète comme pour CalDAV.
$agendaVide = ($infos->calendarurl === "");
$erreurAgenda = null; // le message de l'exception si le téléchargement échoue
if (!$agendaVide) {
	$calendarmanager = new TraitementComite($infos,new Courriel(""),$voipms);
	try {
		$calendarmanager->telechargerAgenda();
	} catch (Exception $e) {
		$erreurAgenda = $e->getMessage();
	}
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/userdiagnostic.vue.php';
