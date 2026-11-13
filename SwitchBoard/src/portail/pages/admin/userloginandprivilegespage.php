<?php

$this->title = _("Changer le mot de passe d'un utilisateur et/ou son niveau d'accès.");
$this->description = _("Laisser le mot de passe vide pour changer uniquement le niveau d'accès");
$this->usage = "changeuserloginandprivileges";
// privilegelevel est toujours soumis par ce formulaire (champ <select>) : sa présence
// signale une vraie soumission (par opposition au simple affichage du formulaire en GET).
if(isset($_POST['privilegelevel'])){
	requireCsrf();
	commencerEnregistrement();
}
// Mise à jour du mot de passe
if(!empty($_POST['createormodifypassword'])  && $_POST['createormodifypassword']!="Passwordnotfilled"){
	$this->iduser = intval($_GET['iduser']);
	$this->password = password_hash($_POST['createormodifypassword'], PASSWORD_DEFAULT);
	$this->webpageparent->databasemanager->updateuserpassword($this->iduser,$this->password);
	
}
// Mise à jour du niveau de privilegelevel
if(isset($_POST['privilegelevel'])){
	
	$this->iduser = intval($_GET['iduser']);
	$this->privilegelevel = intval($_POST['privilegelevel']);
	$this->webpageparent->databasemanager->updateuserprivilegelevel($this->iduser,$this->privilegelevel);
	// Redirection vers la même page (#77)
	terminerEnregistrement();

}


?>