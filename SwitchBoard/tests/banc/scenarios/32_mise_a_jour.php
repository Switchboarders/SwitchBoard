#!/usr/bin/env php
<?php
// La mise à jour automatique, lancée comme un admin par la vraie console et comme un tech par SSH : une nouvelle
// version sur le faux GitHub, puis une panne provoquée à chaque point. La règle : une panne avant le remplacement
// de src/ laisse l'ancien code en marche, le téléphone de garde sonne, et on peut relancer.
require_once '/banc/commun/banc.php';
require_once '/banc/commun/mise_a_jour.php';

garderCopieInstallation();
$versionDepart = versionCode();

// --- Le moteur respecte les couches : rien à l'écran, rien de la requête, pas de SQL ---
$moteur = php_strip_whitespace(APPLI.'/src/modules/systeme/MiseAJour.php');
verifie(!preg_match('/\b(echo|print|die|exit)\b|\$_(POST|GET|REQUEST)|\b(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE) /', $moteur), "MiseAJour.php : ni echo, ni die, ni \$_POST, ni SQL");

// --- La mise à jour normale ---
reinstaller();
publierVersion();
$page = cliquerMettreAJour();
verifierInstallee("mise à jour normale");
verifie(glob(APPLI.'/backups/*/src') !== [], "mise à jour normale : l'ancien code est sauvegardé");
verifie(glob(APPLI.'/backups/*/BD.sql') !== [], "mise à jour normale : la BD est sauvegardée");
verifie(glob(APPLI.'/backups/*/vendor') === [] && !is_file(APPLI.'/vendor/marqueur-banc'), "mise à jour normale : vendor/ n'est pas remplacé, le zip est le même");
verifie(is_file(APPLI.'/backups/.htaccess') && is_file(APPLI.'/download/.htaccess'), "mise à jour normale : backups/ et download/ restent protégés");
verifie(is_file(APPLI.'/config/config.php'), "mise à jour normale : la configuration reste");
verifie(glob(APPLI.'/download/*') === [], "mise à jour normale : download/ est nettoyé");
verifie(str_contains($page, 'Mise à jour terminée') && str_contains($page, 'page=adminpanel'), "mise à jour normale : la console dit que c'est fini et offre le retour");
verifie(!preg_match('/erreur fatale|Fatal error/i', $page), "mise à jour normale : aucune erreur fatale à l'écran");
verifie(posix_getpwuid(fileowner(APPLI.'/src'))['name'] === 'www-data', "mise à jour normale : src/ appartient à www-data");
verifie(in_array(fileperms(APPLI.'/src/languages') & 0777, [0755, 0775], true), "mise à jour normale : dossiers en 0775 moins le umask, comme recurse_copy()");
verifie(glob(APPLI.'/logs/*log.txt') !== [], "mise à jour normale : le cron écrit son journal dans logs/, à la racine");
$reponse = requetePortail(PORTAIL.'?page=adminpanel');
verifie($reponse['code'] === 200 && !preg_match('/Fatal error|Uncaught /', $reponse['page']), "mise à jour normale : la console répond sur le nouveau code");

// La bannière a disparu, et une relance par SSH ne remplace rien pour rien
verifie(!str_contains($reponse['page'], 'update_choice'), "mise à jour normale : la bannière a disparu");
$sauvegardes = glob(APPLI.'/backups/*');
$relance = miseAJourParSsh();
verifie($relance['code'] === 0 && str_contains($relance['sortie'], 'déjà à jour'), "déjà à jour : la relance le dit (".$relance['sortie'].")");
verifie(glob(APPLI.'/backups/*') === $sauvegardes, "déjà à jour : aucune sauvegarde de plus");

// --- Rien du nouveau src/ n'est inclus après son remplacement ---
reinstaller();
$bombe = "<?php throw new Exception('BANC : inclus depuis le nouveau src/');\n";
publierVersion(function (string $racine) use ($bombe) {
	foreach (['pages/admin/successpage.php', 'pages/utilisateur/footer.php', 'pages/utilisateur/header.php'] as $page) {
		file_put_contents("$racine/SwitchBoard/src/portail/$page", $bombe);
	}
});
$page = cliquerMettreAJour();
verifie(versionCode() === VERSION_NEUVE, "fin de mise à jour : le nouveau code est en place");
verifie(str_contains($page, 'Mise à jour terminée') && !str_contains($page, 'BANC : inclus'), "fin de mise à jour : rien du nouveau src/ n'est inclus");
verifie(sonnerieFonctionne(), "fin de mise à jour : le groupe de sonnerie fonctionne après");

