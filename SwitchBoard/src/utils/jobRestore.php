<?php
// Retour arrière de SwitchBoard, en ligne de commande seulement :
//   php src/utils/jobRestore.php                        la plus récente sauvegarde qui contient un src/
//   php src/utils/jobRestore.php 2026-11-06a            cette sauvegarde
//   php src/utils/jobRestore.php 2026-11-06a --avec-bd  la BD aussi, s'il y a un BD.sql de la date du dossier
//   ... --avec-bd --bd-ancienne                         même si le BD.sql n'a pas la date du dossier
// Si src/ est cassé ou absent, le même script se lance depuis une sauvegarde, avec les mêmes arguments :
//   php backups/2026-11-06a/src/utils/jobRestore.php 2026-11-06a
//
// Il remet src/, et vendor/ s'il est dans la sauvegarde, sans toucher à la BD : les migrations restent
// compatibles avec l'ancien code. Le code remplacé est gardé dans un nouveau dossier de backups/. config/ n'est
// jamais restauré : la mise à jour n'y touche pas, et une vieille copie pourrait écraser une clé.
//
// La sauvegarde est copiée, pas déplacée : elle doit rester entière pour qu'on puisse y revenir, par exemple
// avec --avec-bd après un premier retour arrière du code seul.
//
// Rien de l'application n'est chargé pour remettre le code : src/ peut être absent, et depuis une sauvegarde
// lesconstantes.php donnerait les chemins de la sauvegarde. D'où quelques lignes reprises de VerrouExclusif et
// de MiseAJour. Pour la même raison, gettext n'est pas chargé : les messages n'ont pas de _().
//
// Tout ce fichier s'en tient à la syntaxe de PHP 7.4 : le php par défaut de certains serveurs est 7.4, et c'est
// ce fichier qui doit lui dire comment appeler le bon.
if (PHP_SAPI !== 'cli') {
	http_response_code(403);
	exit("Accès refusé : ce script se lance en ligne de commande, avec un PHP de type cli.\n");
}
if (PHP_VERSION_ID < 80300) {
	fwrite(STDERR, "PHP 8.3 minimum est requis, et ce php est en version ".PHP_VERSION.".\n"
		."Sur cPanel, appelez le bon PHP : /opt/cpanel/ea-php84/root/usr/bin/php ".__FILE__."\n");
	exit(1);
}
// Sous root, le code remis en place appartiendrait à root, et les crons et le portail ne pourraient plus y écrire
if (function_exists('posix_geteuid') && (posix_geteuid() === 0 || posix_geteuid() !== fileowner(__DIR__))) {
	$proprietaire = posix_getpwuid(fileowner(__DIR__));
	$nom = $proprietaire === false ? fileowner(__DIR__) : $proprietaire['name'];
	fwrite(STDERR, "Ce script doit tourner sous le propriétaire de src/ ($nom), jamais sous root.\n"
		."Sous Docker : docker exec -u www-data <conteneur php> php ".__FILE__."\n");
	exit(1);
}

ini_set('memory_limit', '512M');
ini_set('display_errors', 'stderr');
date_default_timezone_set('America/New_York');

const MOTIF_DOSSIER_DATE = '[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*';
const FICHIER_BD = 'BD.sql';

// Deux emplacements possibles : <racine>/src/utils ou <racine>/backups/<date>/src/utils
function trouverRacine(): string {
	$dossier = dirname(__DIR__, 2);
	$parent = dirname($dossier);
	return basename($parent) === 'backups' && is_dir(dirname($parent).'/config') ? dirname($parent) : $dossier;
}

// Retourne [nom de la sauvegarde ou null, avec la BD ou non, dump à une autre date accepté ou non]
function lireArguments(array $arguments): array {
	$nom = null;
	$avecBD = false;
	$bdAncienne = false;
	foreach ($arguments as $argument) {
		if ($argument === '--avec-bd') {
			$avecBD = true;
		} elseif ($argument === '--bd-ancienne') {
			$bdAncienne = true;
		} elseif ($nom === null && $argument[0] !== '-' && !in_array(basename($argument), ['.', '..'], true)) {
			$nom = basename($argument);
		} else {
			throw new RuntimeException("Argument non reconnu : $argument. Usage : jobRestore.php [dossier de sauvegarde] [--avec-bd [--bd-ancienne]]");
		}
	}
	if ($bdAncienne && !$avecBD) {
		throw new RuntimeException("--bd-ancienne ne va qu'avec --avec-bd. Rien n'a changé.");
	}
	return [$nom, $avecBD, $bdAncienne];
}

