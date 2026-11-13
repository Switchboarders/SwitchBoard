#!/usr/bin/env php
<?php
// Les sauvegardes : les 5 que garde la mise à jour, jobBackup.php, et le retour arrière de jobRestore.php. La règle
// du retour arrière : il remet le code sans toucher à la BD, seulement sous les verrous, et une panne laisse ce qui
// tournait. Après chaque retour arrière, le groupe de sonnerie fonctionne.
require_once '/banc/commun/banc.php';
require_once '/banc/commun/mise_a_jour.php';

const BACKUPS = APPLI.'/backups';
const JOB_RESTORE = APPLI.'/src/utils/jobRestore.php';
const SORTIE_RETOUR = '/tmp/sortie-retour';
const TUBE = 'src/zzz-banc/tube';

function creerSauvegardes(array $noms): void {
	foreach ($noms as $nom) {
		mkdir(BACKUPS."/$nom/src/commun", 0775, true);
		touch(BACKUPS."/$nom/src/commun/lesconstantes.php");
	}
	exec('chown -R www-data:www-data '.BACKUPS);
}
// Ce qu'une mise à jour ratée laisse : un BD.sql, sans src/
function creerEssaiRate(string $nom): void {
	mkdir(BACKUPS."/$nom", 0775);
	file_put_contents(BACKUPS."/$nom/BD.sql", "-- essai raté\n");
	exec('chown -R www-data:www-data '.BACKUPS);
}
function sauvegardes(): array {
	return array_map('basename', glob(BACKUPS.'/*', GLOB_ONLYDIR));
}
function sauvegardesAvecSrc(): array {
	return array_map(fn(string $chemin) => basename(dirname($chemin)), glob(BACKUPS.'/*/src', GLOB_ONLYDIR));
}
function compteSous(string $commande, string $compte): array {
	exec('su -s /bin/sh '.$compte.' -c '.escapeshellarg("$commande 2>&1"), $sortie, $code);
	return ['code' => $code, 'sortie' => implode(' | ', $sortie)];
}
function retourArriere(string $arguments = '', string $script = JOB_RESTORE, string $compte = 'www-data'): array {
	return compteSous("php $script $arguments", $compte);
}
// Une mise à jour réussie. Retourne le nom de la sauvegarde qu'elle laisse.
function installerVersionNeuve(?callable $modifier = null): string {
	reinstaller();
	publierVersion($modifier);
	cliquerMettreAJour();
	verifie(versionCode() === VERSION_NEUVE, "préparation : la nouvelle version est installée");
	return sauvegardesAvecSrc()[0];
}
// Le retour arrière n'a rien fait : le code et la BD restent ceux de la nouvelle version, sans nouveau dossier
function verifierRienNEstRevenu(string $cas, array $sauvegardesAvant): void {
	verifie(versionCode() === VERSION_NEUVE && versionBD() === VERSION_NEUVE && tableMigree(), "$cas : le code et la BD ne bougent pas");
	verifie(sauvegardes() === $sauvegardesAvant, "$cas : aucun nouveau dossier de sauvegarde");
	verifie(!file_exists(APPLI.'/BD.sql'), "$cas : aucun BD.sql à la racine");
	verifie(sonnerieFonctionne(), "$cas : le groupe de sonnerie fonctionne");
}
function verifierCodeRevenu(string $cas): void {
	global $versionDepart;
	verifie(versionCode() === $versionDepart, "$cas : la version d'avant est en place (code ".versionCode().")");
	verifie(posix_getpwuid(fileowner(APPLI.'/src'))['name'] === 'www-data', "$cas : src/ appartient à www-data");
	verifie(glob(BACKUPS.'/*/preparation') === [], "$cas : aucune copie de travail ne reste");
	verifie(!file_exists(APPLI.'/BD.sql'), "$cas : aucun BD.sql à la racine");
	verifie(is_file(APPLI.'/config/config.php'), "$cas : la configuration reste");
	verifie(sonnerieFonctionne(), "$cas : le groupe de sonnerie fonctionne");
}