// Tous les .php du nouveau src/ piégés, par la console puis par SSH : avec l'autoload de l'amorce, une classe utilisée
// pour la première fois après le remplacement serait chargée depuis le nouveau src/. Le piège laisse une trace sur le
// disque, que son erreur s'affiche ou non.
$trace = ETAT_BANC.'/piege-declenche';
$piegerToutSrc = function (string $racine) use ($trace) {
	$piege = "<?php file_put_contents('$trace', __FILE__.\"\\n\", FILE_APPEND); throw new Exception('BANC : inclus depuis le nouveau src/');\n";
	foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$racine/SwitchBoard/src", FilesystemIterator::SKIP_DOTS)) as $fichier) {
		if ($fichier->getExtension() === 'php') {
			file_put_contents($fichier->getPathname(), $piege);
		}
	}
};
foreach (['la console' => fn() => cliquerMettreAJour(), 'SSH' => fn() => miseAJourParSsh()['sortie']] as $par => $lancer) {
	reinstaller();
	publierVersion($piegerToutSrc);
	@unlink($trace);
	$sortie = $lancer();
	verifie(versionCode() === VERSION_NEUVE && str_contains($sortie, 'Mise à jour terminée'), "tout le nouveau src/ piégé, par $par : la mise à jour finit");
	verifie(!file_exists($trace) && !str_contains($sortie, 'BANC : inclus'), "tout le nouveau src/ piégé, par $par : aucun de ses .php n'est chargé (".trim((string)@file_get_contents($trace)).")");
}
// Le piège lui-même fonctionne : le nouveau code, une fois chargé pour de bon, le déclenche
requetePortail(PORTAIL);
miseAJourParSsh();
$traces = (string)@file_get_contents($trace);
verifie(str_contains($traces, '/src/managementportal.php') && str_contains($traces, '/src/miseajour.php'), "le piège se déclenche dès que le portail ou miseajour.php tourne sur le nouveau code");

// --- Une version sans SQL : le compteur avance, sans sauvegarde de la BD ---
reinstaller();
publierVersion(fn(string $racine) => remplacerMigration($racine, [['noVersion' => VERSION_INTERMEDIAIRE, 'sql' => ''], ['noVersion' => (int)VERSION_NEUVE, 'sql' => '']]));
cliquerMettreAJour();
verifie(versionCode() === VERSION_NEUVE && versionBD() === VERSION_NEUVE, "version sans SQL : le code et le compteur avancent");
verifie(glob(APPLI.'/backups/*/src') !== [] && glob(APPLI.'/backups/*/BD.sql') === [], "version sans SQL : aucune sauvegarde de la BD");
verifie(sonnerieFonctionne(), "version sans SQL : le groupe de sonnerie fonctionne après");

// --- De nouvelles dépendances : vendor/ est remplacé, par la console ---
reinstaller();
publierVersion(fn(string $racine) => changerDependances($racine));
cliquerMettreAJour();
verifierInstallee("nouvelles dépendances");
verifie(is_file(APPLI.'/vendor/marqueur-banc') && is_file(APPLI.'/vendor/autoload.php'), "nouvelles dépendances : vendor/ est remplacé");
verifie(glob(APPLI.'/backups/*/vendor/autoload.php') !== [], "nouvelles dépendances : l'ancien vendor/ est sauvegardé");

// --- Une version publiée : l'archive doit être celle de son commit ---
$publierRelease = function (string $sha) {
	file_put_contents(ETAT_BANC.'/github/release.json', json_encode(['tag_name' => 'v-banc', 'sha_du_banc' => $sha]));
	exec('chown www-data:www-data '.ETAT_BANC.'/github/release.json');
};
reinstaller();
publierVersion();
$publierRelease(str_repeat('f', 40));
cliquerMettreAJour();
verifierRienNAChange("archive d'une autre version");
verifie(glob(APPLI.'/backups/*/src') === [], "archive d'une autre version : aucun remplacement commencé");
$publierRelease(SHA_DE_L_ARCHIVE);
cliquerMettreAJour();
verifierInstallee("archive de la bonne version");

