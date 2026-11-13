<?php 
// Page principale : on prépare tout ici (appels à voip.ms, à un service d'adresse IP, aux journaux), la vue ne fait qu'afficher
$ipSelonVoipms = $this->webpageparent->voipms->lireIp();
$ipSelonLeServeur = htmlspecialchars($_SERVER['SERVER_ADDR'] ?? '');
//$external_ip = exec('curl http://ipecho.net/plain; echo'); // Removed because some servers prohibit exec.
$external_ip = file_get_contents('http://ipecho.net/plain');
$ipExterne = htmlspecialchars($external_ip !== false ? $external_ip : '');
$renvois = $this->webpageparent->calendarmanager->groupeSonnerie()->lireRenvoisDuComite();
$estAdmin = $this->webpageparent->databasemanager->getcurrentuserprivilegelevel()<1;
$remindersmsdaybefore = new JournalDeTache("remindersmsdaybefore",$this->webpageparent->databasemanager->getcurrentuserid());
$remindersmshourbefore = new JournalDeTache("remindersmshourbefore",$this->webpageparent->databasemanager->getcurrentuserid());
$refreshallringgroupslog = $estAdmin ? new JournalDeTache("refreshallringgroups") : null;
// Le journal de l'utilisateur actuel (celui que la page « Forcer » et le cron de sonnerie écrivent)
$journalUtilisateur = $this->webpageparent->logmanager;

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/mainpage.vue.php';