// Un tube nommé dans la sauvegarde : jobRestore.php s'arrête en le copiant, verrous pris, et le scénario peut agir sans
// aucun crochet dans le code. Il est dans son propre sous-dossier parce que copy() ouvre la source avant la destination.
function demarrerRetourQuiAttend(string $sauvegarde) {
	$tube = BACKUPS."/$sauvegarde/".TUBE;
	mkdir(dirname($tube));
	posix_mkfifo($tube, 0644);
	exec('chown -R www-data:www-data '.dirname($tube));
	$processus = proc_open(['su', '-s', '/bin/sh', 'www-data', '-c', 'php '.JOB_RESTORE." $sauvegarde"],
		[1 => ['file', SORTIE_RETOUR, 'w'], 2 => ['file', SORTIE_RETOUR, 'a']], $tuyaux);
	$limite = time() + 30;
	while (glob(BACKUPS.'/*/preparation/src/zzz-banc') === []) {
		if (time() > $limite) {
			throw new RuntimeException("Le retour arrière n'est jamais arrivé au tube : ".file_get_contents(SORTIE_RETOUR));
		}
		usleep(50000);
	}
	return $processus;
}
function sortieDuRetour(): string {
	return str_replace("\n", ' | ', trim((string)file_get_contents(SORTIE_RETOUR)));
}
function laisserContinuer($processus, string $sauvegarde): int {
	fclose(fopen(BACKUPS."/$sauvegarde/".TUBE, 'w'));
	$code = proc_close($processus);
	exec('rm -rf '.escapeshellarg(dirname(BACKUPS."/$sauvegarde/".TUBE)).' '.APPLI.'/src/zzz-banc');
	return $code;
}

garderCopieInstallation();
reinstaller();
$versionDepart = versionCode();

// --- 7 sauvegardes complètes et 1 essai raté : après une réussite, il en reste 5 complètes ---
$anciennes = ['2026-01-01', '2026-01-02', '2026-01-03', '2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07'];
$preparerSauvegardes = function () use ($anciennes) {
	reinstaller();
	creerSauvegardes($anciennes);
	creerEssaiRate('2026-01-08');
	mkdir(BACKUPS.'/posee-a-la-main');
};

$preparerSauvegardes();
publierVersion();
cliquerMettreAJour();
verifierInstallee("5 sauvegardes");
$avecSrc = sauvegardesAvecSrc();
verifie(count($avecSrc) === 5 && array_slice($avecSrc, 0, 4) === ['2026-01-04', '2026-01-05', '2026-01-06', '2026-01-07'], "5 sauvegardes : il reste les 5 plus récentes (".implode(', ', $avecSrc).")");
verifie(!is_dir(BACKUPS.'/2026-01-08'), "5 sauvegardes : l'essai raté est parti");
verifie(is_dir(BACKUPS.'/posee-a-la-main'), "5 sauvegardes : un dossier posé à la main reste");
verifie(glob(BACKUPS.'/*/BD.sql') !== [] && is_file(BACKUPS.'/.htaccess'), "5 sauvegardes : la nouvelle sauvegarde et le .htaccess restent");

// Après un échec, rien n'est effacé : une série d'échecs ne doit pas coûter les bonnes sauvegardes
$preparerSauvegardes();
$avant = sauvegardes();
publierVersion(fn(string $racine) => file_put_contents("$racine/SwitchBoard/src/updatejson/bancmigration.sql", "CECI N'EST PAS DU SQL;\n"));
cliquerMettreAJour();
verifie(versionCode() === $versionDepart, "échec de la mise à jour : l'ancien code reste");
verifie(array_diff($avant, sauvegardes()) === [], "échec de la mise à jour : aucune sauvegarde n'est effacée");
verifie(sonnerieFonctionne(), "échec de la mise à jour : le groupe de sonnerie fonctionne");
publierVersion();
cliquerMettreAJour();
verifie(count(sauvegardesAvecSrc()) === 5, "échec de la mise à jour : la relance réussie fait le ménage");

// Déjà à jour : aucune mise à jour n'a eu lieu, donc aucun ménage
reinstaller();
creerSauvegardes($anciennes);
publierVersion(function (string $racine) use ($versionDepart) {
	file_put_contents("$racine/SwitchBoard/src/updatejson/codeVersion.json", json_encode(['codeVersion' => $versionDepart]));
	remplacerMigration($racine, []);
});
$relance = miseAJourParSsh();
verifie(str_contains($relance['sortie'], 'déjà à jour') && count(sauvegardesAvecSrc()) === 7, "déjà à jour : aucun ménage (".$relance['sortie'].")");

// Le dossier de la mise à jour en cours n'est jamais effacé, même s'il n'est pas parmi les 5 plus récents
reinstaller();
$futures = ['2099-01-01', '2099-01-02', '2099-01-03', '2099-01-04', '2099-01-05', '2099-01-06'];
creerSauvegardes($futures);
publierVersion();
cliquerMettreAJour();
verifie(count(array_diff(sauvegardesAvecSrc(), $futures)) === 1 && count(sauvegardesAvecSrc()) === 6, "dossier en cours : il reste, avec les 5 plus récents");

