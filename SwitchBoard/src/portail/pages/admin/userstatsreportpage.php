<?php
require_once COMMUN_DIRECTORY.'statsreportrenderer.php';

echo boutonRetourConsole();

$iduser = intval($_GET['iduser']);
$username = $this->webpageparent->databasemanager->getuserbyid($iduser)['username'];
renderStatsReportSection($iduser, $username, "iduser=".$iduser);
?>
