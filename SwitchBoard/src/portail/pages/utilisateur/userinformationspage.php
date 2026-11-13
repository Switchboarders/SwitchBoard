<?php 
function validatePhoneNumber($phonenumber){
$phonenumber = preg_replace('/[^0-9.]+/', '', $phonenumber);
$phonenumber = ltrim($phonenumber, '1');
if (strlen($phonenumber) == 10) return $phonenumber;

return false;
}
function isValidEmail($email) {
    // Returns the filtered data, or false if the filter fails
    // Using !== false to ensure a clear boolean return value
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}
// Le courriel de notification/validation accepte plusieurs adresses séparées par des
// virgules (ex.: "comite@exemple.org, region@exemple.org") - mail() accepte nativement une
// liste séparée par virgules dans le champ "to", chaque adresse individuelle doit
// simplement être valide. Ne pas utiliser pour voipmsusername (une seule adresse : le login
// du compte voip.ms).
function isValidEmailList($emails) {
	if ($emails === '') {
		return false;
	}
	foreach (explode(',', $emails) as $adresse) {
		if (filter_var(trim($adresse), FILTER_VALIDATE_EMAIL) === false) {
			return false;
		}
	}
	return true;
}
function isValidUrl($url) {	
	return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

$this->webpageparent->initiatecalendar(); 
	
		
		if(!empty($_GET["iduser"])){
			$iduser = $_GET["iduser"];
		}else{
			$iduser = -1;
		}
		$currentuserid = ($iduser == -1 ? $this->webpageparent->databasemanager->getcurrentuserid() : $iduser);
		$this->webpageparent->databasemanager->filluserinformationsfromdb($this->webpageparent->userinformations,$iduser);
		$this->webpageparent->tokenaccess->createTokenIfNotExist($currentuserid);
		$enregistre = false;
		if(!empty($_POST['voipmsusername']) or !empty($_POST['voipmsapipassword']) or !empty($_POST['calendarurl']) or !empty($_POST['calendartype']) or !empty($_POST['validationfeedbackemail']) or !empty($_POST['ringgroupname'])or !empty($_POST['didnumber']) or !empty($_POST['language'])){
			requireCsrf();
			commencerEnregistrement();
			$enregistre = true;
			if (isValidEmail($_POST['voipmsusername'])){
			$this->webpageparent->userinformations->voipmsusername = $_POST['voipmsusername'];
			}
			else{
				if(!empty($_POST['voipmsusername'])){
					echo "<h2 style='color: red;'>"._("Adresse courriel non valide pour Nom d'utilisateur voip.ms !!")."</h2>";
				}
				$this->webpageparent->userinformations->voipmsusername = "";

			}
			// Champ secret à saisie unique : soumis vide, on conserve le mot de passe API
			// voip.ms déjà en base (chargé plus haut par filluserinformationsfromdb) au
			// lieu de l'effacer, puisque le formulaire ne le préremplit plus en clair.
			if ($_POST['voipmsapipassword'] !== ''){
				$this->webpageparent->userinformations->voipmsapipassword = $_POST['voipmsapipassword'];
			}
			if(isValidUrl($_POST['calendarurl'])){
				$this->webpageparent->userinformations->calendarurl = $_POST['calendarurl'];
			}else{
				if(!empty($_POST['calendarurl'])){
					echo "<h2 style='color: red;'>"._("URL non valide pour URL de l'agenda !!")."</h2>";	
				}				
				$this->webpageparent->userinformations->calendarurl = "";
			}
			if (in_array($_POST['calendartype'] ?? '', ['secreturl', 'caldav'], true)){
				$this->webpageparent->userinformations->calendartype = $_POST['calendartype'];
			}else{
				echo "<h2 style='color: red;'>"._("Type de calendrier invalide !!")."</h2>";
				$this->webpageparent->userinformations->calendartype = 'secreturl';
			}
			$this->webpageparent->userinformations->calendarusername = trim($_POST['calendarusername'] ?? '');
			// Champ secret à saisie unique : mêmes règles que voipmsapipassword ci-dessus.
			if (($_POST['calendarpassword'] ?? '') !== ''){
				$this->webpageparent->userinformations->calendarpassword = $_POST['calendarpassword'];
			}
			if ($this->webpageparent->userinformations->calendartype === 'caldav' && (empty($this->webpageparent->userinformations->calendarusername) || empty($this->webpageparent->userinformations->calendarpassword))){
				echo "<h2 style='color: red;'>"._("Nom d'utilisateur et mot de passe CalDAV requis lorsque le type de calendrier est CalDAV !!")."</h2>";
				$this->webpageparent->userinformations->calendartype = 'secreturl';
			}
			if (isValidEmailList($_POST['validationfeedbackemail'])){
				$adressesNettoyees = array_map('trim', explode(',', $_POST['validationfeedbackemail']));
				$this->webpageparent->userinformations->validationfeedbackemail = implode(', ', $adressesNettoyees);
			}
			else{
				if(!empty($_POST['validationfeedbackemail'])){
					echo "<h2 style='color: red;'>"._("Adresse courriel non valide pour Courriel de notification/validation !!")."</h2>";
				}					
				$this->webpageparent->userinformations->validationfeedbackemail = "";

			}
			$this->webpageparent->userinformations->ringgroupname = mb_substr($_POST['ringgroupname'], 0, 15);
			$didnumber = validatePhoneNumber($_POST['didnumber']);
			if($didnumber){
				$this->webpageparent->userinformations->didnumber = $didnumber;
			}else{
				if(!empty($_POST['didnumber'])){
					echo "<h2 style='color: red;'>"._("DID non valide !!")."</h2>";
				}
				$this->webpageparent->userinformations->didnumber = "";

			}
			// Durée de sonnerie et « Faire le 1 » du comité : vide = valeur par défaut
			$ringtime = trim($_POST['ringtime'] ?? '');
			if ($ringtime === '') {
				$this->webpageparent->userinformations->ringtime = null;
			} elseif (ctype_digit($ringtime) && intval($ringtime) >= GroupeSonnerie::DUREE_SONNERIE_MIN && intval($ringtime) <= GroupeSonnerie::DUREE_SONNERIE_MAX) {
				$this->webpageparent->userinformations->ringtime = intval($ringtime);
			} else {
				echo "<h2 style='color: red;'>"._("Durée de sonnerie non valide (1 à 60 secondes) : valeur par défaut utilisée !!")."</h2>";
				$this->webpageparent->userinformations->ringtime = null;
			}
			$pressone = $_POST['pressone'] ?? '';
			$this->webpageparent->userinformations->pressone = in_array($pressone, ['0', '1'], true) ? intval($pressone) : null;
			$this->webpageparent->userinformations->language = $_POST['language'];
			$this->webpageparent->databasemanager->filldbfromuserinformations($this->webpageparent->userinformations,$iduser);
		
		}
		// Update sb_chat api using sbchat token, if it does not exist, it must be created on the 
		// SBChat info page. This is only to update this user name or password or did
		$sbchatinfo = $this->webpageparent->databasemanager->getSbchatinfobyId($currentuserid);
		if(!empty($sbchatinfo['sbchatapitoken']) and !empty($_POST['voipmsusername']) and !empty($this->webpageparent->userinformations->voipmsapipassword) and !empty($_POST['didnumber'])){
			$sbchat = new ClientSwitchBoardChat($sbchatinfo['sbchatserveraddress']);
			$sbchat->update_call_center($sbchatinfo['sbchatapitoken'],$_POST['voipmsusername'],$this->webpageparent->userinformations->voipmsapipassword,$_POST['didnumber']);
		}
		if ($enregistre) {
			terminerEnregistrement();
		}

		$this->webpageparent->databasemanager->filluserinformationsfromdb($this->webpageparent->userinformations,$currentuserid);

?>