// --- Pannes avant tout changement : à chaque fois, l'ancien code et la BD ne bougent pas, et on relance ---
$modifierJson = fn(string $fichier, string $contenu) => fn(string $racine) => file_put_contents("$racine/SwitchBoard/src/updatejson/$fichier", $contenu);
$pannes = [
	'archive absente' => fn() => publierVersion(null, false),
	'archive corrompue' => function () {
		publierVersion();
		file_put_contents(ETAT_BANC.'/github/depot.zip', 'pas une archive');
	},
	'archive tronquée' => function () {
		publierVersion();
		$archive = ETAT_BANC.'/github/depot.zip';
		file_put_contents($archive, substr(file_get_contents($archive), 0, intval(filesize($archive) / 2)));
	},
	'archive sans src/' => fn() => publierVersion(fn($racine) => exec("mv $racine/SwitchBoard/src $racine/SwitchBoard/autre")),
	'archive sans lesconstantes.php' => fn() => publierVersion(fn($racine) => unlink("$racine/SwitchBoard/src/commun/lesconstantes.php")),
	'dbVersion.json illisible' => fn() => publierVersion($modifierJson('dbVersion.json', 'pas du json')),
	'codeVersion.json illisible' => fn() => publierVersion($modifierJson('codeVersion.json', 'pas du json')),
	'dbVersion.json pas en ordre' => fn() => publierVersion(fn($racine) => remplacerMigration($racine, [
		['noVersion' => VERSION_NEUVE, 'sql' => ''], ['noVersion' => VERSION_INTERMEDIAIRE, 'sql' => '']])),
	'fichier de migration absent' => fn() => publierVersion(fn($racine) => unlink("$racine/SwitchBoard/src/updatejson/bancmigration.sql")),
	'dépendances corrompues' => fn() => publierVersion(fn($racine) => array_map(fn($zip) => file_put_contents($zip, 'pas une archive'), glob("$racine/SwitchBoard/src/precompiled*.zip"))),
	'zip de dépendances absent' => fn() => publierVersion(fn($racine) => array_map('unlink', glob("$racine/SwitchBoard/src/precompiled*.zip"))),
	'migration SQL invalide' => fn() => publierVersion($modifierJson('bancmigration.sql', "CECI N'EST PAS DU SQL;\n")),
	'erreur sur la 2e instruction' => fn() => publierVersion($modifierJson('bancmigration.sql', "SELECT 1; CECI N'EST PAS DU SQL;\n")),
];
foreach ($pannes as $panne => $preparer) {
	reinstaller();
	$preparer();
	$page = cliquerMettreAJour();
	verifie(str_contains($page, 'alert-error'), "$panne : la console dit que ça a échoué");
	verifierRienNAChange($panne);
	verifierRelance($panne);
}

// --- Deux migrations, la première bonne et la seconde invalide : le compteur s'arrête entre les deux ---
reinstaller();
$deuxMigrations = fn(string $seconde) => fn(string $racine) => remplacerMigration($racine, [
	['noVersion' => VERSION_INTERMEDIAIRE, 'sql' => 'CREATE TABLE bancmigration (id int);'], ['noVersion' => VERSION_NEUVE, 'sql' => $seconde]]);
publierVersion($deuxMigrations("CECI N'EST PAS DU SQL;"));
$page = cliquerMettreAJour();
verifie(versionBD() === VERSION_INTERMEDIAIRE && tableMigree(), "deux migrations : le compteur reste sur la première (BD ".versionBD().")");
verifie(versionCode() === $versionDepart, "deux migrations : l'ancien code reste en place");
verifie(preg_match('/'.VERSION_NEUVE.'.*'.VERSION_INTERMEDIAIRE.'/s', $page) === 1, "deux migrations : le message dit laquelle a échoué et où en est la BD");
verifie(sonnerieFonctionne(), "deux migrations : le groupe de sonnerie fonctionne après");
publierVersion($deuxMigrations('CREATE TABLE bancmigration2 (id int);'));
cliquerMettreAJour();
verifie(versionBD() === VERSION_NEUVE && versionCode() === VERSION_NEUVE, "deux migrations : la relance finit le travail");
verifie(connexionBD()->query("SHOW TABLES LIKE 'bancmigration2'")->num_rows === 1, "deux migrations : la relance ne joue que la seconde");
verifie(sonnerieFonctionne(), "deux migrations : le groupe de sonnerie fonctionne après la relance");