// Un dossier impossible à effacer ne fait qu'un message : la mise à jour reste réussie
reinstaller();
creerSauvegardes($anciennes);
exec('chown -R root:root '.BACKUPS.'/2026-01-01');
publierVersion();
$page = cliquerMettreAJour();
verifierInstallee("ménage impossible");
verifie(str_contains($page, "pas pu être effacée complètement") && str_contains($page, 'Mise à jour terminée') && !str_contains($page, "class='alert alert-error'"), "ménage impossible : un message, et la mise à jour reste réussie");
verifie(is_dir(BACKUPS.'/2026-01-01') && !is_dir(BACKUPS.'/2026-01-02'), "ménage impossible : le reste du ménage se fait");

// --- jobBackup.php : ni journaux, ni cache, ni download/ à la racine de la sauvegarde ---
reinstaller();
exec('mkdir -p '.APPLI.'/logs '.APPLI.'/calendarcache '.APPLI.'/download '.APPLI.'/vendor/banc-paquet/logs '.APPLI.'/vendor/banc-paquet/calendarcache');
exec('touch '.APPLI.'/logs/banc.txt '.APPLI.'/calendarcache/banc.ical '.APPLI.'/download/banc.zip '.APPLI.'/vendor/banc-paquet/logs/garder.txt '.APPLI.'/vendor/banc-paquet/calendarcache/garder.txt '.APPLI.'/vendor/banc-paquet/BD.sql');
file_put_contents(APPLI.'/BD.sql', "-- vieux dump oublié à la racine\n");
exec('chown -R www-data:www-data '.APPLI.'/logs '.APPLI.'/calendarcache '.APPLI.'/download '.APPLI.'/vendor/banc-paquet '.APPLI.'/BD.sql');

// backupSBcode() seul : jobBackup.php écrit ensuite son propre BD.sql, qui cacherait celui de la racine
$copieSeule = compteSous('php -r '.escapeshellarg('require "'.APPLI.'/src/commun/lesconstantes.php"; (new Fichiers())->backupSBcode(BASE_DIRECTORY, "banc-copie/");'), 'www-data');
verifie($copieSeule['code'] === 0 && is_dir(BACKUPS.'/banc-copie/src'), "jobBackup, copie du code : elle réussit (".$copieSeule['sortie'].")");
verifie(!file_exists(BACKUPS.'/banc-copie/BD.sql'), "jobBackup, copie du code : le BD.sql de la racine n'y entre pas");
verifie(is_file(BACKUPS.'/banc-copie/vendor/banc-paquet/BD.sql'), "jobBackup, copie du code : un BD.sql plus bas que la racine reste");
exec('rm -rf '.BACKUPS.'/banc-copie');

$sauvegardeComplete = compteSous('php '.APPLI.'/src/utils/jobBackup.php', 'www-data');
exec('rm -rf '.APPLI.'/vendor/banc-paquet/BD.sql '.APPLI.'/logs/banc.txt '.APPLI.'/calendarcache/banc.ical '.APPLI.'/download/banc.zip');
verifie($sauvegardeComplete['code'] === 0, "jobBackup : il réussit (".$sauvegardeComplete['sortie'].")");
$complete = BACKUPS.'/'.sauvegardesAvecSrc()[0];
foreach (['logs', 'calendarcache', 'download'] as $exclu) {
	verifie(!file_exists("$complete/$exclu"), "jobBackup : pas de $exclu/ à la racine de la sauvegarde");
}
verifie(is_file("$complete/vendor/banc-paquet/logs/garder.txt") && is_file("$complete/vendor/banc-paquet/calendarcache/garder.txt"), "jobBackup : les dossiers du même nom sous vendor/ restent");
verifie(is_file("$complete/config/config.php") && is_file("$complete/vendor/autoload.php"), "jobBackup : config/ et vendor/ sont là");
$dump = (string)file_get_contents("$complete/BD.sql");
verifie(str_contains($dump, '-- Dump completed') && !str_contains($dump, 'vieux dump'), "jobBackup : le BD.sql de la sauvegarde est neuf, pas celui de la racine");
unlink(APPLI.'/BD.sql');
exec('rm -rf '.APPLI.'/vendor/banc-paquet');

// Une sauvegarde complète se restaure aussi, sans toucher à config/
file_put_contents("$complete/config/config.php", "\n// vieille copie du banc\n", FILE_APPEND);
publierVersion();
cliquerMettreAJour();
$retour = retourArriere(basename($complete));
verifie($retour['code'] === 0, "sauvegarde complète : le retour arrière réussit (".$retour['sortie'].")");
verifierCodeRevenu("sauvegarde complète");
verifie(!str_contains((string)file_get_contents(APPLI.'/config/config.php'), 'vieille copie du banc'), "sauvegarde complète : config/ n'est pas restauré");
verifie(is_file(APPLI.'/vendor/banc-paquet/logs/garder.txt'), "sauvegarde complète : vendor/ vient de la sauvegarde");