function aDuCode(string $sauvegarde): bool {
	return is_file("$sauvegarde/src/commun/lesconstantes.php");
}

// Un dossier sans src/ est un essai de mise à jour raté : il peut être le plus récent, et il ne faut pas s'y arrêter
function choisirSauvegarde(string $dossierBackups, ?string $nom): string {
	if ($nom !== null) {
		if (!aDuCode("$dossierBackups/$nom")) {
			throw new RuntimeException("Le dossier $nom n'existe pas dans backups/, ou il n'a pas de src/ complet. Rien n'a changé.");
		}
		return $nom;
	}
	$dossiers = glob("$dossierBackups/".MOTIF_DOSSIER_DATE, GLOB_ONLYDIR) ?: [];
	rsort($dossiers, SORT_STRING);
	foreach ($dossiers as $dossier) {
		if (aDuCode($dossier)) {
			return basename($dossier);
		}
	}
	throw new RuntimeException("Aucune sauvegarde avec un src/ dans $dossierBackups. Rien n'a changé.");
}

// Le dump finit par cette ligne : un fichier coupé (disque plein) effacerait des tables sans les remettre
function verifierDump(string $fichier): void {
	if (!is_file($fichier)) {
		throw new RuntimeException("Cette sauvegarde n'a pas de ".FICHIER_BD." : --avec-bd est impossible. Rien n'a changé.");
	}
	$fin = (string)@file_get_contents($fichier, false, null, max(0, filesize($fichier) - 200));
	if (strpos($fin, '-- Dump completed') === false) {
		throw new RuntimeException("$fichier est incomplet : il ne finit pas comme un dump. Rien n'a changé.");
	}
}

// Une sauvegarde peut contenir un BD.sql bien plus vieux qu'elle : l'ancienne mise à jour copiait toute la racine,
// avec le BD.sql qui y traînait, et ne l'écrasait que si elle avait du SQL à exécuter. Le restaurer en silence
// remettrait une BD périmée. Un jour d'écart est permis : le dossier et le dump n'ont pas toujours le même fuseau.
function verifierDateDuDump(string $fichier, string $nomDossier, bool $accepterAutreDate): void {
	$debut = (string)@file_get_contents($fichier, false, null, 0, 1000);
	$moment = preg_match('/^-- Date: (.+)$/m', $debut, $ligne) === 1 ? strtotime($ligne[1]) : false;
	$trouvee = $moment === false ? null : date('Y-m-d', $moment);
	$attendue = preg_match('/^\d{4}-\d{2}-\d{2}/', $nomDossier, $debutDuNom) === 1 ? $debutDuNom[0] : null;
	$description = "date trouvée dans ".FICHIER_BD." : ".($trouvee ?? "aucune, la ligne -- Date: est absente")."; date attendue : ".($attendue ?? "inconnue, le nom du dossier n'en a pas");
	echo ucfirst($description).".\n";
	$estLaBonneDate = $trouvee !== null && $attendue !== null && ecartEnJours($trouvee, $attendue) <= 1;
	if (!$estLaBonneDate && !$accepterAutreDate) {
		throw new RuntimeException("Ce ".FICHIER_BD." n'est peut-être pas la BD de cette sauvegarde ($description). Rien n'a changé. "
			."Pour le restaurer quand même, ajoutez --bd-ancienne.");
	}
}

// En jours de calendrier, en UTC : compté en secondes, l'écart enjambe mal le changement d'heure (du 7 au
// 9 mars 2026, il n'y a que 47 heures)
function ecartEnJours(string $date, string $autreDate): int {
	$utc = new DateTimeZone('UTC');
	return (new DateTime($date, $utc))->diff(new DateTime($autreDate, $utc))->days;
}

