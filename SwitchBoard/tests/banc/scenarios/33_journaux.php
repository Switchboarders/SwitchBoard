#!/usr/bin/env php
<?php
// logs/ et calendarcache/ à la racine de l'installation : créés par le code, protégés du web, et encore là après une
// mise à jour. La règle qui prime : un journal ou un cache impossible à écrire n'arrête jamais le téléphone de garde.
require_once '/banc/commun/banc.php';
require_once '/banc/commun/mise_a_jour.php';

const LOGS = APPLI.'/logs';
const CACHE = APPLI.'/calendarcache';
const JOURNAL = LOGS.'/refreshallringgroupslog.txt';
const JOURNAL_ANCIEN = LOGS.'/refreshallringgroupslog.old.txt';
const COMPTE = 'banc-autonome';
const GROUPE = 'Groupe autonome';
const JEAN = '5145550111';
const MARIE = '5145550112';

function mettreDeGarde(string $nom, string $telephone): void {
	ecrireAgenda('autonome', [['nom' => $nom, 'description' => $telephone, 'debut' => time() - 3600, 'fin' => time() + 3600]]);
}
// Un seul comité, sans aucune page de portail ni aucun cron avant
function preparer(): void {
	exec('rm -rf '.LOGS.' '.CACHE);
	repartirAZero();
	sonnerieSeulementPour([2]);
	mettreDeGarde('Jean', '514-555-0111');
}
function passage(): array {
	viderJournalFaux();
	return lancerCron('refreshallringgroups.php');
}
function repondantEst(string $numero): bool {
	return groupesVoipms(COMPTE) === [GROUPE => [$numero]];
}
function journalDuComite(): string {
	return (string)@file_get_contents(glob(LOGS.'/*id2ringgroupupdatelog.txt')[0] ?? '');
}
function cronVaAuBout(array $cron): bool {
	return $cron['code'] === 0 && str_contains($cron['sortie'], 'Fin du traitement') && !preg_match('/Fatal error|Uncaught /', $cron['sortie']);
}
function signalDeVieBon(): bool {
	return pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'];
}
function fermerDossier(string $dossier): void {
	exec("mkdir -p $dossier && chown root:root $dossier && chmod 755 $dossier");
}

garderCopieInstallation();
reinstaller();
$modeRacine = decoct(fileperms(APPLI) & 0777);
$fermerRacine = fn() => exec('chown root:root '.APPLI.' && chmod 755 '.APPLI);
$ouvrirRacine = fn() => exec('chown www-data:www-data '.APPLI." && chmod $modeRacine ".APPLI);

// --- Premier passage d'un cron, sans page de portail avant : chaque dossier est créé avec sa protection ---
preparer();
verifie(!file_exists(LOGS) && !file_exists(CACHE), "premier passage : logs/ et calendarcache/ n'existent pas au départ");
$cron = passage();
verifie(cronVaAuBout($cron), "premier passage : le cron va au bout");
verifie(repondantEst(JEAN), "premier passage : le groupe de sonnerie est à jour");
foreach ([LOGS, CACHE] as $dossier) {
	$nom = basename($dossier);
	verifie(is_dir($dossier) && posix_getpwuid(fileowner($dossier))['name'] === 'www-data', "premier passage : $nom/ est créé à la racine, par www-data");
	verifie(str_contains((string)@file_get_contents("$dossier/.htaccess"), 'Require all denied'), "premier passage : $nom/ a son .htaccess, sans page de portail");
}
verifie(is_file(JOURNAL) && journalDuComite() !== '', "premier passage : les journaux sont écrits");
verifie(glob(CACHE.'/*.ical') !== [], "premier passage : le cache est écrit");

// --- Accès web refusé, sur de vrais fichiers ---
$racineWeb = 'http://127.0.0.1/SwitchBoard/SwitchBoard/';
foreach ([JOURNAL, glob(CACHE.'/*.ical')[0], CACHE.'/.htaccess'] as $fichier) {
	$chemin = str_replace(APPLI.'/', '', $fichier);
	verifie(requetePortail($racineWeb.implode('/', array_map('rawurlencode', explode('/', $chemin))))['code'] === 403, "web : $chemin est bloqué");
}