// --- jobRestore.php : retour à la version d'avant, sans toucher à la BD ---
$sauvegarde = installerVersionNeuve();
$retour = retourArriere();
verifie($retour['code'] === 0 && str_contains($retour['sortie'], "La BD n'a pas été touchée"), "retour arrière : il réussit (".$retour['sortie'].")");
verifierCodeRevenu("retour arrière");
verifie(!str_contains($retour['sortie'], 'vendor/ est refait') && glob(BACKUPS.'/*/vendor') === [], "retour arrière : même zip de dépendances, vendor/ n'est pas touché");
verifie(versionBD() === VERSION_NEUVE && tableMigree(), "retour arrière : la BD n'est pas touchée");
$remplacee = array_values(array_diff(sauvegardesAvecSrc(), [$sauvegarde]));
verifie(count($remplacee) === 1 && is_file(BACKUPS."/{$remplacee[0]}/src/managementportal.php"), "retour arrière : le code remplacé est gardé dans un nouveau dossier");
verifie(str_contains((string)file_get_contents(BACKUPS."/{$remplacee[0]}/".FICHIER_VERSION_CODE), VERSION_NEUVE), "retour arrière : ce dossier contient bien la version remplacée");
verifie(is_file(BACKUPS."/$sauvegarde/src/managementportal.php") && is_file(BACKUPS."/$sauvegarde/BD.sql"), "retour arrière : la sauvegarde choisie reste entière");
sleep(3); // OPcache de php-fpm : revalidate_freq = 2
connecterAuPortail('admin');
$reponse = requetePortail(PORTAIL.'?page=adminpanel');
verifie($reponse['code'] === 200 && !preg_match('/Fatal error|Uncaught /', $reponse['page']), "retour arrière : la console répond sur le code revenu");

// La sauvegarde est restée entière : on peut encore y ajouter la BD
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] === 0, "code puis BD : le second retour arrière réussit (".$retour['sortie'].")");
verifie(versionBD() === $versionDepart && !tableMigree(), "code puis BD : la BD revient aussi");
verifie(sonnerieFonctionne(), "code puis BD : le groupe de sonnerie fonctionne");

// La mise à jour repart après un retour arrière
publierVersion();
cliquerMettreAJour();
verifierInstallee("après un retour arrière, la mise à jour repart");

// Sans argument, un second retour revient au code remplacé : c'est maintenant la sauvegarde la plus récente
$sauvegarde = installerVersionNeuve();
retourArriere();
$retour = retourArriere();
verifie($retour['code'] === 0 && versionCode() === VERSION_NEUVE, "deux retours de suite : le second revient au code remplacé (".$retour['sortie'].")");
verifie(sonnerieFonctionne(), "deux retours de suite : le groupe de sonnerie fonctionne");

// --- vendor/ est remis s'il est dans la sauvegarde, et le vendor/ remplacé est gardé ---
$sauvegarde = installerVersionNeuve(fn(string $racine) => changerDependances($racine));
verifie(is_file(APPLI.'/vendor/marqueur-banc'), "avec vendor/ : la mise à jour a remplacé vendor/");
$retour = retourArriere();
verifie($retour['code'] === 0, "avec vendor/ : le retour arrière réussit (".$retour['sortie'].")");
verifierCodeRevenu("avec vendor/");
verifie(!is_file(APPLI.'/vendor/marqueur-banc') && is_file(APPLI.'/vendor/autoload.php'), "avec vendor/ : l'ancien vendor/ est en place");
verifie(glob(BACKUPS.'/*/vendor/marqueur-banc') !== [], "avec vendor/ : le vendor/ remplacé est gardé");

// --- Sans vendor/ dans la sauvegarde, mais avec un autre zip de dépendances : vendor/ est refait depuis ce zip ---
// C'est le retour vers une version d'avant la v138 : son code a besoin d'ifsnop/mysqldump-php, que le vendor/ en place n'a plus.
$sauvegarde = installerVersionNeuve(fn(string $racine) => changerDependances($racine));
exec('rm -rf '.BACKUPS."/$sauvegarde/vendor");
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] === 0 && str_contains($retour['sortie'], 'vendor/ est refait depuis precompileddependencies'), "autre zip, sans vendor/ : le retour arrière refait vendor/ (".$retour['sortie'].")");
verifierCodeRevenu("autre zip, sans vendor/");
verifie(!is_file(APPLI.'/vendor/marqueur-banc') && is_file(APPLI.'/vendor/autoload.php'), "autre zip, sans vendor/ : vendor/ est celui du zip de la sauvegarde");
verifie(glob(BACKUPS.'/*/vendor/marqueur-banc') !== [], "autre zip, sans vendor/ : le vendor/ remplacé est gardé");
verifie(versionBD() === $versionDepart && !tableMigree(), "autre zip, sans vendor/ : avec --avec-bd, la BD revient aussi");