// --- Dossiers impossibles à écrire : arrêt au premier point. Par SSH : sans download/, la console ne voit même
// pas la nouvelle version. ---
$dossiersFermes = [
	'sauvegarde impossible' => APPLI.'/backups',
	'download/ fermé' => APPLI.'/download',
	'verrous fermés' => APPLI.'/config/locks',
];
foreach ($dossiersFermes as $panne => $dossier) {
	reinstaller();
	publierVersion();
	exec("mkdir -p $dossier && chown root:root $dossier && chmod 755 $dossier");
	$ssh = miseAJourParSsh();
	verifie($ssh['code'] !== 0 && str_contains($ssh['sortie'], "Droit d'écriture manquant"), "$panne : la mise à jour le dit (".$ssh['sortie'].")");
	verifie(glob(APPLI.'/backups/*/*') === [], "$panne : rien n'est sauvegardé ni déplacé");
	exec("chown -R www-data:www-data $dossier");
	verifierRienNAChange($panne);
	verifierRelance($panne);
}

// Des restes d'un essai précédent impossibles à effacer
reinstaller();
publierVersion();
exec('mkdir '.APPLI.'/download/reste && touch '.APPLI.'/download/reste/fichier && chown -R root:root '.APPLI.'/download/reste');
$ssh = miseAJourParSsh();
verifie($ssh['code'] !== 0 && str_contains($ssh['sortie'], 'download/'), "restes de download/ : la mise à jour le dit (".$ssh['sortie'].")");
exec('rm -rf '.APPLI.'/download/reste');
verifierRienNAChange("restes de download/");
verifierRelance("restes de download/");

// Un fichier de verrou à root laisserait croire au verrou pris, sans rien tenir
reinstaller();
publierVersion();
$fichierVerrou = APPLI.'/config/locks/refreshallringgroups.lock';
exec("mkdir -p ".dirname($fichierVerrou)." && chown www-data:www-data ".dirname($fichierVerrou)." && touch $fichierVerrou && chown root:root $fichierVerrou && chmod 644 $fichierVerrou");
cliquerMettreAJour();
exec("chown www-data:www-data $fichierVerrou");
verifierRienNAChange("fichier de verrou à root");
verifierRelance("fichier de verrou à root");

// La sauvegarde échoue alors que backups/ est ouvert : une vue l'arrête (elle ne copie que les tables, voir DumpBD)
reinstaller();
publierVersion();
$bd = connexionBD();
$bd->query('CREATE TABLE bancvue_source (id int)');
$bd->query('CREATE VIEW bancvue AS SELECT id FROM bancvue_source');
$bd->query('DROP TABLE bancvue_source');
$page = cliquerMettreAJour();
verifie(str_contains($page, 'Backup error'), "dump de la BD en échec : la console le dit");
$bd->query('DROP VIEW bancvue');
verifierRienNAChange("dump de la BD en échec");
verifierRelance("dump de la BD en échec");

// --- Le verrou du cron de sonnerie est occupé : arrêt, rien n'a changé ---
reinstaller();
publierVersion();
$bloqueur = tenirVerrou('refreshallringgroups');
$page = cliquerMettreAJour();
verifie(str_contains($page, 'quelques minutes'), "verrou occupé : la console demande de réessayer");
libererVerrou($bloqueur);
verifierRienNAChange("verrou occupé");
verifierRelance("verrou occupé");

// --- Deux mises à jour en même temps : la seconde n'efface pas le travail de la première ---
reinstaller();
publierVersion(fn(string $racine) => migrationQuiDort($racine, 4));
$miseAJour = demarrerMiseAJourQuiDort();
$seconde = miseAJourParSsh();
verifie($seconde['code'] !== 0 && str_contains($seconde['sortie'], 'déjà en cours'), "deux mises à jour : la seconde refuse de partir (".$seconde['sortie'].")");
proc_close($miseAJour);
verifierInstallee("deux mises à jour");

// --- Un cron qui tombe pendant la mise à jour : le passage saute, sans signal d'échec ---
reinstaller();
publierVersion(fn(string $racine) => migrationQuiDort($racine, 5));
$miseAJour = demarrerMiseAJourQuiDort();
viderJournalFaux();
$cron = lancerCron('refreshallringgroups.php');
verifie($cron['code'] === 0 && str_contains($cron['sortie'], 'mise à jour de SwitchBoard en cours'), "cron pendant la mise à jour : le passage saute (code {$cron['code']})");
verifie(!in_array('/banc-ringgroup/fail', pingsSignalDeVie('ringgroup'), true), "cron pendant la mise à jour : aucun signal d'échec (".implode(',', pingsSignalDeVie('ringgroup')).")");
verifie(methodesVoipms('banc-autonome') === [], "cron pendant la mise à jour : aucun appel à voip.ms");
$codeMiseAJour = proc_close($miseAJour);
verifie($codeMiseAJour === 0, "cron pendant la mise à jour : la mise à jour va au bout (code $codeMiseAJour)");
verifierInstallee("cron pendant la mise à jour");