// --- logs/ impossible à créer ou à écrire : tout le reste continue ---
$pannesJournal = [
	'logs/ impossible à créer' => [function () use ($fermerRacine) { exec('rm -rf '.LOGS); $fermerRacine(); }, $ouvrirRacine],
	'logs/ fermé' => [function () { exec('rm -rf '.LOGS); fermerDossier(LOGS); }, fn() => exec('chown -R www-data:www-data '.LOGS)],
	'journaux fermés' => [fn() => exec('chown root:root '.LOGS.'/*.txt'), fn() => exec('chown www-data:www-data '.LOGS.'/*.txt')],
	'logs est un fichier' => [fn() => exec('rm -rf '.LOGS.' && touch '.LOGS), fn() => exec('rm -f '.LOGS)],
];
foreach ($pannesJournal as $panne => [$fermer, $rouvrir]) {
	preparer();
	passage();
	$courrielsAvant = count(courriels());
	$fermer();
	mettreDeGarde('Marie', '514-555-0112');
	$cron = passage();
	verifie(cronVaAuBout($cron), "$panne : le cron va au bout (code {$cron['code']})");
	verifie(repondantEst(MARIE), "$panne : le groupe de sonnerie change quand même");
	verifie(signalDeVieBon(), "$panne : le signal de vie reste bon");
	verifie(str_contains($cron['sortie'], 'journal impossible à ouvrir'), "$panne : la cause va dans le journal d'erreurs de PHP");
	verifie(count(courriels()) === $courrielsAvant, "$panne : aucun courriel de plus");
	$rouvrir();
	mettreDeGarde('Jean', '514-555-0111');
	verifie(cronVaAuBout(passage()) && repondantEst(JEAN) && is_file(JOURNAL), "$panne : une fois réparé, tout reprend");
}

// Les autres crons ont le même JournalDeTache : aucun ne s'arrête sur un journal fermé
preparer();
passage();
fermerDossier(LOGS);
exec('chown root:root '.LOGS.'/*.txt');
foreach (lignesCron() as $ligne) {
	if (!preg_match('/(\w+\.php)( "\w+")?/', $ligne, $trouve)) {
		continue;
	}
	file_put_contents('/var/log/cron.log', '');
	exec($ligne.' 2>&1', $sortieDirecte, $code);
	$sortie = (string)file_get_contents('/var/log/cron.log');
	verifie($code === 0 && !preg_match('/Fatal error|Uncaught |Unable to open/', $sortie), "journal fermé, {$trouve[0]} : le cron va au bout (code $code)");
}
exec('chown -R www-data:www-data '.LOGS);

// --- calendarcache/ impossible à écrire : l'agenda devient illisible, et les derniers répondants restent ---
$pannesCache = [
	'calendarcache/ impossible à créer' => [function () use ($fermerRacine) { exec('rm -rf '.CACHE); $fermerRacine(); }, $ouvrirRacine],
	'calendarcache/ fermé' => [function () { exec('rm -rf '.CACHE); fermerDossier(CACHE); }, fn() => exec('chown -R www-data:www-data '.CACHE)],
];
foreach ($pannesCache as $panne => [$fermer, $rouvrir]) {
	preparer();
	passage();
	$courrielsAvant = count(courriels());
	$fermer();
	mettreDeGarde('Marie', '514-555-0112');
	$cron = passage();
	verifie(cronVaAuBout($cron), "$panne : le cron va au bout, sans erreur fatale");
	verifie(methodesVoipms(COMPTE) === [] && repondantEst(JEAN), "$panne : aucun appel à voip.ms, les derniers répondants restent");
	verifie(str_contains(journalDuComite(), "Écriture de l'agenda dans le cache impossible") && str_contains(journalDuComite(), 'Agenda illisible'), "$panne : ça se voit au journal du comité");
	verifie(signalDeVieBon() && count(courriels()) === $courrielsAvant, "$panne : signal de vie bon, aucun courriel de plus");
	$rouvrir();
	verifie(cronVaAuBout(passage()) && repondantEst(MARIE), "$panne : une fois réparé, le groupe suit l'agenda");
}

// Une copie est déjà là : c'est elle qui sert, et le groupe reste à la dernière garde copiée
preparer();
passage();
exec('chown -R root:root '.CACHE.' && chmod 755 '.CACHE);
mettreDeGarde('Marie', '514-555-0112');
$cron = passage();
verifie(cronVaAuBout($cron) && repondantEst(JEAN), "calendarcache/ fermé avec une copie : la copie sert, les répondants restent");
verifie(str_contains(journalDuComite(), "Écriture de l'agenda dans le cache impossible"), "calendarcache/ fermé avec une copie : ça se voit au journal du comité");
exec('chown -R www-data:www-data '.CACHE);

