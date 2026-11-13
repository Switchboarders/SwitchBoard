<?php
// Ce script sert à produire les stats de la ligne provinciale pour une plage de dates donnée !
// ex:
//  /usr/bin/php -q /home/aaorg/public_html/systemetelephoniquevoip/genstatsLAA.php --start=2023-10-01 --end=2023-10-31
ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();

//Lire un googlesheet recu en param, y extraire les villes et les no-telephone et retourner un array de ces infos
function lireSheetGoogle($url) {
		$rows = [];
		if (($handle = fopen($url, 'r')) !== FALSE):
				$header = fgetcsv($handle, 1000, ',');
				while (($data = fgetcsv($handle, 1000, ',')) !== FALSE):
					$rowAssoc = array_combine($header, $data);
					$telBrut = $rowAssoc['Telephone'] ?? '';
					$telNettoye = preg_replace('/\D/', '', $telBrut);
					if (substr($telNettoye, 0, 1) === '1'):
							$telNettoye = substr($telNettoye, 1);
					endif;

					$rows[] = [
							'ville' => $rowAssoc['Villes'] ?? '',
							'telephone' => $telNettoye
					];
				endwhile;
				fclose($handle);
		endif;
		return $rows;
}


$databasemanager = new DatabaseManager();
$logmanager = new JournalDeTache("genstatsLAA",-1);
echo "\n";
$logmanager->writelinetolog(_("Début du programme genstatsLAA !"));

$time_start = microtime(true);
$now = new DateTime();
$logmanager->writelinetolog(_("Heure d'exécution : ".$now->format('Y-m-d H:i:s')));
$logmanager->incrementtab();

//Ce script s'exécute sans session utilisateur : on résout directement l'iduser du
//compte de service LigneProvinciale.
$iduser = $databasemanager->getiduserbyusername("LigneProvinciale");

//Détecter la présence d'une intervalle de dates passées en paramètres
// Ex: sudo -u www-data php genstatistics.php --start=2023-10-01 --end=2023-10-31
$startDate = null;
$endDate = null;

//Parcourir les arguments
foreach ($argv as $arg):
	// Détecter l'argument --start
	if (strpos($arg, '--start=') === 0):
			$startDate = substr($arg, 8); // Récupère la valeur après "--start="
		// Détecter l'argument --end
	elseif (strpos($arg, '--end=') === 0):
				$endDate = substr($arg, 6); // Récupère la valeur après "--end="
	endif;
endforeach;

//Validation des dates 
if ($startDate && !strtotime($startDate)):
	die("Erreur : Date de début invalide.\n");
endif;
if ($endDate && !strtotime($endDate)):
	die("Erreur : Date de fin invalide.\n");
endif;

//Établir les dateFrom et dateTo
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

$logmanager->writelinetolog(_("Traitement du compte voip : ").strval($counter));
$logmanager->incrementtab();
$logmanager->writelinetolog("iduser=".$iduser);
$databasemanager = new DatabaseManager();

//Obtenir les infos du user (table userinfo) (un user = un compte voip)
$userinformations = new InformationsUtilisateur($iduser,True);
$username = $databasemanager->getuserbyid(intval($iduser))['username'];
$logmanager->writelinetolog("Voipusername: ".$userinformations->voipmsusername);
$logmanager->writelinetolog(_("Compte Switchboard: ").$username);

//mon email pour debugger
$emailmanager = new Courriel($userinformations->validationfeedbackemail);

//On insère tout de suite la formule de politesse du courriel...
$emailmanager->addlinetomessagebody(_("Bonjour ").$username.",\n");
$emailmanager->addlinetomessagebody(_("Voici les statistiques pour la période mentionnée:"));
//créer un objet $voipms avec notamment les dates from et to
$voipms = new ClientVoipms($userinformations, $dateFrom,$dateTo);

///////////////// CDR ////////////////////////////////////////////////////////////////////////////
//Initialisation des compteurs d'appels
$ctrAppels = 0;
$ctrAnswered = 0;
$ctrNoAnswer = 0;
$ctrBusy = 0;
$ctrFailed = 0;
$ctrAutres = 0;

