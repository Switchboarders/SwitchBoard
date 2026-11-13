<?php
// Ce script peut être appelé en mode CLI sur le serveur au cas où il n'aurait pas passé au début
//  du mois comme prévu.
// On peut lui passer une intervalle de dates en paramètres ex:
//  /usr/bin/php -q /home/aaorg/public_html/systemetelephoniquevoip/genstatistics.php --start=2023-10-01 --end=2023-10-31
// On peut lui passer un user sous la forme --user=aideTelSagLac
//
// La logique par utilisateur (extraction, calcul, CSV, courriel) vit dans RapportStatistiques
// (modules/rapports/RapportStatistiques.php), réutilisée aussi par les pages web de consultation.

ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();

$databasemanager = new DatabaseManager();
$logmanager = new JournalDeTache("genstatistics",-1);
echo "\n";
$logmanager->writelinetolog(_("Début du programme genstatistics !"));

$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog(_("Heure d'exécution : ".$now->format('d-m-Y H:i:s')));
$logmanager->incrementtab();
$userservicetable = $databasemanager->getuserservicetable();

///////////////// Etablir les dates d'extraction  ///////////////////////////////////////////////////////
//Détecter la présence d'une intervalle de dates passées en paramètres
// Ex: sudo -u www-data php genstatistics.php --start=2023-10-01 --end=2023-10-31
$startDate = null;
$endDate = null;
$userAtraiter = null;

//Detecter la presence de dates passees en parametres
foreach ($argv as $arg):
		// Détecter l'argument --start
		if (strpos($arg, '--start=') === 0):
				$startDate = substr($arg, 8); // Récupère la valeur après "--start="
		 // Détecter l'argument --end
		elseif (strpos($arg, '--end=') === 0):
				 $endDate = substr($arg, 6); // Récupère la valeur après "--end="
		elseif (strpos($arg, '--user=') === 0):
				 $userAtraiter = substr($arg, 7); // Récupère la valeur après "--user="
		endif;
endforeach;
echo "userAtraiter = $userAtraiter\n";

$format = 'Y-m-d';
If ($startDate !== null):
	// Création de l'objet DateTime à partir du format spécifié
	$dateConstituee = DateTime::createFromFormat($format, $startDate);
	// On vérifie que la création a réussi ET que la date formatée correspond exactement à la chaîne donnée
	if (!$dateConstituee or $dateConstituee->format($format) !== $startDate):
		die("Erreur : Date de début invalide.\n");
	endif;
endif;

If ($endDate !== null):
	// Création de l'objet DateTime à partir du format spécifié
	$dateConstituee = DateTime::createFromFormat($format, $endDate);
	// On vérifie que la création a réussi ET que la date formatée correspond exactement à la chaîne donnée
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
	//Utiliser les dates recues en parametres
	$dateFrom = $startDate;
	$dateTo = $endDate;
endif;

$logmanager->writelinetolog(_("Premier jour d'extraction des stats : ").$dateFrom);
$logmanager->writelinetolog(_("Dernier jour d'extraction des stats : ").$dateTo);

$counter = 1;
echo "\n";

//////////////// Boucle principale ///////////////////////////////////////////////////////////
//Balayer la table userservice et traiter tous les comités abonnés aux service de statistiques
//seulement si la table n'est pas vide
$statsreportmanager = new RapportStatistiques();
if ($userservicetable == false):
	$logmanager->writelinetolog(_("Table userservice vide, on ne traite rien"));
else:
	while ($row = $userservicetable->fetch_assoc()):
		//si un user a été passé en param et que nous sommes en train de traiter celui-ci, alors on traite ce user
		//si le service "sendstats" est activé, on traite ce comité
		$iduser = intval($row['iduser']);
		$username = $databasemanager->getuserbyid($iduser)['username'];
		echo "username de la BD= $username\n";
		//Si --user= a été passé, on traite seulement ce comité (peu importe son sendstats),
		//au lieu du traitement normal de tous les comités abonnés au service de statistiques.
		if (isset($userAtraiter)):
			$doitTraiterCeComite = ($username === $userAtraiter);
		else:
			$doitTraiterCeComite = ($row['sendstats'] == 1);
		endif;
		if ($doitTraiterCeComite):
			$logmanager->writelinetolog(_("Traitement du compte voip : ").strval($counter));
			$logmanager->incrementtab();
			$logmanager->writelinetolog("iduser=".$iduser);

			$statsreportmanager->processUser($iduser, $dateFrom, $dateTo, true, $logmanager);

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