// --- Un vrai chevauchement entre deux crons : le signal d'échec reste ---
reinstaller();
$bloqueur = tenirVerrou('refreshallringgroups');
viderJournalFaux();
$cron = lancerCron('refreshallringgroups.php');
verifie(str_contains($cron['sortie'], 'chevauchement détecté'), "vrai chevauchement : le cron abandonne");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "vrai chevauchement : le signal d'échec reste (".implode(',', pingsSignalDeVie('ringgroup')).")");
libererVerrou($bloqueur);
verifie(sonnerieFonctionne(), "vrai chevauchement : le groupe de sonnerie fonctionne après");

// --- Arrêt brutal au milieu d'une migration : l'ancien code tourne encore et la relance réussit ---
reinstaller();
publierVersion(fn(string $racine) => migrationQuiDort($racine, 4));
tuerMiseAJour(demarrerMiseAJourQuiDort());
verifierRienNAChange("arrêt brutal");
$relance = miseAJourParSsh();
verifie($relance['code'] === 0, "arrêt brutal : la relance réussit (".$relance['sortie'].")");
verifierInstallee("arrêt brutal, relance");

// La limite : une instruction déjà reçue par le serveur va au bout sans PHP, et le compteur reste en retard sur le
// schéma. La relance s'arrête, et son message dit quoi corriger.
reinstaller();
publierVersion(fn(string $racine) => remplacerMigration($racine, [['noVersion' => VERSION_NEUVE, 'sql' => 'SELECT SLEEP(1); CREATE TABLE bancmigration (id int);']]));
tuerMiseAJour(demarrerMiseAJourQuiDort());
sleep(2);
verifie(tableMigree() && versionBD() === $versionDepart, "arrêt brutal sur du DDL : le serveur finit l'instruction, le compteur reste en retard");
$relance = miseAJourParSsh();
verifie($relance['code'] !== 0 && str_contains($relance['sortie'], "ligne 'db version'"), "arrêt brutal sur du DDL : la relance s'arrête et dit quoi corriger");
verifie(versionCode() === $versionDepart && sonnerieFonctionne(), "arrêt brutal sur du DDL : l'ancien code tourne encore");
connexionBD()->query("UPDATE parameters SET paramvalue = '".VERSION_NEUVE."' WHERE paramname = 'db version'");
$relance = miseAJourParSsh();
verifie($relance['code'] === 0, "arrêt brutal sur du DDL : après correction du compteur, la relance réussit");
verifierInstallee("arrêt brutal sur du DDL, relance");

// --- Le remplacement de vendor/ échoue : l'ancien revient, et la relance finit le travail ---
// Pendant que la migration dort, le nouveau dossier devient impossible à déplacer pour www-data : déplacer un
// dossier vers un autre parent demande le droit d'écriture sur ce dossier. Aucun crochet dans le code.
reinstaller();
publierVersion(function (string $racine) {
	migrationQuiDort($racine, 3);
	changerDependances($racine);
});
$miseAJour = demarrerMiseAJourQuiDort();
$vendorNeuf = APPLI.'/download/dependances/vendor';
exec("chown root:root $vendorNeuf && chmod 555 $vendorNeuf");
$codeMiseAJour = proc_close($miseAJour);
verifie($codeMiseAJour !== 0, "remplacement de vendor/ en échec : la mise à jour le dit");
verifie(is_file(APPLI.'/vendor/autoload.php') && !is_file(APPLI.'/vendor/marqueur-banc'), "remplacement de vendor/ en échec : l'ancien vendor/ revient");
verifie(versionCode() === $versionDepart, "remplacement de vendor/ en échec : l'ancien code reste en place");
verifie(sonnerieFonctionne(), "remplacement de vendor/ en échec : le groupe de sonnerie fonctionne après");
exec("chown -R www-data:www-data ".APPLI."/download && chmod 755 $vendorNeuf");
cliquerMettreAJour();
verifie(versionCode() === VERSION_NEUVE && is_file(APPLI.'/vendor/marqueur-banc'), "remplacement de vendor/ en échec : la relance finit le travail");
verifie(sonnerieFonctionne(), "remplacement de vendor/ en échec : le groupe de sonnerie fonctionne après la relance");