// Les mêmes fichiers que VerrouExclusif, que les crons et la mise à jour regardent. Le noyau les libère à la
// fin du processus.
function prendreVerrou(string $racine, string $nom, string $messageSiOccupe) {
	$dossier = "$racine/config/locks";
	if (!is_dir($dossier)) {
		@mkdir($dossier, 0700, true);
	}
	$verrou = @fopen("$dossier/$nom.lock", 'c');
	if ($verrou === false) {
		throw new RuntimeException("Le verrou $dossier/$nom.lock ne s'ouvre pas. Rien n'a changé.");
	}
	if (!flock($verrou, LOCK_EX | LOCK_NB)) {
		throw new RuntimeException($messageSiOccupe);
	}
	return $verrou;
}

// Même nom que ceux des mises à jour : une date, puis une lettre si elle est déjà prise
function creerDossierDeSauvegarde(string $dossierBackups): string {
	$nom = date('Y-m-d');
	for ($lettre = 'a'; file_exists("$dossierBackups/$nom"); $lettre++) {
		$nom = date('Y-m-d').$lettre;
	}
	if (!@mkdir("$dossierBackups/$nom", 0775)) {
		throw new RuntimeException("Impossible de créer backups/$nom. Rien n'a changé.");
	}
	return "$dossierBackups/$nom";
}

// Une copie incomplète ne doit jamais devenir le code en place : on s'arrête à la première erreur, et les
// tailles sont comparées parce qu'un quota plein peut couper un fichier sans que copy() le dise.
function copierDossier(string $source, string $destination): void {
	$noms = @scandir($source);
	if ($noms === false || !@mkdir($destination, 0775, true)) {
		throw new RuntimeException("Copie impossible de $source. Rien n'a changé.");
	}
	foreach (array_diff($noms, ['.', '..']) as $nom) {
		$de = "$source/$nom";
		$vers = "$destination/$nom";
		if (is_link($de)) {
			$estCopie = @symlink((string)readlink($de), $vers);
		} elseif (is_dir($de)) {
			copierDossier($de, $vers);
			continue;
		} else {
			$estCopie = @copy($de, $vers) && filesize($de) === filesize($vers);
		}
		if (!$estCopie) {
			throw new RuntimeException("Copie impossible de $de (disque plein ?). Rien n'a changé.");
		}
	}
}

function effacerDossier(string $dossier): void {
	foreach (array_diff(@scandir($dossier) ?: [], ['.', '..']) as $nom) {
		$chemin = "$dossier/$nom";
		if (is_dir($chemin) && !is_link($chemin)) {
			effacerDossier($chemin);
		} else {
			@unlink($chemin);
		}
	}
	@rmdir($dossier);
}

// Déplace $actuel vers $garde puis $neuf vers $actuel. $actuel ne doit jamais rester absent : si le second
// renommage échoue, l'ancien dossier revient.
function echangerDossier(string $actuel, string $neuf, string $garde): void {
	$existait = is_dir($actuel);
	if ($existait && !@rename($actuel, $garde)) {
		throw new RuntimeException("Impossible de déplacer $actuel vers $garde : rien n'a été remplacé.");
	}
	if (@rename($neuf, $actuel)) {
		return;
	}
	if ($existait && !@rename($garde, $actuel)) {
		throw new RuntimeException("$actuel est absent : remettez-le à la main depuis $garde.");
	}
	throw new RuntimeException("Impossible de mettre le nouveau ".basename($actuel)." en place : l'ancien a été remis.");
}

// vendor/ d'abord, comme la mise à jour. Si src/ échoue, l'ancien vendor/ revient.
function remettreDossiers(string $racine, string $preparation, string $garde, array $dossiers): void {
	$faits = [];
	try {
		foreach ($dossiers as $dossier) {
			echangerDossier("$racine/$dossier", "$preparation/$dossier", "$garde/$dossier");
			$faits[] = $dossier;
		}
	} catch (RuntimeException $erreur) {
		foreach ($faits as $dossier) {
			if (is_dir("$garde/$dossier")) {
				echangerDossier("$racine/$dossier", "$garde/$dossier", "$preparation/$dossier");
			}
		}
		throw $erreur;
	}
}

function lireVersion(string $src): string {
	$contenu = json_decode((string)@file_get_contents("$src/updatejson/codeVersion.json"), true);
	return is_array($contenu) ? (string)($contenu['codeVersion'] ?? '?') : '?';
}