// Le zip de la sauvegarde est illisible : erreur avant d'avoir touché à quoi que ce soit
$sauvegarde = installerVersionNeuve(fn(string $racine) => changerDependances($racine));
exec('rm -rf '.BACKUPS."/$sauvegarde/vendor");
file_put_contents(glob(BACKUPS."/$sauvegarde/src/precompileddependencies*.zip")[0], 'pas un zip');
$avant = sauvegardes();
$retour = retourArriere($sauvegarde);
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], 'illisible'), "zip de la sauvegarde illisible : le retour arrière le dit (".$retour['sortie'].")");
verifie(is_file(APPLI.'/vendor/marqueur-banc'), "zip de la sauvegarde illisible : vendor/ ne bouge pas");
verifierRienNEstRevenu("zip de la sauvegarde illisible", $avant);

// --- Choix du dossier ---
$sauvegarde = installerVersionNeuve();
creerEssaiRate('2099-12-31');
creerSauvegardes(['zzz-posee-a-la-main']);
$retour = retourArriere();
verifie($retour['code'] === 0 && str_contains($retour['sortie'], "sauvegarde $sauvegarde"), "essai raté : il est ignoré, et le plus récent avec un src/ est choisi (".$retour['sortie'].")");
verifierCodeRevenu("essai raté");
exec('rm -rf '.BACKUPS.'/zzz-posee-a-la-main');

$sauvegarde = installerVersionNeuve();
creerEssaiRate('2099-12-31');
$avant = sauvegardes();
foreach (['2099-12-31' => 'pas de src/', 'nexistepas' => 'dossier absent', '..' => 'chemin refusé', '--inconnu' => 'option inconnue', "$sauvegarde deux" => 'deux dossiers'] as $argument => $cas) {
	$retour = retourArriere($argument);
	verifie($retour['code'] !== 0 && str_contains($retour['sortie'], 'a échoué'), "argument refusé, $cas : une erreur claire (".$retour['sortie'].")");
}
verifierRienNEstRevenu("argument refusé", $avant);

// --- --avec-bd : la BD redevient celle de la sauvegarde, tables créées depuis comprises ---
reinstaller();
connexionBD()->query("INSERT INTO parameters (paramname, paramvalue) VALUES ('banc', 'été à Québec')");
publierVersion();
cliquerMettreAJour();
$sauvegarde = sauvegardesAvecSrc()[0];
connexionBD()->query("UPDATE parameters SET paramvalue = 'changé depuis' WHERE paramname = 'banc'");
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] === 0, "--avec-bd : il réussit (".$retour['sortie'].")");
verifie(str_contains($retour['sortie'], 'ATTENTION') && str_contains($retour['sortie'], 'est perdu'), "--avec-bd : il rappelle que ce qui s'est passé depuis est perdu");
verifierCodeRevenu("--avec-bd");
verifie(versionBD() === $versionDepart && !tableMigree(), "--avec-bd : le compteur recule et la table créée depuis est retirée");
$valeur = connexionBD()->query("SELECT paramvalue FROM parameters WHERE paramname = 'banc'")->fetch_row()[0];
verifie($valeur === 'été à Québec', "--avec-bd : les données reviennent, accents compris (obtenu : $valeur)");
$gardee = glob(BACKUPS.'/*/BD.sql');
verifie(count($gardee) === 2 && array_filter($gardee, fn(string $dump) => str_contains((string)file_get_contents($dump), 'changé depuis')) !== [], "--avec-bd : la BD d'avant le retour arrière est gardée");
publierVersion();
cliquerMettreAJour();
verifierInstallee("--avec-bd, puis mise à jour");