// --- Le second renommage de src/ échoue : src/ revient, et vendor/ aussi. Même méthode que pour vendor/. ---
reinstaller();
publierVersion(function (string $racine) {
	migrationQuiDort($racine, 3);
	changerDependances($racine);
});
$miseAJour = demarrerMiseAJourQuiDort();
$srcNeuf = APPLI.'/download/'.RACINE_ARCHIVE.'/SwitchBoard/src';
exec("chown root:root $srcNeuf && chmod 555 $srcNeuf");
$codeMiseAJour = proc_close($miseAJour);
verifie($codeMiseAJour !== 0, "second renommage en échec : la mise à jour le dit (".file_get_contents('/tmp/sortie-maj').")");
verifie(is_file(APPLI.'/src/managementportal.php') && versionCode() === $versionDepart, "second renommage en échec : l'ancien src/ revient");
verifie(glob(APPLI.'/backups/*/src') === [], "second renommage en échec : il n'est plus dans backups/");
verifie(!is_file(APPLI.'/vendor/marqueur-banc') && is_file(APPLI.'/vendor/autoload.php'), "second renommage en échec : l'ancien vendor/ revient");
verifie(versionBD() === VERSION_NEUVE, "second renommage en échec : la BD garde sa migration (compatible avec l'ancien code)");
verifie(sonnerieFonctionne(), "second renommage en échec : le groupe de sonnerie fonctionne après");
exec("chown -R www-data:www-data ".APPLI."/download && chmod 755 $srcNeuf");
cliquerMettreAJour();
verifie(versionCode() === VERSION_NEUVE && is_file(APPLI.'/vendor/marqueur-banc'), "second renommage en échec : la relance finit le travail");
verifie(sonnerieFonctionne(), "second renommage en échec : le groupe de sonnerie fonctionne après la relance");

// --- src/miseajour.php : réussite sous www-data, sans allow_url_fopen comme sur cPanel ---
reinstaller();
publierVersion();
$ssh = miseAJourParSsh('-d allow_url_fopen=0');
verifie($ssh['code'] === 0 && str_contains($ssh['sortie'], 'Mise à jour terminée'), "par SSH : réussite sous www-data, sans allow_url_fopen (".$ssh['sortie'].")");
verifie(journalFaux('codeload.github.com') !== [], "par SSH : l'archive suit la redirection de GitHub");
verifierInstallee("par SSH");

// --- Un jeton GitHub révoqué : le dépôt est public, la vérification et la mise à jour se font sans lui, et la console le dit ---
reinstaller();
publierVersion();
$configOrigine = file_get_contents(APPLI.'/config/config.php');
// Juste après la balise d'ouverture : le fichier finit par une balise de fermeture, et une ligne ajoutée à la fin serait affichée au lieu d'être lue
file_put_contents(APPLI.'/config/config.php', preg_replace('/^<\?php/', '<'."?php\n\$githubtoken = 'jeton-revoque-banc';", $configOrigine, 1));
@unlink(ETAT_BANC.'/github-jetons.txt');
connecterAuPortail('admin');
$console = requetePortail(PORTAIL.'?page=adminpanel')['page'];
verifie(str_contains($console, 'update_choice') && str_contains($console, 'disponible sur GitHub : '.VERSION_NEUVE), "jeton révoqué : la console voit la nouvelle version, demandée sans jeton");
verifie(str_contains($console, 'GitHub refuse le jeton configuré') && !str_contains($console, 'Vérification des mises à jour impossible'), "jeton révoqué : la console dit de retirer ou remplacer le jeton");
verifie(!str_contains($console, 'jeton-revoque-banc'), "jeton révoqué : le jeton n'est pas affiché");
$ssh = miseAJourParSsh();
verifie($ssh['code'] === 0 && str_contains($ssh['sortie'], 'Mise à jour terminée'), "jeton révoqué : la mise à jour par SSH passe sans le jeton (".$ssh['sortie'].")");
verifierInstallee("jeton révoqué");
$jetons = array_count_values(@file(ETAT_BANC.'/github-jetons.txt', FILE_IGNORE_NEW_LINES) ?: []);
verifie(($jetons['refuse'] ?? 0) >= 2 && ($jetons['sans'] ?? 0) >= 2 && !isset($jetons['accepte']), "jeton révoqué : GitHub a reçu le jeton, l'a refusé, puis des demandes sans jeton (".json_encode($jetons).")");
file_put_contents(APPLI.'/config/config.php', $configOrigine);