function remettreLeCode(string $racine, string $sauvegarde, string $garde): void {
	$dossiers = is_dir("$sauvegarde/vendor") ? ['vendor', 'src'] : ['src'];
	$preparation = "$garde/preparation";
	try {
		foreach ($dossiers as $dossier) {
			copierDossier("$sauvegarde/$dossier", "$preparation/$dossier");
		}
		remettreDossiers($racine, $preparation, $garde, $dossiers);
	} finally {
		effacerDossier($preparation);
	}
}

// Après le code : c'est le src/ restauré qui connaît cette BD, et lesconstantes.php y donne les bons chemins.
// La BD en place est gardée avant d'être écrasée : c'est la seule copie de ce qui s'est passé depuis.
function remettreLaBD(string $racine, string $sauvegarde, string $garde): void {
	echo "ATTENTION : la BD redevient celle de la sauvegarde ".basename($sauvegarde).". Ce qui s'y est passé depuis est perdu.\n";
	try {
		require_once "$racine/src/commun/lesconstantes.php";
		// Le src/ restauré peut dater d'avant la v130 (databasemanager.php dans ringgroupupdater/), d'entre la v130 et la v134 (dans commun/),
		// ou d'après (modules/donnees/DatabaseManager.php, que l'autoload de son amorce trouve seul).
		foreach (["$racine/src/commun/databasemanager.php", "$racine/src/ringgroupupdater/databasemanager.php"] as $ancien) {
			if (is_file($ancien)) {
				require_once $ancien;
				break;
			}
		}
		ini_set('display_errors', 'stderr'); // lesconstantes.php l'a coupé
		$bd = new DatabaseManager();
		$bd->backupDatabase("$garde/".FICHIER_BD);
		$bd->restoreDatabase("$sauvegarde/".FICHIER_BD);
	} catch (\Throwable $erreur) {
		throw new RuntimeException("Le code est en place, mais la BD n'a pas été restaurée : ".$erreur->getMessage(), 0, $erreur);
	}
	echo "La BD est restaurée. Celle d'avant ce retour arrière est dans backups/".basename($garde)."/".FICHIER_BD.".\n";
}

function lancerRetourArriere(array $arguments): void {
	$racine = trouverRacine();
	$dossierBackups = "$racine/backups";
	[$nom, $avecBD, $bdAncienne] = lireArguments($arguments);
	$nom = choisirSauvegarde($dossierBackups, $nom);
	$sauvegarde = "$dossierBackups/$nom";
	if ($avecBD) {
		verifierDump("$sauvegarde/".FICHIER_BD);
		verifierDateDuDump("$sauvegarde/".FICHIER_BD, $nom, $bdAncienne);
	}
	// Dans des variables jusqu'à la fin de la fonction : un verrou se libère quand sa variable disparaît
	$verrouMiseAJour = prendreVerrou($racine, 'miseajour', "Une mise à jour ou un retour arrière est déjà en cours. Rien n'a changé.");
	$verrouSonnerie = prendreVerrou($racine, 'refreshallringgroups', "Le cron des groupes de sonnerie est en cours. Rien n'a changé : réessayez dans quelques minutes.");

	echo "Retour à la sauvegarde $nom : version ".lireVersion("$sauvegarde/src")." (en place : ".lireVersion("$racine/src").").\n";
	$garde = creerDossierDeSauvegarde($dossierBackups);
	try {
		remettreLeCode($racine, $sauvegarde, $garde);
		echo "Le code de la sauvegarde $nom est en place.".(is_dir("$garde/src") ? " Le code remplacé est gardé dans backups/".basename($garde)."." : "")."\n";
		if ($avecBD) {
			remettreLaBD($racine, $sauvegarde, $garde);
		} else {
			echo "La BD n'a pas été touchée.\n";
		}
	} finally {
		@rmdir($garde); // seulement s'il est resté vide : un échec, ou un src/ absent au départ
	}
}

try {
	lancerRetourArriere(array_slice($argv, 1));
} catch (\Throwable $erreur) {
	fwrite(STDERR, "Le retour arrière a échoué : ".$erreur->getMessage()."\n");
	exit(1);
}
