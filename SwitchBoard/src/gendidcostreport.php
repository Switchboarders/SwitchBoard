<?php
// Rapport mensuel de répartition des coûts par DID, envoyé par courriel au même échéancier que
// genstatistics.php (même déclencheur : userservice.sendstats), mais dans un courriel distinct.
// Ce script peut être appelé en mode CLI sur le serveur avec les mêmes paramètres que genstatistics.php :
//  /usr/bin/php -q /home/aaorg/public_html/systemetelephoniquevoip/src/gendidcostreport.php --start=2023-10-01 --end=2023-10-31
// On peut lui passer un user sous la forme --user=aideTelSagLac
//
// La logique par utilisateur (extraction, calcul, CSV, courriel) vit dans RapportCoutsDid
// (modules/rapports/RapportCoutsDid.php), réutilisée aussi par les pages web de consultation.

ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();

$databasemanager = new DatabaseManager();
$logmanager = new JournalDeTache("gendidcostreport",-1);
echo "\n";
$logmanager->writelinetolog(_("Début du programme gendidcostreport !"));

$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog(_("Heure d'exécution : ".$now->format('d-m-Y H:i:s')));
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();

///////////////// Etablir les dates d'extraction  ///////////////////////////////////////////////////////
//Détecter la présence d'une intervalle de dates passées en paramètres (identique à genstatistics.php)
$startDate = null;
$endDate = null;
$userAtraiter = null;

foreach ($argv as $arg):
		if (strpos($arg, '--start=') === 0):
				$startDate = substr($arg, 8);
		elseif (strpos($arg, '--end=') === 0):
				 $endDate = substr($arg, 6);
		elseif (strpos($arg, '--user=') === 0):
				 $userAtraiter = substr($arg, 7);
		endif;
endforeach;
echo "userAtraiter = $userAtraiter\n";

$format = 'Y-m-d';
If ($startDate !== null):
	$dateConstituee = DateTime::createFromFormat($format, $startDate);
	if (!$dateConstituee or $dateConstituee->format($format) !== $startDate):
		die("Erreur : Date de début invalide.\n");
	endif;
endif;

If ($endDate !== null):
	$dateConstituee = DateTime::createFromFormat($format, $endDate);
	if (!$dateConstituee or $dateConstituee->format($format) !== $endDate):
		die("Erreur : Date de fin invalide.\n");
	endif;
endif;

//Établir les dateFrom et dateTo lorsque pas recues en param
if ($startDate === null):
	//Premier et dernier jour du mois précédent
	$dateFrom = date('Y-m-01', strtotime('first day of last month'));
	$dateTo = date('Y-m-t', strtotime('last day of last month'));
else:
	$dateFrom = $startDate;
	$dateTo = $endDate;
endif;

$logmanager->writelinetolog(_("Premier jour d'extraction des coûts : ").$dateFrom);
$logmanager->writelinetolog(_("Dernier jour d'extraction des coûts : ").$dateTo);

$counter = 1;
echo "\n";

//////////////// Boucle principale ///////////////////////////////////////////////////////////
//Balayer la table userservice et traiter tous les comités abonnés aux service de statistiques
//(on réutilise sendstats : ce rapport est destiné aux mêmes destinataires que les statistiques)
$didcostreportmanager = new RapportCoutsDid();
if ($userservicetable == false):
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
else:
	while ($row = $userservicetable->fetch_assoc()):
		$iduser = intval($row['iduser']);
		$username = $databasemanager->getuserbyid($iduser)['username'];
		echo "username de la BD= $username\n";
		if ( (isset($userAtraiter) && ($username === $userAtraiter)) || ($row['sendstats'] == 1) ):
			$logmanager->writelinetolog(_("Traitement du compte voip : ").strval($counter));
			$logmanager->incrementtab();
			$logmanager->writelinetolog("iduser=".$iduser);

			$didcostreportmanager->processUser($iduser, $dateFrom, $dateTo, true, $logmanager);

			echo "\n";
			$counter++;
			$logmanager->decrementtab();
		endif; //if sendstats = 1
	endwhile;
endif;

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
$execTimeMsg = _("Temps d'exécution total : ");
$logmanager->writelinetolog($execTimeMsg.$execution_time);
echo $execTimeMsg.$execution_time;
$logmanager->decrementtab();

$finMsg = _("Fin du programme !");
$logmanager->writelinetolog($finMsg);
echo $finMsg;
$logmanager->trimandcloselog();

?>
