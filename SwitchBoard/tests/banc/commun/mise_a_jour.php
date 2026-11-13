<?php
// Ce que les scénarios de la mise à jour et des sauvegardes ont en commun : une nouvelle version sur le faux GitHub,
// le bouton de la console, la commande par SSH, et ce qu'on vérifie après chaque panne.

const VERSION_NEUVE = '9999';
const VERSION_INTERMEDIAIRE = '9998';
const COPIE_SRC = '/tmp/src-origine';
const COPIE_VENDOR = '/tmp/vendor-origine';
const FICHIER_VERSION_CODE = 'src/updatejson/codeVersion.json';
const RACINE_ARCHIVE = 'Switchboarders-SwitchBoard-abc1234';
const SHA_DE_L_ARCHIVE = 'abc1234aaaabbbbccccddddeeeeffff0000111122';
const DEPOT_NEUF = '/tmp/depot-neuf';
const FICHIER_VERROU_TENU = '/tmp/verrou-tenu';
const FICHIER_GARDER_VERROU = '/tmp/garder-verrou';

// La copie de l'installation que reinstaller() remet en place. Une par scénario : au début, puis à la fin.
function garderCopieInstallation(): void {
	exec('cp -a '.APPLI.'/src '.COPIE_SRC);
	exec('cp -a '.APPLI.'/vendor '.COPIE_VENDOR);
}
function oublierCopieInstallation(): void {
	exec('rm -rf '.COPIE_SRC.' '.COPIE_VENDOR.' '.DEPOT_NEUF.' /tmp/marqueur');
}

// Le nouveau dépôt sur le faux GitHub. $modifier reçoit le dossier du dépôt avant la création de l'archive.
function publierVersion(?callable $modifier = null, bool $avecArchive = true): void {
	exec('rm -rf '.DEPOT_NEUF.' '.ETAT_BANC.'/github');
	$racine = DEPOT_NEUF.'/'.RACINE_ARCHIVE;
	mkdir("$racine/SwitchBoard", 0755, true);
	exec('cp -a '.COPIE_SRC." $racine/SwitchBoard/src");
	$updatejson = "$racine/SwitchBoard/src/updatejson";
	file_put_contents("$updatejson/codeVersion.json", json_encode(['codeVersion' => VERSION_NEUVE]));
	file_put_contents("$updatejson/bancmigration.sql", "CREATE TABLE bancmigration (id int);\n");
	$versions = json_decode(file_get_contents("$updatejson/dbVersion.json"), true);
	$versions['versions'][] = ['noVersion' => VERSION_NEUVE, 'file' => 'bancmigration.sql'];
	file_put_contents("$updatejson/dbVersion.json", json_encode($versions));

	// Les fichiers de version sont publiés avant la panne : la console doit toujours voir la nouvelle version
	$publie = ETAT_BANC.'/github/depot/SwitchBoard/src/updatejson';
	mkdir($publie, 0755, true);
	copy("$updatejson/codeVersion.json", "$publie/codeVersion.json");
	copy("$updatejson/dbVersion.json", "$publie/dbVersion.json");

	if ($modifier !== null) {
		$modifier($racine);
	}
	if ($avecArchive) {
		exec('cd '.DEPOT_NEUF.' && zip -q -r '.ETAT_BANC.'/github/depot.zip '.RACINE_ARCHIVE);
	}
	exec('chown -R www-data:www-data '.ETAT_BANC.'/github');
}

// Remplace la migration de la nouvelle version par ces entrées
function remplacerMigration(string $racine, array $entrees): void {
	$fichier = "$racine/SwitchBoard/src/updatejson/dbVersion.json";
	$versions = json_decode(file_get_contents($fichier), true);
	array_pop($versions['versions']);
	file_put_contents($fichier, json_encode(['versions' => array_merge($versions['versions'], $entrees)]));
}

// Une première entrée qui dort, le temps pour le scénario d'agir pendant la mise à jour, puis la vraie migration
function migrationQuiDort(string $racine, int $secondes): void {
	remplacerMigration($racine, [['noVersion' => VERSION_INTERMEDIAIRE, 'sql' => "SELECT SLEEP($secondes)"], ['noVersion' => VERSION_NEUVE, 'file' => 'bancmigration.sql']]);
}

// Ajoute un fichier au zip de dépendances : il a changé, et vendor/ doit être remplacé
function changerDependances(string $racine): void {
	$zip = glob("$racine/SwitchBoard/src/precompiled*.zip")[0];
	exec("mkdir -p /tmp/marqueur/vendor && touch /tmp/marqueur/vendor/marqueur-banc && cd /tmp/marqueur && zip -q $zip vendor/marqueur-banc");
}

// Une installation à la version actuelle avec un comité de garde. Un seul comité : le cron reste rapide.
// vendor/ est recopié à chaque fois parce qu'un dossier de l'image Docker ne peut pas être renommé (EXDEV) :
// sur les serveurs, il est sur un disque ordinaire.
function reinstaller(): void {
	exec('rm -rf '.APPLI.'/src '.APPLI.'/vendor '.APPLI.'/backups/* '.APPLI.'/download/* '.APPLI.'/config/locks');
	exec('cp -a '.COPIE_SRC.' '.APPLI.'/src');
	exec('cp -a '.COPIE_VENDOR.' '.APPLI.'/vendor');
	exec('chown -R www-data:www-data '.APPLI.'/backups '.APPLI.'/download');
	repartirAZero();
	sonnerieSeulementPour([2]);
	ecrireAgenda('autonome', [['nom' => 'Jean', 'description' => '514-555-0111', 'debut' => time() - 3600, 'fin' => time() + 3600]]);
}