// --- Après une mise à jour, les journaux et le cache sont encore là, et le cache sert pendant une panne d'agenda ---
preparer();
passage();
$journalAvant = (string)file_get_contents(JOURNAL);
$fichierCache = glob(CACHE.'/*.ical')[0];
$cacheAvant = (string)file_get_contents($fichierCache);
publierVersion();
cliquerMettreAJour();
verifie(versionCode() === VERSION_NEUVE, "après une mise à jour : le nouveau code est en place");
verifie(str_starts_with((string)file_get_contents(JOURNAL), $journalAvant), "après une mise à jour : le journal est encore là");
verifie(file_get_contents($fichierCache) === $cacheAvant, "après une mise à jour : le cache des agendas est encore là");
verifie(glob(APPLI.'/backups/*/src/*/{logs/*.txt,calendarcache/*.ical}', GLOB_BRACE) === [], "après une mise à jour : aucun journal ni agenda dans la sauvegarde");

unlink(ETAT_BANC.'/agenda-autonome.ics');
exec('rm -f '.ETAT_BANC.'/voipms-'.COMPTE.'.json');
connexionBD()->query('DELETE FROM ringgroupstate');
verifie(cronVaAuBout(passage()) && repondantEst(JEAN), "panne d'agenda juste après la mise à jour : le cache redonne les répondants à voip.ms");
reinstaller();

// --- Rotation à 5 Mo ---
$gros = fn(string $lettre) => str_repeat(str_repeat($lettre, 1023)."\n", 5 * 1024 + 1);
preparer();
passage();
file_put_contents(JOURNAL, $gros('x'));
verifie(cronVaAuBout(passage()), "rotation : le cron va au bout");
verifie(file_get_contents(JOURNAL_ANCIEN) === $gros('x'), "rotation : le gros journal passe en .old.txt");
verifie(filesize(JOURNAL) < 100000 && str_contains((string)file_get_contents(JOURNAL), "Heure d'exécution"), "rotation : l'écriture continue dans un journal neuf");
verifie(repondantEst(JEAN), "rotation : le groupe de sonnerie fonctionne");
file_put_contents(JOURNAL, $gros('y'));
passage();
verifie(file_get_contents(JOURNAL_ANCIEN) === $gros('y'), "rotation : le .old.txt suivant écrase l'ancien");

// Sous la limite, rien ne bouge
$petit = str_repeat('z', 1000)."\n";
file_put_contents(JOURNAL, $petit);
passage();
verifie(file_get_contents(JOURNAL_ANCIEN) === $gros('y') && str_starts_with((string)file_get_contents(JOURNAL), $petit), "rotation : un journal sous la limite ne bouge pas");

// Dossier fermé : le renommage est impossible, mais le fichier reste ouvert à l'écriture
file_put_contents(JOURNAL, $gros('x'));
fermerDossier(LOGS);
mettreDeGarde('Marie', '514-555-0112');
$cron = passage();
verifie(cronVaAuBout($cron) && repondantEst(MARIE), "rotation impossible : le cron va au bout et le groupe change");
verifie(str_contains($cron['sortie'], 'rotation impossible'), "rotation impossible : la cause va dans le journal d'erreurs de PHP");
verifie(str_contains((string)file_get_contents(JOURNAL), "Heure d'exécution"), "rotation impossible : le journal continue d'être écrit");
exec('chown -R www-data:www-data '.LOGS);

// --- Le bouton « Effacer tous les journaux » efface aussi les .old.txt, et garde le .htaccess ---
connecterAuPortail('admin');
$page = requetePortail(PORTAIL.'?page=mainpage')['page'];
$aLeBouton = preg_match("/<form class='form-action[^']*' method='post' action='managementportal.php\?page=mainpage'[^>]*>.*?name='action' value='deletealllogs'.*?<button[^>]*>Effacer tous les journaux d&#039;exécution<\/button>/s", $page) === 1;
verifie($aLeBouton, "effacer les journaux : l'admin a le bouton (un formulaire POST, pas un lien)");
verifie(!str_contains($page, 'action=deletealllogs'), "effacer les journaux : plus aucun lien qui efface");
$suite = requetePortail(PORTAIL.'?page=mainpage', ['action' => 'deletealllogs', 'csrf_token' => jetonCsrfDeLaSession()]);
verifie($suite['code'] === 303 && enTete($suite, 'Location') === 'managementportal.php?page=mainpage', "effacer les journaux : redirection vers la page principale (code {$suite['code']})");
$reponse = requetePortail(dirname(PORTAIL).'/'.enTete($suite, 'Location'));
verifie(str_contains($reponse['page'], 'Tous les journaux ont été effacés'), "effacer les journaux : la page le dit");
verifie(!file_exists(JOURNAL_ANCIEN), "effacer les journaux : le .old.txt est effacé");
verifie(!str_contains((string)@file_get_contents(JOURNAL), "Heure d'exécution"), "effacer les journaux : le journal est effacé");
verifie(is_file(LOGS.'/.htaccess'), "effacer les journaux : le .htaccess reste");

oublierCopieInstallation();
finDuScenario();