//extraire le CDR pour le voip traité, $cdrArr est un array
//
$cdrArr = $voipms->lireAppels(true);
if (is_string($cdrArr)):
	// probleme avec getCDR
	echo "probleme avec getCDR\n";
	$msgLogEcho = _("Retour de getCdr: ").$cdrArr;
	$logmanager->writelinetolog($msgLogEcho);
	$emailmanager->addlinetomessagebody($msgLogEcho);
else:
	//Tout va bien, on continue
	//trier le array cdrArr (sur la destination)
	echo "tri de cdrArr\n";
	usort($cdrArr, function($a, $b) {
					return strcmp($a['destination'], $b['destination']);
			});

	//compter les appels répondus, non-répondus, occupés, echoués...
	echo "\n";

	//Liste des villes et numéros d'aide par région
	$url87 = 'https://docs.google.com/spreadsheets/d/1T9q-MoFTEyxEX9n5LOFJmpuqBvPBIE43JWkaMesjSF4/export?format=csv';
	$url88 = 'https://docs.google.com/spreadsheets/d/18_oZHHxIYrUj0BRWrJTpyMwPf93mUm2ziWWCuHaApPk/export?format=csv';
	$url89 = 'https://docs.google.com/spreadsheets/d/1mYyMUrc8O4gvnl82Jtwi876Jdc0BRBMJiEA32p07B_Q/export?format=csv';
	$url90 = 'https://docs.google.com/spreadsheets/d/1s5jtXRrxtLrDfoQznEnlQE6rMNnE3LUW8YfUy2_aJVg/export?format=csv';

	//Créer 4 tableaux pour contenir ces listes
	$donneesParRegion = [];
	$donneesParRegion['87'] = lireSheetGoogle($url87);
	$donneesParRegion['88'] = lireSheetGoogle($url88);
	$donneesParRegion['89'] = lireSheetGoogle($url89);
	$donneesParRegion['90'] = lireSheetGoogle($url90);

	// Construire la table numéro → région
	$numeroRegionMap = [];
	foreach ($donneesParRegion as $region => $liste):
		foreach ($liste as $ligne):
			$telephone = $ligne['telephone'];
			//Ne pas écraser une région déjà attribuée à ce numéro : en cas de conflit
			//(ex.: le 9230 de Montréal réutilisé pour Ahuntsic/Rivière-des-Prairies dans
			//le sheet de la région 90), la première région à revendiquer le numéro le
			//garde — ici, 87 est toujours lu avant 90.
			if ($telephone && !isset($numeroRegionMap[$telephone])):
					$numeroRegionMap[$telephone] = $region;
			endif;
		endforeach;
	endforeach;
	// Traiter mon $cdrArr ici !!!  ///////////////////////////////////////
	// Initialiser les compteurs par région
	$compteursRegion = [];

	foreach ($cdrArr as $appel):
		$ctrAppels++;
		// Nettoyer le no de telephone (champ destination)
		$telNettoye = preg_replace('/\D/', '', $appel['destination']);
		if (substr($telNettoye, 0, 1) === '1'):
				$telNettoye = substr($telNettoye, 1);
		endif;
		$dest = $telNettoye;
		//obtient la région pour le numero destinataire sinon affiche ce numero destinataire
		$region = $numeroRegionMap[$dest] ?? $dest;
		$disp = $appel['disposition'];

		if (!isset($compteursRegion[$region])):
			$compteursRegion[$region] = [];
		endif;

		// Récupérer la valeur 'total' (0 si absent ou non numérique)
		$total = isset($appel['total']) ? floatval($appel['total']) : 0;

		// Initialiser la somme 'total' pour cette région si nécessaire
		if (!isset($compteursRegion[$region]['somme_total'])):
				$compteursRegion[$region]['somme_total'] = 0;
		endif;

		// Cumuler la valeur du champ 'total'
		$compteursRegion[$region]['somme_total'] += $total;

		if (!isset($compteursRegion[$region][$disp])):
			// Initialiser ce statut à 0 quand on le rencontre pour la première fois
			$compteursRegion[$region][$disp] = 0;
		endif;

		$compteursRegion[$region][$disp]++;

	endforeach;

	// Arrondir les compteurs de couts
	foreach ($compteursRegion as &$compteur):
		if (isset($compteur['somme_total'])):
				$compteur['somme_total'] = round($compteur['somme_total'], 2);
		endif;
	endforeach;

	// Afficher les compteurs par région
	$msgLogEcho = _(" ");
	$logmanager->writelinetolog($msgLogEcho);
	$emailmanager->addlinetomessagebody($msgLogEcho);
	echo $msgLogEcho."\n";

	$msgLogEcho = _("Nombre d'appels transférés: ").$ctrAppels;
	$logmanager->writelinetolog($msgLogEcho);
	$emailmanager->addlinetomessagebody($msgLogEcho);
	echo $msgLogEcho."\n";

	// Exemple : $compteursRegion['87'] = ['ANSWERED' => 10, 'BUSY' => 2, 'FAILED' => 1, ...];
	// Parcourir chaque région et ses compteurs
	foreach ($compteursRegion as $region => $compteurs):
		$msgLogEcho = _(" ");
		$logmanager->writelinetolog($msgLogEcho);
		$emailmanager->addlinetomessagebody($msgLogEcho);
		echo $msgLogEcho."\n";

		$msgLogEcho = _("Compteurs pour la région ") . $region;
		$logmanager->writelinetolog($msgLogEcho);
		$emailmanager->addlinetomessagebody($msgLogEcho);
		echo $msgLogEcho."\n";

		// Parcourir chaque statut et son compteur
		foreach ($compteurs as $statut => $valeur):
			if ($statut === 'somme_total'):
				// Affichage spécifique pour la somme totale
				$texte = _("Total en $ US pour la région: ");
				$msgLogEcho = $texte . $valeur;
			else:
				// Message générique pour les autres statuts
				$texte = _("Nombre d'appels ") . $statut;
				$msgLogEcho = $texte . ": " . $valeur;
			endif;

			$logmanager->writelinetolog($msgLogEcho);
			$emailmanager->addlinetomessagebody($msgLogEcho);
			echo $msgLogEcho."\n";
		endforeach;
	endforeach;

