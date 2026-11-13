<?php
require_once COMMUN_DIRECTORY.'statsreportrenderer.php';

echo boutonRetour($this->webpageparent->databasemanager);

$iduser = $this->webpageparent->databasemanager->getcurrentuserid();
$username = $this->webpageparent->databasemanager->getuserbyid($iduser)['username'];
renderStatsReportSection($iduser, $username);
?>
