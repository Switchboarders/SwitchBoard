<?php
// Ce fichier ne fait que préparer $dbmanager/$currentuserid pour le formulaire des macros
// (sbchatmacros/sbchatmacrosform.php). La copie du gestionnaire de sauvegarde SBChat/MAS qui
// vivait ici (dupliquée de sbchatinformationspage.php) a été retirée : le formulaire des
// macros ne soumet plus ces champs, et la configuration SBChat/MAS se fait uniquement sur
// ?page=sbchatinfo (voir SBCHAT-SETUP.md). Garder deux copies du même gestionnaire avait
// déjà fait diverger les corrections de bogues entre les deux pages.
$dbmanager = new DatabaseManager();
if(!empty($_GET["iduser"])){
	$iduser = $_GET["iduser"];
}else{
	$iduser = -1;
}
// Le droit sur cet iduser est déjà contrôlé par sa route (voir modules/portail/Routes.php)
$currentuserid = ($iduser == -1 ? $dbmanager->getcurrentuserid() : $iduser);
?>
