<?php
$iduser = intval($_GET['iduser']);
if(isset($_POST['haschanged'])){
	requireCsrf();
	commencerEnregistrement();
	$this->webpageparent->databasemanager->setUserServiceValuesByIduser($iduser,empty($_POST['ringgroupupdate']) ? 0 : 1,empty($_POST['smsdaybefore']) ? 0 : 1,empty($_POST['smshourbefore']) ? 0 : 1,empty($_POST['sendstats']) ? 0 : 1,empty($_POST['sbchatupdate']) ? 0 : 1,empty($_POST['mainaccount']) ? 0 : 1);
	terminerEnregistrement();

}

$this->checkboxvalues = $this->webpageparent->databasemanager->getUserServiceValuesByIduser($iduser);

?>