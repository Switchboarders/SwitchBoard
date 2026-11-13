#!/usr/bin/env php
<?php
// La sauvegarde de la BD (DumpBD, depuis la v138 : elle remplace ifsnop/mysqldump-php). Ce qu'elle copie revient à
// l'identique par restoreDatabase(), valeurs piégées comprises ; le fichier garde le format que jobRestore.php lit ; la
// mémoire ne grossit pas avec la BD ; elle refuse ce qu'elle ne sait pas copier, et ne laisse jamais un fichier à moitié.
require_once '/banc/commun/banc.php';

const DOSSIER = '/tmp/banc-dump';
const DUMP = DOSSIER.'/BD.sql';

// Pour chaque table : sa création et la somme de ses données, calculée par la BD
function etatBD(): array {
	$bd = connexionBD();
	$etat = [];
	foreach ($bd->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'switchboard' ORDER BY table_name")->fetch_all() as [$table]) {
		$nom = '`'.str_replace('`', '``', $table).'`';
		$etat[$table] = [$bd->query("SHOW CREATE TABLE $nom")->fetch_row()[1], $bd->query("CHECKSUM TABLE $nom")->fetch_row()[1]];
	}
	return $etat;
}
function sauvegarder(): string {
	@unlink(DUMP);
	try {
		(new DatabaseManager())->backupDatabase(DUMP);
		return '';
	} catch (Throwable $erreur) {
		return $erreur->getMessage();
	}
}
function lignesInsert(string $dump, string $table): array {
	return array_values(array_filter(explode("\n", $dump), fn(string $ligne) => str_starts_with($ligne, "INSERT INTO `$table` ")));
}

repartirAZero();
exec('rm -rf '.DOSSIER.' && mkdir -p '.DOSSIER);
$bd = connexionBD();
$bd->set_charset('utf8mb4');

// --- Des valeurs piégées, dans les types que la BD connaît ---
$bd->query("CREATE TABLE bancdump_valeurs (
	id int NOT NULL AUTO_INCREMENT PRIMARY KEY,
	texte_latin1 varchar(255) CHARACTER SET latin1 NULL,
	texte_utf8 text CHARACTER SET utf8mb4 NULL,
	octets blob NULL,
	octets_fixes binary(4) NULL,
	entier bigint NULL,
	nombre decimal(14,4) NULL,
	reel double NULL,
	moment timestamp NULL DEFAULT NULL,
	date_heure datetime NULL,
	bits bit(5) NULL,
	choix enum('un','deux') NULL,
	moitie bigint GENERATED ALWAYS AS (entier DIV 2) VIRTUAL,
	`order` varchar(10) NULL
) ENGINE=InnoDB DEFAULT CHARSET=latin1");
$piege = "l'apostrophe \"guillemets\" \\ barre\nnouvelle ligne\r\ttab \0 nul \x1a ctrl-Z ; -- pas un commentaire /* non plus */ `accent grave`";
$tousLesOctets = implode('', array_map('chr', range(0, 255)));
$requete = $bd->prepare("INSERT INTO bancdump_valeurs (id, texte_latin1, texte_utf8, octets, octets_fixes, entier, nombre, reel, moment, date_heure, bits, choix, `order`)
	VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, b'10101', ?, ?)");
$lignes = [
	[1, 'été à Québec — œuvre', '📞 garde 🌙 été', $tousLesOctets, "\0\1\2\3", '-9223372036854775808', '-1234567890.1234', '0.1', '2026-07-01 12:00:00', '2026-11-01 01:30:00', 'deux', 'NULL'],
	[2, $piege, $piege, "'\\\0", "a\0b\0", '9223372036854775807', '0.0000', '1.7976931348623157e308', '1970-01-01 00:00:01', '1000-01-01 00:00:00', 'un', ''],
	[3, '', '', '', "\0\0\0\0", '0', '-0.0001', '-2.5e-300', null, '9999-12-31 23:59:59', null, null],
];
foreach ($lignes as $ligne) {
	$requete->bind_param('isssssssssss', ...$ligne);
	$requete->execute();
}
$bd->query("INSERT INTO bancdump_valeurs (id) VALUES (4)"); // tout à NULL, sauf l'identifiant
$bd->query("SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'");
$bd->query("INSERT INTO bancdump_valeurs (id, texte_utf8) VALUES (0, 'identifiant zéro')");
$bd->query("SET SESSION sql_mode = DEFAULT");

