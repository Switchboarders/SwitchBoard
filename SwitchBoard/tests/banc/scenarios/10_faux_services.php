#!/usr/bin/env php
<?php
// Les faux services et les comités inventés, de bout en bout : les vrais crons, avec leur vraie ligne de
// crontab, sur une vraie BD. Ce scénario prouve le banc ; les cas métier viennent aux tâches 2.3 et 2.4.
require_once '/banc/commun/banc.php';

$maintenant = time();
$quartEnCours = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => $maintenant - 3600, 'fin' => $maintenant + 3600];

// --- Installation neuve et comités inventés ---
$erreursSql = repartirAZero();
$bd = connexionBD();
$versions = json_decode(file_get_contents(DOSSIER_UPDATEJSON.NOM_FICHIER_DB_VERSION), true)['versions'];
$derniereVersion = end($versions)['noVersion'];
verifie($bd->query("SELECT paramvalue FROM parameters WHERE paramname = 'db version'")->fetch_row()[0] === $derniereVersion, "la BD est à la version $derniereVersion");
verifie($derniereVersion === json_decode(file_get_contents(FICHIER_LOCAL_CODE_VERSION))->codeVersion, "codeVersion égale le dernier noVersion");
foreach (['userservice.mainaccount', 'userinfo.ringtime', 'ringgroupmember.pressone', 'userinfo.calendartype'] as $colonne) {
	[$table, $nom] = explode('.', $colonne);
	verifie($bd->query("SHOW COLUMNS FROM `$table` LIKE '$nom'")->num_rows === 1, "les migrations donnent la colonne $colonne");
}
verifie(array_filter($erreursSql, fn($erreur) => !preg_match('/^(Duplicate|Table .* already exists|Can\'t DROP|Multiple primary|Unknown column)/i', $erreur)) === [],
	"les erreurs SQL de l'installation sont seulement des doublons (".count($erreursSql)." : ".implode(' ; ', array_slice($erreursSql, 0, 3)).")");

// --- Le cron de sonnerie, sur un compte autonome et sur un compte partagé ---
ecrireAgenda('autonome', [$quartEnCours('Jean', '514-555-0111')]);
ecrireAgenda('membre1', [$quartEnCours('Marie', '514-555-0112')]);
ecrireAgenda('membre2', [$quartEnCours('Lise', '514-555-0113')]);
$cron = lancerCron('refreshallringgroups.php');
verifie($cron['code'] === 0 && str_contains($cron['sortie'], 'Fin du traitement'), "le cron de sonnerie va jusqu'au bout");
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111']], "le groupe autonome contient son répondant : le mot de passe a été déchiffré");
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => ['5145550112'], 'Groupe membre 2' => ['5145550113']], "chaque membre a son groupe sur le compte partagé");
verifie(in_array('https://hc-ping.com', array_map(fn($a) => 'https://'.$a['hote'], journalFaux('hc-ping.com')), true)
	&& journalFaux('hc-ping.com')[0]['chemin'] === '/banc-ringgroup', "le signal de vie du cron arrive au faux service");
verifie(courriels() === [], "aucun courriel quand tout va bien");

// --- Courriel ---
$cron = lancerCron('mailcanary.php');
$envoyes = courriels();
verifie($cron['code'] === 0 && count($envoyes) === 1 && str_contains($envoyes[0], 'canari@banc.test'), "le courriel de mailcanary est capturé");
touch(ETAT_BANC.'/panne-msmtp');
$cron = lancerCron('mailcanary.php');
unlink(ETAT_BANC.'/panne-msmtp');
verifie(count(courriels()) === 1, "une panne de msmtp n'écrit aucun courriel");
verifie(array_pop(array_filter(journalFaux('hc-ping.com'), fn($a) => str_starts_with($a['chemin'], '/banc-mail')))['chemin'] === '/banc-mail/fail', "mailcanary signale la panne de msmtp");