// vendor/ absent : l'amorce ne l'exige plus, et miseajour.php démarre au lieu de mourir en silence sur le require
reinstaller();
publierVersion();
exec('rm -rf '.APPLI.'/vendor');
$ssh = miseAJourParSsh();
verifie(str_contains($ssh['sortie'], 'La mise à jour a échoué') && !str_contains($ssh['sortie'], 'Failed opening required'), "vendor/ absent : miseajour.php démarre et dit pourquoi il s'arrête (".$ssh['sortie'].")");
connu($ssh['code'] === 0 && is_file(APPLI.'/vendor/autoload.php'), "vendor/ absent : miseajour.php le remet en place (".$ssh['sortie'].")");

// Refus sous root et par le web, même avec la clé de cron
reinstaller();
publierVersion();
exec('php '.APPLI.'/src/miseajour.php 2>&1', $sortieRoot, $codeRoot);
verifie($codeRoot !== 0 && str_contains(implode(' ', $sortieRoot), 'docker exec -u www-data'), "par SSH : refus sous root, avec la marche à suivre");
oublierSession();
$web = requetePortail(dirname(PORTAIL).'/miseajour.php?key=clefactice');
verifie($web['code'] === 403, "par le web : refus même avec la clé de cron (code {$web['code']})");
verifierRienNAChange("refus sous root et par le web");

// --- Les droits de la console ---
reinstaller();
publierVersion();
connecterAuPortail('autonome');
$pageComite = requetePortail(PORTAIL.'?page=userinfo')['page'];
$aUnJeton = preg_match("/name='csrf_token' value='([^']+)'/", $pageComite, $jeton) === 1;
verifie($aUnJeton, "droits : le comité a un jeton CSRF valide");
$reponse = requetePortail(PORTAIL.'?page=adminpanel', ['csrf_token' => $jeton[1] ?? '', 'update_choice' => 'yes']);
verifie($reponse['code'] === 403, "droits : un comité ne peut pas lancer la mise à jour (code {$reponse['code']})");
oublierSession();
requetePortail(PORTAIL.'?page=adminpanel', ['update_choice' => 'yes']);
connecterAuPortail('admin');
$reponse = requetePortail(PORTAIL.'?page=adminpanel', ['update_choice' => 'yes']);
verifie($reponse['code'] === 403, "droits : un admin sans jeton CSRF non plus (code {$reponse['code']})");
$console = requetePortail(PORTAIL.'?page=adminpanel')['page'];
preg_match("/name='csrf_token' value='([^']+)'/", $console, $jeton);
$reponse = requetePortail(PORTAIL.'?page=adminpanel', ['csrf_token' => $jeton[1] ?? '', 'update_choice' => 'no']);
verifie(str_contains($reponse['page'], 'Update refused'), "droits : le refus de l'admin s'affiche comme avant");
verifierRienNAChange("droits");

// --- La mémoire des alertes : créée par la migration, sur un serveur qui vient de l'ancienne version ---
reinstaller();
connexionBD()->query('DROP TABLE alertecomite');
connexionBD()->query("UPDATE parameters SET paramvalue = '112' WHERE paramname = 'db version'");
$sansTable = fn() => connexionBD()->query("SHOW TABLES LIKE 'alertecomite'")->num_rows === 0;
verifie($sansTable() && sonnerieFonctionne(), "version 113, avant la migration : sans la table, le groupe de sonnerie fonctionne");
publierVersion();
cliquerMettreAJour();
verifie(!$sansTable(), "version 113 : la migration crée la table des alertes");
verifierInstallee("version 113");