// --- --avec-bd vers une sauvegarde de l'ancienne disposition : avant la v134, DatabaseManager était dans commun/databasemanager.php ---
// (et dans ringgroupupdater/ avant la v130). jobRestore.php le charge alors de là ; depuis la v134, l'autoload de l'amorce le trouve seul.
// Le code de cette sauvegarde fabriquée n'est pas celui d'une vraie v133 : seule la remise de la BD est vérifiée, puis on réinstalle.
foreach (['commun/databasemanager.php' => 'commun/ (v130 à v133)', 'ringgroupupdater/databasemanager.php' => 'ringgroupupdater/ (avant la v130)'] as $ancienChemin => $disposition) {
	reinstaller();
	connexionBD()->query("INSERT INTO parameters (paramname, paramvalue) VALUES ('banc', 'avant le retour')");
	publierVersion();
	cliquerMettreAJour();
	$sauvegarde = sauvegardesAvecSrc()[0];
	@mkdir(dirname(BACKUPS."/$sauvegarde/src/$ancienChemin"), 0775, true);
	rename(BACKUPS."/$sauvegarde/src/modules/donnees/DatabaseManager.php", BACKUPS."/$sauvegarde/src/$ancienChemin");
	exec('chown -R www-data:www-data '.BACKUPS);
	connexionBD()->query("UPDATE parameters SET paramvalue = 'changé depuis' WHERE paramname = 'banc'");
	$retour = retourArriere("$sauvegarde --avec-bd");
	$valeur = connexionBD()->query("SELECT paramvalue FROM parameters WHERE paramname = 'banc'")->fetch_row()[0] ?? '';
	verifie($retour['code'] === 0 && str_contains($retour['sortie'], 'La BD est restaurée') && $valeur === 'avant le retour',
		"--avec-bd, sauvegarde de la disposition $disposition : DatabaseManager est trouvé et la BD redevient celle de la sauvegarde (code {$retour['code']}, $valeur : ".substr($retour['sortie'], -160).")");
}
reinstaller();

// Sans BD.sql : erreur avant d'avoir touché à quoi que ce soit
$sansSql = fn(string $racine) => remplacerMigration($racine, [['noVersion' => VERSION_NEUVE, 'sql' => '']]);
reinstaller();
publierVersion($sansSql);
cliquerMettreAJour();
$avant = sauvegardes();
$retour = retourArriere('--avec-bd');
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "n'a pas de BD.sql"), "--avec-bd sans BD.sql : une erreur claire (".$retour['sortie'].")");
verifie(versionCode() === VERSION_NEUVE && sauvegardes() === $avant && sonnerieFonctionne(), "--avec-bd sans BD.sql : rien n'a changé");

// Un dump coupé (disque plein) effacerait des tables sans les remettre
$sauvegarde = installerVersionNeuve();
$avant = sauvegardes();
$dump = BACKUPS."/$sauvegarde/BD.sql";
file_put_contents($dump, substr((string)file_get_contents($dump), 0, intval(filesize($dump) / 2)));
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], 'incomplet'), "dump coupé : une erreur claire (".$retour['sortie'].")");
verifierRienNEstRevenu("dump coupé", $avant);

// Un BD.sql qui n'a pas la date de son dossier : l'ancienne mise à jour en a laissé dans ses sauvegardes
$changerDate = function (string $sauvegarde, string $ligne) {
	$dump = BACKUPS."/$sauvegarde/BD.sql";
	file_put_contents($dump, preg_replace('/^-- Date: .*\n/m', $ligne, (string)file_get_contents($dump)));
};
$sauvegarde = installerVersionNeuve();
$avant = sauvegardes();
$changerDate($sauvegarde, "-- Date: Mon, 25 Aug 2025 10:00:00 -0400\n");
$retour = retourArriere("$sauvegarde --avec-bd");
$dateAttendue = substr($sauvegarde, 0, 10);
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], '--bd-ancienne'), "dump périmé : il est refusé, et le message dit comment passer outre (".$retour['sortie'].")");
verifie(str_contains($retour['sortie'], 'date trouvée dans BD.sql : 2025-08-25') && str_contains($retour['sortie'], "date attendue : $dateAttendue"), "dump périmé : le message dit la date trouvée et la date attendue");
verifierRienNEstRevenu("dump périmé", $avant);

$retour = retourArriere("$sauvegarde --bd-ancienne");
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "ne va qu'avec --avec-bd"), "--bd-ancienne seule : refusée (".$retour['sortie'].")");
verifierRienNEstRevenu("--bd-ancienne seule", $avant);

$changerDate($sauvegarde, '');
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], 'ligne -- Date: est absente'), "dump sans date : il est refusé (".$retour['sortie'].")");
verifierRienNEstRevenu("dump sans date", $avant);

$retour = retourArriere("$sauvegarde --avec-bd --bd-ancienne");
verifie($retour['code'] === 0, "--bd-ancienne : le dump passe quand même (".$retour['sortie'].")");
verifierCodeRevenu("--bd-ancienne");
verifie(versionBD() === $versionDepart && !tableMigree(), "--bd-ancienne : la BD revient");