// --- Rappel par SMS ---
$midiDemain = strtotime('tomorrow noon');
ecrireAgenda('autonome', [['nom' => '*Jean', 'description' => '514-555-0111', 'debut' => $midiDemain, 'fin' => $midiDemain + 3600]]);
lancerCron('sendnotificationsmss.php "daybefore"');
$sms = array_values(array_filter(journalFaux('voip.ms'), fn($a) => ($a['params']['method'] ?? '') === 'sendSMS'));
verifie(count($sms) === 1 && $sms[0]['params']['dst'] === '5145550111' && $sms[0]['params']['did'] === '5145550101', "le rappel part vers le répondant, depuis le DID du comité");

// --- SwitchBoard Chat ---
ecrireAgenda('chat-sbchat', [$quartEnCours('@lita', '')]);
$cron = lancerCron('refreshsbchat.php');
$commande = json_decode(journalFaux('sbchat.banc.test')[0]['corps'] ?? '{}', true);
verifie(($commande['command'] ?? '') === 'change_respondents' && ($commande['respondent'] ?? []) === ['@lita'], "le cron de chat envoie le répondant au faux serveur");

// --- Pannes simulées et mauvais identifiants ---
$urlVoipms = 'https://voip.ms/api/v1/rest.php?method=getIP&api_username=banc-autonome&api_password=';
$codeHttp = function (string $url): int {
	$c = curl_init($url);
	curl_setopt($c, CURLOPT_RETURNTRANSFER, true);
	curl_exec($c);
	return curl_getinfo($c, CURLINFO_HTTP_CODE);
};
simulerPanne('voip.ms', [522, 200]);
verifie($codeHttp($urlVoipms.'banc-banc-autonome') === 522, "une panne simulée répond son code");
verifie($codeHttp($urlVoipms.'banc-banc-autonome') === 200, "le code suivant de la panne répond normalement");
verifie(json_decode(file_get_contents($urlVoipms.'mauvais'))->status === 'invalid_credentials', "un mauvais mot de passe est refusé");

// --- Les autres services ---
$taux = (new TauxDeChange())->obtenirTauxUsdVersCad();
verifie($taux['taux'] === 1.37 && $taux['date'] === '2026-11-02', "le taux vient de la fausse Banque du Canada ({$taux['taux']}, {$taux['source']})");
verifie(trim(file_get_contents('https://docs.google.com/spreadsheets/d/abc/export?format=csv')) === 'Villes,Telephone', "le faux Google Sheets répond");

// GitHub : le vrai client (knplabs, HTTP de Symfony) doit voir une version plus récente
mkdir(ETAT_BANC.'/github/depot/SwitchBoard/src/updatejson', 0755, true);
file_put_contents(ETAT_BANC.'/github/depot/'.FICHIER_DISTANT_CODE_VERSION, '{"codeVersion":"999"}');
exec('chown -R www-data:www-data '.ETAT_BANC.'/github');
$code = <<<'PHP'
require '/var/www/html/SwitchBoard/SwitchBoard/src/commun/lesconstantes.php';
require SRC_DIRECTORY.'updatemanager.php';
$gestion = new UpdateManager();
var_export($gestion->checkNewVersionAvailable());
echo ' '.$gestion->getVersionDistante();
PHP;
exec('su -s /bin/sh www-data -c '.escapeshellarg('php -r '.escapeshellarg($code)).' 2>&1', $sortie);
verifie(str_contains(implode("\n", $sortie), 'true 999'), "le vrai client GitHub voit la version du faux dépôt (".substr(implode(' ', $sortie), 0, 300).")");

// --- Tous les appels sont restés dans le banc ---
$hotesConnus = array_map('trim', file('/etc/banc/hotes-faux.txt'));
verifie(array_diff(array_unique(array_column(journalFaux(), 'hote')), $hotesConnus) === [], "aucun appel vers un hôte hors de la liste");
verifie(array_filter(journalFaux(), fn($a) => $a['hote'] === 'github.com' || $a['hote'] === 'login.microsoftonline.com') === [], "aucun appel à un service non simulé");

finDuScenario();
