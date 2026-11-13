<?php
// Prend une sauvegarde complète de SwitchBoard, code et BD, dans backups/<date>/ :
//   php /var/www/html/switchboard/src/utils/jobBackup.php
// Pour y revenir : voir jobRestore.php, dans le même dossier.
ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require dirname(__FILE__,2).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé
// partagée CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();


$myFileManager = new Fichiers();

$nomReperBckDate = date("Y-m-d") . "/";
$uniqueFolder = $myFileManager->backupSBcode(BASE_DIRECTORY, $nomReperBckDate);

// La BD va dans le même dossier que le code
$mydatabasemanager = new DatabaseManager();
$backupPath = BASE_DIRECTORY . BCK_SWITCHBOARD_DIRECTORY . $uniqueFolder . FICHIER_BACKUP_BD;
$mydatabasemanager->backupDatabase($backupPath);