// Un jour d'écart est permis, à cause des fuseaux horaires, mais pas deux, même s'ils enjambent le passage à l'heure
// d'été (le 8 mars 2026) : il n'y a alors que 47 heures du 7 au 9 mars
$sauvegarde = installerVersionNeuve();
rename(BACKUPS."/$sauvegarde", BACKUPS.'/2026-03-09');
$avant = sauvegardes();
$changerDate('2026-03-09', "-- Date: Sat, 07 Mar 2026 12:00:00 -0500\n");
$retour = retourArriere('2026-03-09 --avec-bd');
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], 'date trouvée dans BD.sql : 2026-03-07'), "dump de deux jours d'écart, au changement d'heure : refusé (".$retour['sortie'].")");
verifierRienNEstRevenu("dump de deux jours d'écart", $avant);
$changerDate('2026-03-09', "-- Date: Sun, 08 Mar 2026 12:00:00 -0400\n");
$retour = retourArriere('2026-03-09 --avec-bd');
verifie($retour['code'] === 0, "dump d'un jour d'écart : il passe (".$retour['sortie'].")");
verifie(versionBD() === $versionDepart, "dump d'un jour d'écart : la BD revient");

// Un dossier sans date dans son nom : rien à comparer, donc pas sans --bd-ancienne
$sauvegarde = installerVersionNeuve();
rename(BACKUPS."/$sauvegarde", BACKUPS.'/avant-migration');
$avant = sauvegardes();
$retour = retourArriere('avant-migration --avec-bd');
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "date attendue : inconnue"), "dossier sans date : refusé (".$retour['sortie'].")");
verifierRienNEstRevenu("dossier sans date", $avant);

// La BD ne se restaure pas : le code est revenu, et le message le dit
$sauvegarde = installerVersionNeuve();
file_put_contents(BACKUPS."/$sauvegarde/BD.sql", "-- Date: ".date('r')."\nCECI N'EST PAS DU SQL;\nDROP TABLE IF EXISTS `banc`;\n-- Dump completed\n");
$retour = retourArriere("$sauvegarde --avec-bd");
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "Le code est en place, mais la BD n'a pas été restaurée"), "BD en échec : le message dit où on en est (".$retour['sortie'].")");
verifierCodeRevenu("BD en échec");
verifie(versionBD() === VERSION_NEUVE, "BD en échec : la BD reste en place");

// --- Depuis un dossier de sauvegarde, quand src/ est absent ou cassé ---
$sauvegarde = installerVersionNeuve();
$scriptSauvegarde = BACKUPS."/$sauvegarde/src/utils/jobRestore.php";
exec('rm -rf '.APPLI.'/src');
$retour = retourArriere($sauvegarde, $scriptSauvegarde);
verifie($retour['code'] === 0, "src/ absent : le retour arrière réussit depuis la sauvegarde (".$retour['sortie'].")");
verifierCodeRevenu("src/ absent");
verifie(is_file($scriptSauvegarde) && !file_exists(BACKUPS."/$sauvegarde/backups"), "src/ absent : rien n'est écrit dans la sauvegarde");
verifie(sauvegardesAvecSrc() === [$sauvegarde], "src/ absent : aucun dossier vide ne reste pour le code remplacé");

$sauvegarde = installerVersionNeuve();
unlink(APPLI.'/src/commun/lesconstantes.php');
$retour = retourArriere("$sauvegarde --avec-bd", BACKUPS."/$sauvegarde/src/utils/jobRestore.php");
verifie($retour['code'] === 0, "src/ cassé, avec la BD : le retour arrière réussit (".$retour['sortie'].")");
verifierCodeRevenu("src/ cassé, avec la BD");
verifie(versionBD() === $versionDepart && !tableMigree(), "src/ cassé, avec la BD : la BD revient aussi");

// --- Refus ---
$sauvegarde = installerVersionNeuve();
$avant = sauvegardes();
exec('php '.JOB_RESTORE.' 2>&1', $sortieRoot, $codeRoot);
verifie($codeRoot !== 0 && str_contains(implode(' ', $sortieRoot), 'docker exec -u www-data'), "refus : sous root, avec la marche à suivre");
$autreCompte = retourArriere('', JOB_RESTORE, 'nobody');
verifie($autreCompte['code'] !== 0 && str_contains($autreCompte['sortie'], 'propriétaire de src/'), "refus : sous un autre compte que celui de src/ (".$autreCompte['sortie'].")");
oublierSession();
$web = requetePortail(dirname(PORTAIL).'/utils/jobRestore.php?key=clefactice');
verifie($web['code'] === 403, "refus : par le web, même avec la clé de cron (code {$web['code']})");
verifierRienNEstRevenu("refus", $avant);