// Un nom à guillemets obliques, une table vide, et une clé étrangère vers une table qui vient après dans l'ordre alphabétique
$bd->query("CREATE TABLE `bancdump-tiret` (`select` int NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$bd->query("INSERT INTO `bancdump-tiret` VALUES (1), (2)");
$bd->query("CREATE TABLE bancdump_vide (id int) ENGINE=InnoDB");
$bd->query("CREATE TABLE bancdump_z_parent (id int NOT NULL PRIMARY KEY) ENGINE=InnoDB");
$bd->query("CREATE TABLE bancdump_a_enfant (id int NOT NULL PRIMARY KEY, parent int NOT NULL, FOREIGN KEY (parent) REFERENCES bancdump_z_parent (id)) ENGINE=InnoDB");
$bd->query("INSERT INTO bancdump_z_parent VALUES (10)");
$bd->query("INSERT INTO bancdump_a_enfant VALUES (1, 10)");

// Assez de lignes pour plusieurs INSERT : chacun reste sous la taille permise d'un paquet
$bd->query("CREATE TABLE bancdump_volume (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, contenu varchar(1000) NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
for ($lot = 0; $lot < 6; $lot++) {
	$valeurs = [];
	for ($i = 0; $i < 500; $i++) {
		$valeurs[] = "('".$bd->real_escape_string(mb_substr(str_repeat("ligne $lot-$i : l'été à Québec ; ", 40), 0, 900))."')";
	}
	$bd->query("INSERT INTO bancdump_volume (contenu) VALUES ".implode(',', $valeurs));
}

$avant = etatBD();
$erreur = sauvegarder();
verifie($erreur === '', "la sauvegarde réussit ($erreur)");
$dump = (string)@file_get_contents(DUMP);

// --- Le format que jobRestore.php et restoreDatabase() lisent ---
$date = preg_match('/^-- Date: (.+)$/m', substr($dump, 0, 1000), $ligneDate) === 1 ? date('Y-m-d', (int)strtotime($ligneDate[1])) : null;
verifie($date === date('Y-m-d'), "la ligne -- Date: est dans l'en-tête, à la date du jour (".($date ?? 'absente').")");
verifie(str_contains(substr($dump, -200), '-- Dump completed'), "le fichier finit par -- Dump completed");
preg_match_all('/^DROP TABLE IF EXISTS `([^`]+)`;$/m', $dump, $tables);
verifie($tables[1] === array_keys($avant), "une ligne DROP TABLE IF EXISTS par table, seule sur sa ligne (".count($tables[1])." tables sur ".count($avant).")");
verifie(lignesInsert($dump, 'bancdump_vide') === [], "une table vide n'a aucun INSERT");
$inserts = lignesInsert($dump, 'bancdump_volume');
$plusLong = max(array_map('strlen', $inserts));
verifie(count($inserts) >= 3 && $plusLong <= 1000001, "plusieurs INSERT pour une grosse table, chacun d'au plus un million d'octets (".count($inserts)." INSERT, le plus long : $plusLong)");
verifie(!str_contains($dump, '`moitie`) VALUES') && !preg_match('/INSERT INTO `bancdump_valeurs` \\([^)]*`moitie`/', $dump), "la colonne générée n'est pas copiée : elle se recalcule");

// --- Tout revient à l'identique ---
$bd->query("UPDATE bancdump_valeurs SET texte_utf8 = 'changé', octets = NULL");
$bd->query("DELETE FROM bancdump_volume WHERE id > 100");
$bd->query("DROP TABLE `bancdump-tiret`");
$bd->query("CREATE TABLE bancdump_ajoutee (id int)");
$gestionnaire = new DatabaseManager();
$connexionDuGestionnaire = (new ReflectionProperty(DatabaseManager::class, 'conn'))->getValue($gestionnaire);
$sessionAvant = $connexionDuGestionnaire->query('SELECT @@session.sql_mode, @@session.time_zone, @@session.foreign_key_checks, @@session.character_set_client')->fetch_row();
$erreur = '';
try {
	$gestionnaire->restoreDatabase(DUMP);
} catch (Throwable $exception) {
	$erreur = $exception->getMessage();
}
verifie($erreur === '', "restoreDatabase() relit la sauvegarde ($erreur)");
$apres = etatBD();
verifie($apres === $avant, "chaque table revient à l'identique : création et somme des données (différentes : "
	.implode(', ', array_keys(array_diff_key($avant, $apres) + array_diff_key($apres, $avant) + array_filter($avant, fn($etat, $table) => ($apres[$table] ?? null) !== $etat, ARRAY_FILTER_USE_BOTH))).")");
$sessionApres = $connexionDuGestionnaire->query('SELECT @@session.sql_mode, @@session.time_zone, @@session.foreign_key_checks, @@session.character_set_client')->fetch_row();
verifie($sessionApres === $sessionAvant, "après la restauration, la connexion retrouve ses réglages (".implode(' | ', $sessionApres).")");
verifie($gestionnaire->getDbVersion() !== false, "après la restauration, la connexion sert encore");
$ligne = $bd->query("SELECT texte_latin1, texte_utf8, octets, HEX(bits), moitie FROM bancdump_valeurs WHERE id = 1")->fetch_row();
verifie($ligne === ['été à Québec — œuvre', '📞 garde 🌙 été', $tousLesOctets, '15', '-4611686018427387904'], "accents, emoji, octets et bits reviennent tels quels, et la colonne générée se recalcule");
verifie($bd->query("SELECT texte_utf8 FROM bancdump_valeurs WHERE id = 2")->fetch_row()[0] === $piege, "le texte piégé revient tel quel");
verifie($bd->query("SELECT texte_utf8 FROM bancdump_valeurs WHERE id = 0")->fetch_row()[0] === 'identifiant zéro', "la ligne d'identifiant zéro revient avec cet identifiant");
verifie($bd->query("SELECT COUNT(*) FROM bancdump_valeurs WHERE id = 4 AND texte_utf8 IS NULL AND octets IS NULL AND entier IS NULL")->fetch_row()[0] === '1'
	&& $bd->query("SELECT COUNT(*) FROM bancdump_valeurs WHERE id = 3 AND texte_utf8 = '' AND octets = ''")->fetch_row()[0] === '1', "NULL et chaîne vide restent distincts");
verifie($bd->query("SHOW TABLES LIKE 'bancdump_ajoutee'")->num_rows === 0, "une table créée depuis la sauvegarde est retirée");

// --- La mémoire ne grossit pas avec la BD : 20 Mo de lignes, lues une à une ---
$bd->query("CREATE TABLE bancdump_memoire (id int NOT NULL AUTO_INCREMENT PRIMARY KEY, contenu text NOT NULL) ENGINE=InnoDB");
$contenu = str_repeat('x', 2000);
for ($lot = 0; $lot < 20; $lot++) {
	$bd->query("INSERT INTO bancdump_memoire (contenu) VALUES ".implode(',', array_fill(0, 500, "('$contenu')")));
}
exec('chown www-data '.DOSSIER);
$code = 'require "'.APPLI.'/src/commun/lesconstantes.php"; $bd = new DatabaseManager(); $avant = memory_get_peak_usage();'
	.' $bd->backupDatabase("'.DOSSIER.'/memoire.sql"); echo round((memory_get_peak_usage() - $avant) / 1048576, 1);';
exec('su -s /bin/sh www-data -c '.escapeshellarg('php -r '.escapeshellarg($code).' 2>&1'), $sortie, $codeSortie);
$memoire = implode(' ', $sortie);
$taille = round((int)@filesize(DOSSIER.'/memoire.sql') / 1048576, 1);
verifie($codeSortie === 0 && is_numeric($memoire) && (float)$memoire < 6 && $taille > 19, "sauvegarde de $taille Mo : la mémoire monte de $memoire Mo seulement");
$bd->query("DROP TABLE bancdump_memoire");

// --- Ce qu'elle ne sait pas copier l'arrête, et aucun fichier ne reste ---
$bd->query("CREATE VIEW bancdump_vue AS SELECT 1 AS un");
$erreur = sauvegarder();
verifie(str_starts_with($erreur, 'Backup error') && str_contains($erreur, 'bancdump_vue (VIEW)') && !file_exists(DUMP), "une vue arrête la sauvegarde, et le message la nomme ($erreur)");
$bd->query("DROP VIEW bancdump_vue");

$bd->query("CREATE TRIGGER bancdump_declencheur BEFORE INSERT ON bancdump_vide FOR EACH ROW SET NEW.id = NEW.id");
$erreur = sauvegarder();
verifie(str_contains($erreur, 'bancdump_declencheur (TRIGGER)') && !file_exists(DUMP), "un déclencheur arrête la sauvegarde ($erreur)");
$bd->query("DROP TRIGGER bancdump_declencheur");

// Une table illisible, la dernière dans l'ordre : la sauvegarde échoue après avoir écrit les autres, et le fichier commencé est effacé
$bd->query("CREATE TABLE zzz_bancdump_illisible (id int) ENGINE=InnoDB");
$bd->query("ALTER TABLE zzz_bancdump_illisible DISCARD TABLESPACE");
$erreur = sauvegarder();
verifie(str_starts_with($erreur, 'Backup error') && !file_exists(DUMP), "une table illisible arrête la sauvegarde en cours de route, sans laisser de fichier ($erreur)");
$bd->query("DROP TABLE zzz_bancdump_illisible");

@unlink(DUMP);
try {
	(new DatabaseManager())->backupDatabase(DOSSIER.'/absent/BD.sql');
	$erreur = '';
} catch (Throwable $exception) {
	$erreur = $exception->getMessage();
}
verifie(str_contains($erreur, "Impossible d'écrire"), "un dossier absent : un message clair ($erreur)");
verifie(sauvegarder() === '', "après ces refus, la sauvegarde réussit de nouveau");

foreach (['bancdump_valeurs', '`bancdump-tiret`', 'bancdump_vide', 'bancdump_a_enfant', 'bancdump_z_parent', 'bancdump_volume'] as $table) {
	$bd->query("DROP TABLE IF EXISTS $table");
}
exec('rm -rf '.DOSSIER);
finDuScenario();
