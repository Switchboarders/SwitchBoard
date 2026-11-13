<?php 
function isValidUrl($url) {	
	return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
$dbmanager = new DatabaseManager();		
		if(!empty($_GET["iduser"])){
			$iduser = $_GET["iduser"];
		}else{
			$iduser = -1;
		}
		// Le droit sur cet iduser est déjà contrôlé par sa route (voir modules/portail/Routes.php)
		$currentuserid = ($iduser == -1 ? $dbmanager->getcurrentuserid() : $iduser);
		// isset (pas !empty) sur un champ toujours soumis par le formulaire : sbchatapitoken
		// est un champ masqué à saisie unique (vide sur presque toute soumission une fois un
		// jeton déjà enregistré) et sbchatcalendarurl peut légitimement être vide, donc se fier
		// à l'un ou l'autre pour détecter une soumission ignorait silencieusement les
		// changements sur les autres champs (ex. : Adresse du serveur SwitchBoardChat) dès que
		// ces deux-là étaient vides.
		if(isset($_POST['sbchatserveraddress'])){
			requireCsrf();
			commencerEnregistrement();
			$userinfo = $dbmanager->getuserinfobyid($currentuserid);
			// voip.ms : les trois champs, ou aucun (comité sans voip.ms : clavardage Web seulement)
			$sansVoipms = empty($userinfo['voipmsusername']) && empty($userinfo['voipmsapipassword']) && empty($userinfo['didnumber']);
			if(!$sansVoipms && (empty($userinfo['voipmsusername'])||empty($userinfo['voipmsapipassword'])||empty($userinfo['didnumber']))) {
				echo(_("<H2 style='color: red;'>SVP configurer un Nom d'utilisateur voip.ms, un mot de passe API pour voip.ms et un numéro DID pour cette utilisateur dans la section Informations du compte (ou laisser les trois vides pour un comité sans voip.ms)</H2>"));

			}else{
				if(isValidUrl($_POST['sbchatcalendarurl'])){
					$sbchatcalendarurl = $_POST['sbchatcalendarurl'] ;
				}else{
					if(!empty($_POST['sbchatcalendarurl']))
						echo "<h2 style='color: red;'>"._("Adresse URL non valide pour URL de l'Agenda !!")."</h2>";
					$sbchatcalendarurl = "";
				}
				
				if(isValidUrl($_POST['sbchatserveraddress'])){
					$sbchatserveraddress = rtrim($_POST['sbchatserveraddress'], "/") ;
				}else{
					if(!empty($_POST['sbchatserveraddress']))
						echo "<h2 style='color: red;'>"._("Adresse URL non valide pour Adresse du serveur SwitchBoardChat !!")."</h2>";
					$sbchatserveraddress = "";
				}
				if(isValidUrl($_POST['masurl'])){
					$masurl = rtrim($_POST['masurl'], "/") ;
				}else{
					if(!empty($_POST['masurl']))
						echo "<h2 style='color: red;'>"._("Adresse URL non valide pour Adresse du serveur MAS !!")."</h2>";
					$masurl = "";
				}
				
				// Champs secrets à saisie unique : un champ soumis vide conserve la valeur
				// déjà enregistrée plutôt que de l'effacer (le formulaire ne les préremplit
				// plus, pour ne pas les afficher en clair).
				$sbchatinfoexistant = $dbmanager->getSbchatinfobyId($currentuserid);
				$sbchatapitoken = $_POST['sbchatapitoken'] !== '' ? $_POST['sbchatapitoken'] : ($sbchatinfoexistant['sbchatapitoken'] ?? '');
				$masclientid = $_POST['masclientid'] !== '' ? $_POST['masclientid'] : ($sbchatinfoexistant['masclientid'] ?? '');
				$massecret = $_POST['massecret'] !== '' ? $_POST['massecret'] : ($sbchatinfoexistant['massecret'] ?? '');

				$infoarray = array("sbchatapitoken" => $sbchatapitoken , "sbchatcalendarurl" => $sbchatcalendarurl, "sbchatserveraddress" => $sbchatserveraddress, "masurl" => $masurl,"masclientid" => $masclientid,"massecret" => $massecret);
				$dbmanager->setsbchatinfobyuserid($iduser,$infoarray);
			}
			terminerEnregistrement();
		}

?>