// Se connecte comme admin et clique sur « mettre à jour » dans la console. Retourne la page.
function cliquerMettreAJour(): string {
	connecterAuPortail('admin');
	$console = requetePortail(PORTAIL.'?page=adminpanel')['page'];
	$aLeBouton = preg_match("/name='csrf_token' value='([^']+)'/", $console, $jeton) === 1 && str_contains($console, 'update_choice');
	verifie($aLeBouton, "la console offre la nouvelle version");
	if (!$aLeBouton) {
		return $console;
	}
	return requetePortail(PORTAIL.'?page=adminpanel', ['csrf_token' => $jeton[1], 'update_choice' => 'yes'])['page'];
}

// Lance src/miseajour.php comme par SSH, sous le compte du serveur web. Retourne ['code', 'sortie'].
function miseAJourParSsh(string $optionsPhp = ''): array {
	exec('su -s /bin/sh www-data -c '.escapeshellarg("php $optionsPhp ".APPLI.'/src/miseajour.php 2>&1'), $sortie, $code);
	return ['code' => $code, 'sortie' => implode(' | ', $sortie)];
}

// Démarre la mise à jour par SSH sans attendre sa fin, et revient quand elle est rendue à sa migration qui dort
function demarrerMiseAJourQuiDort() {
	$processus = proc_open(['su', '-s', '/bin/sh', 'www-data', '-c', 'php '.APPLI.'/src/miseajour.php'],
		[1 => ['file', '/tmp/sortie-maj', 'w'], 2 => ['file', '/tmp/sortie-maj', 'a']], $tuyaux);
	$limite = time() + 60;
	while (time() < $limite) {
		$requete = "SELECT COUNT(*) FROM information_schema.processlist WHERE info LIKE 'SELECT SLEEP%'";
		if (connexionBD()->query($requete)->fetch_row()[0] > 0) {
			return $processus;
		}
		usleep(100000);
	}
	throw new RuntimeException("La mise à jour n'est jamais arrivée à sa migration : ".file_get_contents('/tmp/sortie-maj'));
}

// kill -9 de la mise à jour lancée par demarrerMiseAJourQuiDort()
function tuerMiseAJour($processus): void {
	foreach (glob('/proc/[0-9]*/cmdline') as $fichier) {
		if (str_contains((string)@file_get_contents($fichier), 'src/miseajour.php')) {
			posix_kill((int)basename(dirname($fichier)), 9);
		}
	}
	proc_close($processus);
}

// Tient un verrou sous un autre processus, comme le ferait un cron en marche
function tenirVerrou(string $nom) {
	touch(FICHIER_GARDER_VERROU);
	$code = 'require "'.APPLI.'/src/utils/verrouexclusif.php"; $verrou = new VerrouExclusif("'.$nom.'"); $verrou->acquerirSansAttendre();'
		.' touch("'.FICHIER_VERROU_TENU.'"); while (file_exists("'.FICHIER_GARDER_VERROU.'")) { usleep(100000); }';
	$processus = proc_open(['su', '-s', '/bin/sh', 'www-data', '-c', 'php -r '.escapeshellarg($code)], [], $tuyaux);
	while (!file_exists(FICHIER_VERROU_TENU)) {
		usleep(100000);
	}
	return $processus;
}
function libererVerrou($processus): void {
	unlink(FICHIER_GARDER_VERROU);
	proc_close($processus);
	unlink(FICHIER_VERROU_TENU);
}

function versionCode(): string {
	return (string)(json_decode((string)@file_get_contents(APPLI.'/'.FICHIER_VERSION_CODE))->codeVersion ?? '?');
}
function versionBD(): string {
	return connexionBD()->query("SELECT paramvalue FROM parameters WHERE paramname = 'db version'")->fetch_row()[0];
}
function tableMigree(): bool {
	return connexionBD()->query("SHOW TABLES LIKE 'bancmigration'")->num_rows === 1;
}
// Après n'importe quelle panne, le téléphone de garde doit continuer de fonctionner
function sonnerieFonctionne(): bool {
	viderJournalFaux();
	exec('rm -f '.ETAT_BANC.'/voipms-banc-autonome.json');
	connexionBD()->query('DELETE FROM ringgroupstate');
	lancerCron('refreshallringgroups.php');
	return groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111']];
}
function coherent(): bool {
	return versionCode() === versionBD() && (versionCode() === VERSION_NEUVE) === tableMigree();
}

function verifierRienNAChange(string $panne): void {
	global $versionDepart;
	verifie(versionCode() === $versionDepart, "$panne : l'ancien code reste en place (code ".versionCode().")");
	verifie(is_file(APPLI.'/src/managementportal.php'), "$panne : src/ est intact");
	verifie(versionBD() === $versionDepart, "$panne : le compteur de BD ne bouge pas (obtenu : ".versionBD().")");
	verifie(coherent(), "$panne : le code et la BD restent cohérents");
	verifie(is_file(APPLI.'/vendor/autoload.php'), "$panne : vendor/ est intact");
	verifie(sonnerieFonctionne(), "$panne : le groupe de sonnerie fonctionne après");
}

function verifierInstallee(string $panne): void {
	verifie(versionCode() === VERSION_NEUVE, "$panne : le nouveau code est en place (code ".versionCode().")");
	verifie(versionBD() === VERSION_NEUVE && tableMigree(), "$panne : la migration est faite et le compteur a avancé (BD ".versionBD().")");
	verifie(sonnerieFonctionne(), "$panne : le groupe de sonnerie fonctionne après");
}

// La cause est réparée : la bonne version est publiée, et le bouton de la console finit le travail
function verifierRelance(string $panne): void {
	publierVersion();
	cliquerMettreAJour();
	verifierInstallee("$panne, relance");
}
