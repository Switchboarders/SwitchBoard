<?php
$this->title = _("Créer un nouveau compte");
$this->description = "";
$this->usage = "adduserloginandprivileges";
if(!empty($_POST['createormodifyusername']) and !empty($_POST['createormodifypassword'])){
	requireCsrf();
	commencerEnregistrement();
	$this->webpageparent->databasemanager->createormodifyuser($_POST['createormodifyusername'], password_hash($_POST['createormodifypassword'], PASSWORD_DEFAULT),intval($_POST['privilegelevel']));
	// La page qui suit (le même formulaire, vide) le dit une fois
	echo "<div class='login-title' style='color:green;'>".htmlspecialchars("Utilisateur ".$_POST['createormodifyusername']." entré avec succès")."</div>";
	terminerEnregistrement();
}


?>