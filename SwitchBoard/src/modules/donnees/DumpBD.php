<?php
// La sauvegarde de la BD dans un fichier SQL (backups/<date>/BD.sql), avec mysqli seulement. Depuis la v138, elle remplace
// ifsnop/mysqldump-php (GPL-3.0, qui ne s'accorde pas avec la licence du projet) et garde le format que le reste du code lit :
// - « -- Date: » dans l'en-tête : jobRestore.php compare cette date au nom du dossier de la sauvegarde ;
// - « DROP TABLE IF EXISTS `table`; », seul sur sa ligne, pour chaque table : restoreDatabase() en tire la liste des tables ;
// - « -- Dump completed » à la fin : jobRestore.php refuse un fichier coupé.
// Le fichier se relit aussi à la main, avec le client de la BD : mariadb <base> < BD.sql
//
// Seules les tables sont copiées. Une vue, une séquence ou un déclencheur arrête la sauvegarde : SwitchBoard n'en crée
// pas, et une copie qui les perdrait en silence ne rendrait pas la BD telle qu'elle était.
// Les lignes sont lues une à une (MYSQLI_USE_RESULT) et écrites au fur et à mesure : la mémoire ne grossit pas avec la BD.
// La lecture se fait dans une transaction : la copie est celle d'un seul instant, même si un cron écrit pendant ce temps.
class DumpBD {
	// Un INSERT de plusieurs lignes ne dépasse pas cette taille, comme avec mysqldump : il reste sous max_allowed_packet
	private const TAILLE_MAX_INSERT = 1000000;
	private const TYPES_NUMERIQUES = [MYSQLI_TYPE_TINY, MYSQLI_TYPE_SHORT, MYSQLI_TYPE_LONG, MYSQLI_TYPE_INT24, MYSQLI_TYPE_LONGLONG,
		MYSQLI_TYPE_DECIMAL, MYSQLI_TYPE_NEWDECIMAL, MYSQLI_TYPE_FLOAT, MYSQLI_TYPE_DOUBLE, MYSQLI_TYPE_YEAR];
	// Avec le jeu de caractères 63 (binary), ces types sont des octets : écrits en hexadécimal, ils reviennent intacts
	private const TYPES_OCTETS = [MYSQLI_TYPE_STRING, MYSQLI_TYPE_VAR_STRING, MYSQLI_TYPE_BLOB, MYSQLI_TYPE_TINY_BLOB,
		MYSQLI_TYPE_MEDIUM_BLOB, MYSQLI_TYPE_LONG_BLOB, MYSQLI_TYPE_GEOMETRY];
	private const JEU_BINARY = 63;

	/** @var resource */
	private $fichier;

	// Une connexion à elle seule : la sauvegarde change sa session (jeu de caractères, fuseau, mode SQL, transaction)
	public function __construct(private mysqli $connexion) {}

	/**
	 * Écrit la sauvegarde dans $chemin. En cas d'échec, le fichier commencé est effacé.
	 *
	 * @throws RuntimeException|mysqli_sql_exception
	 */
	public function ecrire(string $chemin): void {
		$this->preparerSession();
		$tables = $this->tables();
		$fichier = @fopen($chemin, 'wb');
		if ($fichier === false) {
			throw new RuntimeException(sprintf(_("Impossible d'écrire la sauvegarde de la base de données : %s."), $chemin));
		}
		$this->fichier = $fichier;
		try {
			$this->ecrireTexte($this->entete());
			foreach ($tables as $table) {
				$this->ecrireTable($table);
			}
			$this->ecrireTexte($this->pied());
			if (!fflush($fichier)) {
				throw new RuntimeException(sprintf(_("Impossible d'écrire la sauvegarde de la base de données : %s."), $chemin));
			}
		} catch (\Throwable $erreur) {
			fclose($fichier);
			@unlink($chemin);
			throw $erreur;
		}
		if (!fclose($fichier)) {
			@unlink($chemin);
			throw new RuntimeException(sprintf(_("Impossible d'écrire la sauvegarde de la base de données : %s."), $chemin));
		}
		$this->requete('COMMIT');
	}

