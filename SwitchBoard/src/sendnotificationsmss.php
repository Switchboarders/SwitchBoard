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

if(!isset($_SERVER['argv'][1])){
	if(!isset($_GET['mode'])){
		echo("Paramètre absent !");
		exit();
	}else{
		// Accès web protégé par requireCliOrCronKey() ci-dessus (clé CRON_WEB_KEY requise).
		// sendnotificationsmss.php?mode=hourbefore ou sendnotificationsmss.php?mode=daybefore
		$notificationtime = $_GET['mode'];
	}
	
}else{
	$notificationtime = $_SERVER['argv'][1];
	
}


$databasemanager = new DatabaseManager();
$languagemanager = new Langue();
$logmanager = new JournalDeTache("sendnotificationsmss".$notificationtime,-1);
$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog("Heure d'exécution : ".$now->format('d-m-Y H:i:s'));
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();
// Retourne les résultats où le service smsdaybefore ou smshourbefore est activé
// Ici il y a deux possibilité, soit on averti le répondant la veille ou 30 minutes à l'avance
// Return results where smsdaybefore or smshourbefore is activated
// Here there is two possibility : notify the day before or 30 minutes before

if($notificationtime == "hourbefore"){
	$userservicetableselectedcolumnname = 'smshourbefore';
}
else if($notificationtime == "daybefore"){
	$userservicetableselectedcolumnname = 'smsdaybefore';
}else{
	echo("Erreur de paramètre ! Utiliser hourbefore ou daybefore comme paramètre dans votre tâche cron.");
	exit();
	
}


echo "Sending Notifications for ".$notificationtime;
$counter = 1;
$bilan = new BilanPassage('sendnotificationsmss');
if ($userservicetable === false) {
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
} else {
while ($row = $userservicetable->fetch_assoc()){
	if($row[$userservicetableselectedcolumnname] == 1){
	// Une erreur pour UN utilisateur ne doit jamais empêcher le traitement des suivants
	// dans cette même exécution (voir la même garde dans refreshallringgroups.php).
	// Remis à null pour que le catch n'écrive pas les mesures de l'utilisateur précédent si
	// l'erreur survient avant la création du ClientVoipms de celui-ci.
	$voipms = null;
	try {
	$debutUtilisateur = microtime(true);
	$languagemanager->setLanguage($row['iduser']);
	$logmanager->writelinetolog(_("Ordre dans la file : ").strval($counter));
	$logmanager->incrementtab();
	$logmanager->writelinetolog("iduser=".$row['iduser']);
	$userinformations = new InformationsUtilisateur($iduser = $row['iduser'],$superprivilege=True);
	$nomUtilisateur = $databasemanager->getuserbyid($row['iduser'])['username'];
	$logmanager->writelinetolog("Username: ".$nomUtilisateur);
	$logmanager->writelinetolog("Voipusername: ".$userinformations->voipmsusername);
	echo "\nsendnotificationsmss: On traite l'utilisateur: $nomUtilisateur \n";
	$emailmanager = new Courriel($userinformations->validationfeedbackemail);
	$voipms = new ClientVoipms($userinformations);
	$calendarmanager = new TraitementComite($userinformations,$emailmanager,$voipms);
	$voipmsanswer = $calendarmanager->sendremindersms($row['iduser'],True,$notificationtime);
	$voipms->ecrireMesuresAuJournal($logmanager, microtime(true) - $debutUtilisateur);
	$logmanager->writelinetolog(_("Résultat de l'échange avec voip.ms : ").$voipmsanswer);
	$counter++;
	$logmanager->decrementtab();
	} catch (\Throwable $erreur) {
		$msgErreur = "Erreur lors du traitement de l'utilisateur iduser=".$row['iduser']." : ".$erreur->getMessage()."\n";
		echo $msgErreur;
		$logmanager->writelinetolog($msgErreur);
		if ($voipms !== null) {
			$voipms->ecrireMesuresAuJournal($logmanager, microtime(true) - $debutUtilisateur);
		}
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

// Ce cron n'a pas de signal de vie : le courriel limité part dans tous les cas
envoyerAlertesDuPassage($bilan, $languagemanager);

?>