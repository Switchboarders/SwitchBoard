<?php
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

// Quel utilisateur la page montre, et le mot de passe affiché (rien d'écrit à l'écran ici)
if(!isset($_GET['iduser'])){
	$this->username = "";
	$this->password = "";
	$this->privilegelevel = 10;
	
}else{
	$this->iduser = intval($_GET['iduser']);
	if($this->webpageparent->databasemanager->getcurrentuserprivilegelevel() < 1 || $this->iduser == $this->webpageparent->databasemanager->getcurrentuserid()){
		$this->user = $this->webpageparent->databasemanager->getuserbyid($this->iduser);
		$this->username = $this->user['username'];
		$this->privilegelevel = $this->user['privilegelevel'];
	}
}

if(isset($this->usage) && $this->usage == "changeuserloginandprivileges"){
	$this->passwordvalue = "Passwordnotfilled";
}else {
	$this->passwordvalue = "";
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/usermodificationform/usermodificationform.vue.php';