// --- Verrous occupés : rien ne change ---
foreach (['refreshallringgroups' => 'cron', 'miseajour' => 'mise à jour'] as $verrou => $cas) {
	$avant = sauvegardes();
	$bloqueur = tenirVerrou($verrou);
	$retour = retourArriere();
	libererVerrou($bloqueur);
	verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "Rien n'a changé"), "verrou occupé, $cas : le retour arrière le dit (".$retour['sortie'].")");
	verifierRienNEstRevenu("verrou occupé, $cas", $avant);
}

// Les verrous sont tenus pendant le remplacement : un cron saute son passage, sans signal d'échec
$sauvegarde = installerVersionNeuve();
$retour = demarrerRetourQuiAttend($sauvegarde);
viderJournalFaux();
$cron = lancerCron('refreshallringgroups.php');
verifie($cron['code'] === 0 && str_contains($cron['sortie'], 'mise à jour de SwitchBoard en cours'), "cron pendant le retour arrière : le passage saute");
verifie(!in_array('/banc-ringgroup/fail', pingsSignalDeVie('ringgroup'), true) && methodesVoipms('banc-autonome') === [], "cron pendant le retour arrière : aucun signal d'échec, aucun appel à voip.ms");
$miseAJour = miseAJourParSsh();
verifie($miseAJour['code'] !== 0 && str_contains($miseAJour['sortie'], 'déjà en cours'), "mise à jour pendant le retour arrière : elle refuse de partir");
verifie(laisserContinuer($retour, $sauvegarde) === 0, "cron pendant le retour arrière : le retour arrière va au bout (".sortieDuRetour().")");
verifierCodeRevenu("cron pendant le retour arrière");

// --- Pannes ---
// La copie échoue sur un fichier illisible : rien n'a changé, et il ne reste rien de la copie
$sauvegarde = installerVersionNeuve();
$avant = sauvegardes();
$illisible = BACKUPS."/$sauvegarde/src/managementportal.php";
exec("chown root:root $illisible && chmod 600 $illisible");
$retour = retourArriere();
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "Rien n'a changé"), "copie en échec : le retour arrière le dit (".$retour['sortie'].")");
exec("chown www-data:www-data $illisible && chmod 644 $illisible");
verifierRienNEstRevenu("copie en échec", $avant);

// Le premier renommage de src/ échoue, après celui de vendor/ : l'ancien vendor/ revient
$sauvegarde = installerVersionNeuve(fn(string $racine) => changerDependances($racine));
$avant = sauvegardes();
exec('chown root:root '.APPLI.'/src && chmod 755 '.APPLI.'/src');
$retour = retourArriere();
exec('chown www-data:www-data '.APPLI.'/src');
verifie($retour['code'] !== 0 && str_contains($retour['sortie'], "rien n'a été remplacé"), "src/ impossible à déplacer : le retour arrière le dit (".$retour['sortie'].")");
verifie(is_file(APPLI.'/vendor/marqueur-banc'), "src/ impossible à déplacer : le vendor/ d'avant le retour arrière revient");
verifierRienNEstRevenu("src/ impossible à déplacer", $avant);

// Le second renommage de src/ échoue : src/ et vendor/ reviennent. Pendant la copie, la copie de src/ devient impossible
// à déplacer pour www-data : déplacer un dossier vers un autre parent demande le droit d'écriture sur ce dossier.
$sauvegarde = installerVersionNeuve(fn(string $racine) => changerDependances($racine));
$avant = sauvegardes();
$retour = demarrerRetourQuiAttend($sauvegarde);
$copieSrc = glob(BACKUPS.'/*/preparation/src')[0];
$dossierTemporaire = dirname($copieSrc, 2);
exec("chown root:root $copieSrc && chmod 555 $copieSrc");
$code = laisserContinuer($retour, $sauvegarde);
$sortie = sortieDuRetour();
exec('rm -rf '.escapeshellarg($dossierTemporaire));
verifie($code !== 0 && str_contains($sortie, "l'ancien a été remis"), "second renommage en échec : le retour arrière le dit ($sortie)");
verifie(is_file(APPLI.'/vendor/marqueur-banc') && is_file(APPLI.'/vendor/autoload.php'), "second renommage en échec : vendor/ revient");
verifierRienNEstRevenu("second renommage en échec", $avant);

reinstaller();
oublierCopieInstallation();
finDuScenario();