// --- Installation neuve : ce que voit un nouveau serveur ---
exec('rm -rf '.APPLI.'/src '.APPLI.'/vendor');
exec('cp -a '.COPIE_SRC.' '.APPLI.'/src');
exec('cp -a '.COPIE_VENDOR.' '.APPLI.'/vendor');
viderEtatFaux();
viderBD();
ob_start();
$bd = new DatabaseManager();
ob_end_clean();
verifie(connexionBD()->query("SHOW TABLES LIKE 'alertecomite'")->num_rows === 1, "installation neuve : la table des alertes existe");
$versionsActuelles = json_decode(file_get_contents(DOSSIER_UPDATEJSON.NOM_FICHIER_DB_VERSION), true)['versions'];
// Le constructeur seul amène la BD neuve à la dernière version : rien ne la rattrape ensuite (le code est déjà à jour)
verifie(versionBD() === end($versionsActuelles)['noVersion'], "installation neuve : le compteur de BD est à la dernière version sans migration à part (".versionBD().")");
verifie(connexionBD()->query("SHOW TABLES LIKE 'ringgroupstate'")->num_rows === 1, "installation neuve : la table de la liste conservée existe");
// La BD neuve a exactement la structure figée (tâche 4.4) : mêmes tables, même définition de chacune
$figee = DatabaseManager::structureFigee();
verifie($figee['version'] === (int)versionBD() || $figee['version'] < (int)versionBD(), "installation neuve : la structure figée est à la version {$figee['version']}, le compteur à ".versionBD());
preg_match_all('/CREATE TABLE `([a-z_]+)`.*?;\s*$/ms', $figee['sql'], $definitions, PREG_SET_ORDER);
$normaliser = fn(string $sql) => preg_replace('/\s+/', ' ', trim(preg_replace('/ AUTO_INCREMENT=\d+/', '', $sql), "; \n"));
$ecarts = [];
foreach ($definitions as [$texte, $table]) {
	$reel = connexionBD()->query("SHOW CREATE TABLE `$table`")->fetch_row()[1] ?? '';
	if ($normaliser($reel) !== $normaliser($texte)) {
		$ecarts[] = $table;
	}
}
$tablesNeuves = array_column(connexionBD()->query('SHOW TABLES')->fetch_all(), 0);
sort($tablesNeuves);
$tablesFigees = array_column($definitions, 1);
sort($tablesFigees);
verifie(count($definitions) === 9 && $tablesNeuves === $tablesFigees, "installation neuve : les 9 tables de la structure figée, ni plus ni moins (".implode(',', $tablesNeuves).")");
verifie($ecarts === [], "installation neuve : chaque table a la définition de la structure figée (écarts : ".implode(',', $ecarts).")");

// --- Une migration avec du SQL, publiée après la structure figée : la BD neuve la reçoit (sinon le code installé attendrait
// une colonne absente, et la mise à jour ne la rattraperait jamais : le code est déjà à jour) ---
$fichierVersions = DOSSIER_UPDATEJSON.NOM_FICHIER_DB_VERSION;
$fichierSql = DOSSIER_UPDATEJSON.'essai-installation-neuve.sql';
$avant = file_get_contents($fichierVersions);
$derniere = (int)end($versionsActuelles)['noVersion'];
$versions = json_decode($avant, true);
$versions['versions'][] = ['noVersion' => (string)($derniere + 1), 'sql' => 'ALTER TABLE parameters ADD COLUMN essai_sql INT NOT NULL DEFAULT 7;'];
$versions['versions'][] = ['noVersion' => (string)($derniere + 2), 'file' => 'essai-installation-neuve.sql'];
file_put_contents($fichierVersions, json_encode($versions));
file_put_contents($fichierSql, "ALTER TABLE parameters ADD COLUMN essai_fichier INT NOT NULL DEFAULT 9;\nALTER TABLE parameters ADD COLUMN essai_deux INT NOT NULL DEFAULT 1;");
$erreursInstallation = installerBD();
$colonnes = array_column(connexionBD()->query('SHOW COLUMNS FROM parameters')->fetch_all(), 0);
file_put_contents($fichierVersions, $avant);
unlink($fichierSql);
verifie($erreursInstallation === [], "installation neuve avec migrations SQL : aucune erreur (".implode(' | ', $erreursInstallation).")");
verifie(versionBD() == $derniere + 2, "installation neuve avec migrations SQL : le compteur est à la dernière migration (".versionBD().")");
verifie(in_array('essai_sql', $colonnes, true) && in_array('essai_fichier', $colonnes, true) && in_array('essai_deux', $colonnes, true), "installation neuve avec migrations SQL : les colonnes des migrations (SQL en ligne, fichier, deux instructions) existent");
installerBD();
verifie(versionBD() == $derniere && !in_array('essai_sql', array_column(connexionBD()->query('SHOW COLUMNS FROM parameters')->fetch_all(), 0), true), "installation neuve : le fichier d'essai retiré, la BD neuve est de nouveau celle du dépôt");

oublierCopieInstallation();
finDuScenario();
