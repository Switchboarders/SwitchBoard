<?php
ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();
$debut = date('Y-m-d H:i:s');
echo "Début du traitement : " . $debut."\n";
//Detecter le serveur actuel
$serverName = gethostname();
echo "Nous sommes sur le serveur: $serverName\n";
//Obtenir le nom du serveur via la variable d'env SERVEUR provenant de la tâche CRON
$serverCron = getenv('SERVEUR');

if ($serverCron != false && $serverName != $serverCron){
	echo "Attention, on roule sur le mauvais serveur: $serverName, on abandonne !\n";
	die(99);
}

require_once SRC_DIRECTORY.'utils/verrouexclusif.php';

// Surveillance dead-man's-switch (voir DEADMANSWITCH-SETUP.md) : ce rafraîchissement doit
// tourner à toutes les 10 minutes sans interruption - armerDetectionEchec() capte aussi un
// crash imprévu (erreur fatale, die() non prévu) qui n'aurait pas été rattrapé plus bas.
$dms = new SignalDeVie('ringgroup');
$dms->armerDetectionEchec();

// Détection de chevauchement : si l'exécution précédente tourne toujours (ex.: beaucoup de
// comités, API voip.ms lente), on abandonne CETTE invocation immédiatement plutôt que de
// risquer une contention sur l'API - voir VerrouExclusif pour la garantie de libération même
// en cas de plantage/kill de l'exécution précédente.
$verrouRingGroup = new VerrouExclusif(VerrouExclusif::SONNERIE);
if (!$verrouRingGroup->acquerirSansAttendre()) {
	// Une mise à jour tient ce verrou exprès : ce passage saute sans échec, sinon chaque mise à jour
	// enverrait une fausse alerte au signal de vie.
	if (VerrouExclusif::miseAJourEnCours()) {
		echo "refreshallringgroups: mise à jour de SwitchBoard en cours - on saute ce passage.\n";
		$dms->finirAvecSucces("Passage sauté : mise à jour de SwitchBoard en cours.");
		exit(0);
	}
	$msgChevauchement = "refreshallringgroups: exécution précédente toujours en cours (chevauchement détecté) - on abandonne cette invocation.\n";
	echo $msgChevauchement;
	$dms->finirAvecEchec("Chevauchement détecté : une exécution précédente de refreshallringgroups était toujours en cours au déclenchement de la suivante (10 min).");
	die(2);
}
register_shutdown_function([$verrouRingGroup, 'liberer']);



$databasemanager = new DatabaseManager();
$languagemanager = new Langue();
$logmanager = new JournalDeTache("refreshallringgroups",-1);
$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog("Heure d'exécution : ".$now->format('d-m-Y H:i:s'));
$logmanager->writelinetolog("Nous sommes sur le serveur: $serverName");
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();
// Retourne les résultats où le service ringgroupupdate est activé
// Return results where ringgroupupdate is activated
$counter = 1;
$ctrAppelsAPIall = 0;
$bilan = new BilanPassage('refreshallringgroups');
echo "Refreshing all Ring Groups\n";
//On balaye la table userservice
if ($userservicetable === false) {
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
} else {
while ($row = $userservicetable->fetch_assoc()){
	if($row['ringgroupupdate'] == 1){
		// Une erreur (ex.: fichier de journal disparu pendant l'exécution, appel API en
		// échec) pour UN groupe de sonnerie ne doit jamais empêcher le traitement des
		// suivants dans cette même exécution - avant ce garde-fou, une erreur non rattrapée
		// ici arrêtait tout le script, laissant tous les groupes suivants dans la file sans
		// rafraîchissement, silencieusement.
		//
		// An error (e.g., a log file that disappeared mid-run, a failed API call) for ONE
		// ring group must never prevent the following ones from being processed in this same
		// run - before this guard, an uncaught error here aborted the whole script, leaving
		// every subsequent ring group in the queue unrefreshed, silently.
		// Remis à null pour que le catch n'écrive pas les mesures du comité précédent si
		// l'erreur survient avant la création du ClientVoipms de celui-ci.
		$voipms = null;
		// Une mise à jour de ce comité est en cours (page « Forcer » d'un admin ou du comité) : on le saute, le
		// passage suivant le reprendra. Ce n'est pas un échec.
		$verrouComite = VerrouExclusif::pourComite(intval($row['iduser']));
		if (!$verrouComite->acquerirSansAttendre()) {
			echo "\nrefreshallringgroups: le comité iduser=".$row['iduser']." est déjà en cours de mise à jour (page « Forcer ») : on le saute.\n";
			$logmanager->writelinetolog("iduser=".$row['iduser']." : déjà en cours de mise à jour, sauté pour ce passage.");
			continue;
		}
		try {
		$debutComite = microtime(true);
		$databasemanager = new DatabaseManager();
		$languagemanager->setLanguage($row['iduser']);
		//inscriptions au log
		$logmanager->writelinetolog(_("Ordre dans la file : ").strval($counter));
		$logmanager->incrementtab();
		$logmanager->writelinetolog("iduser=".$row['iduser']);

		//On obtient les infos du user (table userinfo)
		$userinformations = new InformationsUtilisateur($row['iduser'],True);

		$logmanager->writelinetolog("Username: ".$databasemanager->getuserbyid(intval($row['iduser']))['username']);
		$logmanager->writelinetolog("Voipusername: ".$userinformations->voipmsusername);
		$leUser = $userinformations->voipmsusername;
		echo "\nrefreshallringgroups: On traite le user: $leUser \n";
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$voipms = new ClientVoipms($userinformations);
		$calendarmanager = new TraitementComite($userinformations,$emailmanager,$voipms);
		$voipmsanswer = $calendarmanager->refreshringgroup($row['iduser']);
		if ($calendarmanager->groupeSonnerie()->aTente()) {
			$bilan->noterGroupe(intval($row['iduser']), $calendarmanager->groupeSonnerie()->echec());
		}
		$voipms->ecrireMesuresAuJournal($logmanager, microtime(true) - $debutComite);
		$logmanager->writelinetolog($voipmsanswer."\n");
		$counter++;

		$ctrAppelsAPIall += $voipms->nbRequetes();

		$logmanager->decrementtab();
		} catch (\Throwable $erreur) {
			$msgErreur = "Erreur lors du traitement du groupe de sonnerie iduser=".$row['iduser']." : ".$erreur->getMessage()."\n";
			echo $msgErreur;
			$logmanager->writelinetolog($msgErreur);
			if ($voipms !== null) {
				$voipms->ecrireMesuresAuJournal($logmanager, microtime(true) - $debutComite);
			}
			$logmanager->decrementtab();
			$counter++;
			$bilan->noterException(intval($row['iduser']), $erreur->getMessage());
		} finally {
			$verrouComite->liberer();
		}
	}
}
}
$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
$logmanager->writelinetolog(_("Temps d'exécution total : ").$execution_time."\n\n");