endif; //if is_string($cdrArr)

///////////////// Solde ////////////////////////////////////////////////////////////////////////////
//extraire le Solde au compte pour le voip traité, getBalance est un array
$balanceArr = $voipms->lireSolde();
if (is_string($balanceArr)):
	$msgLogEcho = _("Retour de getBalance: ").$balanceArr;
	$logmanager->writelinetolog($msgLogEcho);
	$emailmanager->addlinetomessagebody($msgLogEcho);
	echo $msgLogEcho."\n";
else:
	//Écrire les compteurs + ajout au courriel
	foreach ($balanceArr as $kk => $vv):
		$msgLogEcho = _(" ");
		$logmanager->writelinetolog($msgLogEcho);
		$emailmanager->addlinetomessagebody($msgLogEcho);
		echo $msgLogEcho."\n";

		$msgLogEcho = _("Solde au compte voip.ms: ").$vv;
		$logmanager->writelinetolog($msgLogEcho);
		$emailmanager->addlinetomessagebody($msgLogEcho);
		echo $msgLogEcho."\n";
	endforeach;
endif;
$msgLogEcho = _(" ");
$logmanager->writelinetolog($msgLogEcho);
$emailmanager->addlinetomessagebody($msgLogEcho);
echo $msgLogEcho."\n";


///////////////// Courriel ////////////////////////////////////////////////////////////////////////////
//Envoyer les statistiques par email à l'adresse de validationfeedbackemail
//On insère la formule de politesse de fin de courriel et on envoie le courriel
$emailmanager->addlinetomessagebody("\n"._("Passez une bonne journée !"));
$emailmanager->addlinetomessagebody("\n"._("Votre Comité de l'aide téléphonique provincial !"));
$emailmanager->sendemail(_("Statistiques des appels du ").$dateFrom._(" au ").$dateTo._(" pour l'utilisateur: ").$username);

$counter++;
$logmanager->decrementtab();

$time_end = microtime(true);
$execution_time = ($time_end - $time_start);
$logmanager->writelinetolog(_("Temps d'exécution total : ").$execution_time);
$logmanager->decrementtab();

$logmanager->writelinetolog(_("Fin du programme !"));
$logmanager->trimandcloselog();
