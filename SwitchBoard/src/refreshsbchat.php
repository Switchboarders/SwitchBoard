<?php
ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();
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
$dms = new SignalDeVie('sbchat');
$dms->armerDetectionEchec();

// Détection de chevauchement : voir le même garde-fou dans refreshallringgroups.php.
$verrouSbchat = new VerrouExclusif('refreshsbchat');
if (!$verrouSbchat->acquerirSansAttendre()) {
	$msgChevauchement = "refreshsbchat: exécution précédente toujours en cours (chevauchement détecté) - on abandonne cette invocation.\n";
	echo $msgChevauchement;
	$dms->finirAvecEchec("Chevauchement détecté : une exécution précédente de refreshsbchat était toujours en cours au déclenchement de la suivante (10 min).");
	die(2);
}
register_shutdown_function([$verrouSbchat, 'liberer']);


$databasemanager = new DatabaseManager();
$languagemanager = new Langue();
$logmanager = new JournalDeTache("refreshsbchat",-1);
$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog("Heure d'exécution : ".$now->format('d-m-Y H:i:s'));
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();
// Retourne les résultats où le service ringgroupupdate est activé
// Return results where ringgroupupdate is activated
$counter = 1;
$bilan = new BilanPassage('refreshsbchat');
echo "Refreshing all SwitchBoard Chats\n";
//On balaye la table userservice
if ($userservicetable === false) {
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
} else {
while ($row = $userservicetable->fetch_assoc()){
	if($row['sbchatupdate'] == 1){
	// Une erreur pour UN utilisateur ne doit jamais empêcher le traitement des suivants
	// dans cette même exécution (voir la même garde dans refreshallringgroups.php).
	try {
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
	echo "\nrefreshsbchat: On traite le user: $leUser \n";
	$emailmanager = new Courriel($userinformations->validationfeedbackemail);
	$voipms = new ClientVoipms($userinformations);
	$calendarmanager = new TraitementComite($userinformations,$emailmanager,$voipms);
	$sbchatanswer = $calendarmanager->refreshsbchat($row['iduser']);
	$logmanager->writelinetolog($sbchatanswer."\n");
	$counter++;
	$logmanager->decrementtab();
	} catch (\Throwable $erreur) {
		$msgErreur = "Erreur lors du traitement de l'utilisateur iduser=".$row['iduser']." : ".$erreur->getMessage()."\n";
		echo $msgErreur;
		$logmanager->writelinetolog($msgErreur);
		$logmanager->decrementtab();
		$counter++;
		$bilan->noterException(intval($row['iduser']), $erreur->getMessage());
	}
	}
}
}
$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
$logmanager->writelinetolog(_("Temps d'exécution total : ").$execution_time."\n\n");
$logmanager->decrementtab();
$logmanager->trimandcloselog();

$nbTraites = $counter - 1;
if ($bilan->sontTousEnEchec($nbTraites)) {
	$dms->finirAvecEchec("Tous les $nbTraites utilisateurs ont échoué durant cette exécution - probable panne systémique plutôt qu'un problème isolé.");
} else {
	$dms->finirAvecSucces("utilisateurs traités=$nbTraites, erreurs=".$bilan->nombreExceptions().", durée={$execution_time}s");
}
// Quand tous les utilisateurs échouent, le signal de vie s'en charge : aucun courriel par comité. Sans signal de vie
// configuré sur ce serveur, le courriel est le seul avertissement.
if (!$bilan->sontTousEnEchec($nbTraites) || !$dms->estConfigure()) {
	envoyerAlertesDuPassage($bilan, $languagemanager);
}
