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
$logmanager = new JournalDeTache("sendnotificationsbchat".$notificationtime,-1);
$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog("Heure d'exécution : ".$now->format('d-m-Y H:i:s'));
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();
// Retourne les résultats où le service smsdaybefore ou smshourbefore est activé
// Ici il y a deux possibilité, soit on averti le répondant la veille ou 30 minutes à l'avance
// Return results where smsdaybefore or smshourbefore is activated
// Here there is two possibility : notify the day before or 30 minutes before

if(!($notificationtime == "hourbefore" || $notificationtime == "daybefore")){
	echo("Erreur de paramètre ! Utiliser hourbefore ou daybefore comme paramètre dans votre tâche cron.");
	exit();	
}

echo "Sending Notifications for ".$notificationtime."\n";
$counter = 1;
$bilan = new BilanPassage('sendnotificationsbchat');
if ($userservicetable === false) {
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
} else {
while ($row = $userservicetable->fetch_assoc()){
	if($row['sbchatupdate'] == 1){
		// Une erreur pour UN utilisateur ne doit jamais empêcher le traitement des suivants
		// dans cette même exécution (voir la même garde dans refreshallringgroups.php).
		try {
		$languagemanager->setLanguage($row['iduser']);
		$logmanager->writelinetolog(_("Ordre dans la file : ").strval($counter));
		$logmanager->incrementtab();
		$logmanager->writelinetolog("iduser=".$row['iduser']."\n");
		$userinformations = new InformationsUtilisateur($iduser = $row['iduser'],$superprivilege=True);
		$nomUtilisateur = $databasemanager->getuserbyid($row['iduser'])['username'];
		$logmanager->writelinetolog("Username: ".$nomUtilisateur."\n");
		$logmanager->writelinetolog("Voipusername: ".$userinformations->voipmsusername);
		echo "\nsendnotificationsbchat: On traite l'utilisateur: $nomUtilisateur \n";
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$voipms = new ClientVoipms($userinformations);
		$calendarmanager = new TraitementComite($userinformations,$emailmanager,$voipms);
		$sbchatanswer = $calendarmanager->sendremindersbchat($row['iduser'],$notificationtime);
		$logmanager->writelinetolog($sbchatanswer."\n");
		$logmanager->writelinetolog(_("Résultat de l'échange avec sbchat : ").$sbchatanswer);
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

// Ce cron n'a pas de signal de vie : le courriel limité part dans tous les cas
envoyerAlertesDuPassage($bilan, $languagemanager);

?>