$msg = "\nNbre de requêtes vers voip.ms pour ce refreshallringgroups= $ctrAppelsAPIall\n";
echo $msg;
$logmanager->writelinetolog($msg);

$nbTraites = $counter - 1;
$resume = "comités traités=$nbTraites, en échec=".$bilan->nombreEnEchec()." (exceptions=".$bilan->nombreExceptions().", voip.ms=".$bilan->nombreEchecsVoipms()."), appels API=$ctrAppelsAPIall, durée={$execution_time}s";
echo $resume."\n";
$logmanager->writelinetolog($resume);

$logmanager->decrementtab();
$logmanager->trimandcloselog();
$fin = date('Y-m-d H:i:s');
echo "Fin du traitement : " . $fin ."\n";

// Tout ce qui suit vient après la boucle : les groupes de sonnerie sont traités, et une panne ici ne doit pas empêcher
// le signal de vie. Mémoire illisible : on ne sait pas si le passage précédent avait aussi échoué, et quand tous les
// comités échouent, une alerte de trop vaut mieux que le silence.
$passagesTousEnEchec = null;
try {
	$passagesTousEnEchec = $bilan->mettreAJourCompteurs(new MemoireAlertes(new DatabaseManager()), $nbTraites);
} catch (\Throwable $erreur) {
	echo "Mémoire des alertes indisponible : ".$erreur->getMessage()."\n";
}

if ($bilan->sontTousEnException($nbTraites)) {
	// Une exception pour tous les comités : probable bogue ou panne de la BD, sans attendre le passage suivant
	$dms->finirAvecEchec("Tous les $nbTraites comités ont levé une exception durant ce passage. $resume");
} elseif ($bilan->sontTousEnEchec($nbTraites) && $passagesTousEnEchec !== 1) {
	$dms->finirAvecEchec("Tous les $nbTraites comités sont en échec (voip.ms ?) pour au moins un 2e passage de suite. $resume");
} elseif ($bilan->sontTousEnEchec($nbTraites)) {
	// Un incident de 10 minutes chez voip.ms ne mérite pas d'alerte : on attend le passage suivant
	$dms->finirAvecSucces("Tous les $nbTraites comités sont en échec (voip.ms ?), 1er passage : pas d'alerte avant le 2e de suite. $resume");
} else {
	$dms->finirAvecSucces($resume);
}

// Quand tous les comités échouent, le signal de vie s'en charge : aucun courriel par comité. Sans signal de vie
// configuré sur ce serveur, le courriel est le seul avertissement.
if (!$bilan->sontTousEnEchec($nbTraites) || !$dms->estConfigure()) {
	envoyerAlertesDuPassage($bilan, $languagemanager);
}