	// Sans mode SQL, les guillemets s'échappent par une barre oblique inverse et SHOW CREATE TABLE donne tout. En UTC, les
	// TIMESTAMP reviennent tels quels sur un serveur d'un autre fuseau (l'en-tête du fichier remet aussi UTC).
	private function preparerSession(): void {
		if (!$this->connexion->set_charset('utf8mb4')) {
			throw new mysqli_sql_exception($this->connexion->error, $this->connexion->errno);
		}
		$this->requete("SET SESSION sql_mode = '', SESSION time_zone = '+00:00', SESSION sql_quote_show_create = 1");
		$this->requete('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
		$this->requete('START TRANSACTION WITH CONSISTENT SNAPSHOT');
	}

	/** @return list<string> */
	private function tables(): array {
		$tables = [];
		$autres = [];
		$resultat = $this->requete('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME');
		foreach ($resultat->fetch_all() as [$nom, $type]) {
			if ($type === 'BASE TABLE') {
				$tables[] = (string)$nom;
			} else {
				$autres[] = "$nom ($type)";
			}
		}
		$resultat = $this->requete('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() ORDER BY TRIGGER_NAME');
		foreach ($resultat->fetch_all() as [$nom]) {
			$autres[] = "$nom (TRIGGER)";
		}
		if ($autres !== []) {
			throw new RuntimeException(sprintf(_("La base de données contient autre chose que des tables : %s. La sauvegarde ne copie que les tables : retirez ces éléments, ou sauvegardez la base à la main."), implode(', ', $autres)));
		}
		if ($tables === []) {
			throw new RuntimeException(_("La base de données n'a aucune table : il n'y a rien à sauvegarder."));
		}
		return $tables;
	}

	private function entete(): string {
		return "-- Sauvegarde de la base de données de SwitchBoard (src/modules/donnees/DumpBD.php)\n"
			."-- Serveur : ".preg_replace('/[\r\n]/', ' ', $this->connexion->server_info)."\n"
			."-- Date: ".date('r')."\n\n"
			."/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;\n"
			."/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;\n"
			."/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;\n"
			."/*!40101 SET NAMES utf8mb4 */;\n"
			."/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n"
			."/*!40103 SET TIME_ZONE='+00:00' */;\n"
			."/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;\n"
			."/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
			."/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n"
			."/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;\n";
	}

	private function pied(): string {
		return "\n/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n"
			."/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
			."/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;\n"
			."/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;\n"
			."/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;\n"
			."/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;\n"
			."/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n"
			."/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;\n\n"
			."-- Dump completed on ".date('r')."\n";
	}

	private function ecrireTable(string $table): void {
		$creation = (string)$this->requete('SHOW CREATE TABLE '.$this->nom($table))->fetch_row()[1];
		$this->ecrireTexte("\n--\n-- Table ".$this->nom($table)."\n--\n\n"
			.'DROP TABLE IF EXISTS '.$this->nom($table).";\n$creation;\n\n");
		$this->ecrireLignes($table);
	}

	// Les colonnes générées (VIRTUAL, STORED) se recalculent : un INSERT qui leur donne une valeur serait refusé.
	// Retourne [nom de la colonne => ce qui la lit] : un BIT se lit en nombre (mysqlnd le rend en décimal, l'ancien
	// pilote libmysqlclient en octets ; « + 0 » donne le même nombre aux deux).
	/** @return array<string, string> */
	private function colonnesACopier(string $table): array {
		$resultat = $this->requete("SELECT COLUMN_NAME, DATA_TYPE, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '"
			.$this->connexion->real_escape_string($table)."' ORDER BY ORDINAL_POSITION");
		$colonnes = [];
		foreach ($resultat->fetch_all() as [$nom, $type, $extra]) {
			if (preg_match('/\b(VIRTUAL|STORED|PERSISTENT)\b/i', (string)$extra) !== 1) {
				$colonnes[(string)$nom] = strtolower((string)$type) === 'bit' ? $this->nom((string)$nom).' + 0' : $this->nom((string)$nom);
			}
		}
		return $colonnes;
	}

	private function ecrireLignes(string $table): void {
		$lectures = $this->colonnesACopier($table);
		if ($lectures === []) {
			return;
		}
		$colonnes = implode(',', array_map([$this, 'nom'], array_keys($lectures)));
		$resultat = $this->requete('SELECT '.implode(',', $lectures).' FROM '.$this->nom($table), MYSQLI_USE_RESULT);
		$formats = array_map([$this, 'format'], $resultat->fetch_fields());
		$debut = 'INSERT INTO '.$this->nom($table)." ($colonnes) VALUES ";
		$insert = '';
		while (is_array($ligne = $resultat->fetch_row())) {
			$valeurs = [];
			foreach ($ligne as $i => $valeur) {
				$valeurs[] = $this->valeur($valeur, $formats[$i]);
			}
			$tuple = '('.implode(',', $valeurs).')';
			if ($insert !== '' && strlen($insert) + strlen($tuple) + 1 > self::TAILLE_MAX_INSERT) {
				$this->ecrireTexte("$insert;\n");
				$insert = '';
			}
			$insert = $insert === '' ? $debut.$tuple : "$insert,$tuple";
		}
		// Une lecture coupée (connexion perdue) finit comme la dernière ligne : seule l'erreur de la connexion la distingue
		if ($this->connexion->errno !== 0) {
			throw new mysqli_sql_exception($this->connexion->error, $this->connexion->errno);
		}
		$resultat->free();
		if ($insert !== '') {
			$this->ecrireTexte("$insert;\n");
		}
	}

	private function format(object $champ): string {
		if (in_array($champ->type, self::TYPES_NUMERIQUES, true)) {
			return 'nombre';
		}
		if ($champ->charsetnr === self::JEU_BINARY && in_array($champ->type, self::TYPES_OCTETS, true)) {
			return 'octets';
		}
		return 'texte';
	}

	private function valeur(?string $valeur, string $format): string {
		if ($valeur === null) {
			return 'NULL';
		}
		if ($format === 'nombre') {
			return $valeur;
		}
		if ($format === 'octets') {
			return $valeur === '' ? "''" : '0x'.bin2hex($valeur);
		}
		return "'".$this->connexion->real_escape_string($valeur)."'";
	}

	private function nom(string $nom): string {
		return '`'.str_replace('`', '``', $nom).'`';
	}

	private function ecrireTexte(string $texte): void {
		if (@fwrite($this->fichier, $texte) !== strlen($texte)) {
			throw new RuntimeException(_("La sauvegarde de la base de données est incomplète : le disque est peut-être plein."));
		}
	}

	private function requete(string $sql, int $mode = MYSQLI_STORE_RESULT): mysqli_result|bool {
		$resultat = $this->connexion->query($sql, $mode);
		if ($resultat === false) {
			throw new mysqli_sql_exception($this->connexion->error, $this->connexion->errno);
		}
		return $resultat;
	}
}
