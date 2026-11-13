<?php
class Fichiers {
	protected $file;
	protected $logManager;

	/**
	 * Construit un objet de la classe Fichiers
	 * 
	 */
	public function __construct(?JournalDeTache $logManager = null) {
		$this->logManager = $logManager;
	}

	/**
	 * Ouvre un fichier en écriture ou le créé à vide
	 * 
	 */
	public function createFile($path) {
		$this->ensureDirectoryExists(dirname($path));
		$this->file = fopen($path, 'w');
		if ($this->file === false) {
			throw new Exception("Unable to create file at $path");
		}
	}

	/**
	 * Ecrit $content dans le fichier 
	 * 
	 */
	public function writeFile($content) {
		if ($this->file === null) {
			throw new Exception("No file is open for writing.");
		}
		fwrite($this->file, $content);
	}

	/**
	 * lit le contenu du fichier $path et le retourne
	 * 
	 */
	public function readFile($path) {
		$this->ensureFileExists($path);
		return file_get_contents($path);
	}

	/**
	 * Ajouter $content au fichier $path 
	 * 
	 */
	public function appendToFile($path, $content) {
		$this->ensureDirectoryExists(dirname($path));
		file_put_contents($path, $content, FILE_APPEND);
	}

	/**
	 * Ferme le fichier
	 * 
	 */
	public function closeFile() {
		if ($this->file !== null) {
			fclose($this->file);
			$this->file = null;
		}
	}

	/**
	 * Fait sûr que le dossier $directory existe, ie le créé s'il n'existe pas
	 * 
	 */
	protected function ensureDirectoryExists($directory) {


		if (!is_dir($directory)) {
			if (mkdir($directory, 0775, true)) {
				if ($this->logManager !== null) {
					$this->logManager->writelinetolog("Directory created: $directory");
				} else {
					echo "Directory created: $directory\n";
				}
			} else {
				throw new Exception("Failed to create directory $directory"); 
			}
		}
	}

	/**
	 * Dépose un .htaccess interdisant tout accès web dans $directory, s'il n'y en a pas déjà.
	 *
	 * Nécessaire parce que l'installation vit souvent DANS la racine web (ex.: cPanel,
	 * public_html/) : backups/ contient alors une copie complète du code - donc
	 * config/config.php, avec le mot de passe de la BD, le jeton GitHub et la clé de
	 * chiffrement - ainsi qu'un dump BD.sql complet, servi tel quel en texte brut par Apache
	 * à qui devine l'URL (le nom du dossier est une simple date). La pile Docker fournie
	 * bloque déjà ces dossiers côté nginx ; ceci protège les installations Apache.
	 *
	 * Créé à l'exécution et non livré dans le dépôt : les .htaccess y sont ignorés
	 * (voir .gitignore), et la mise à jour automatique ne recopie que src/.
	 *
	 * Crée aussi $directory s'il n'existe pas encore : le laisser attendre sa première
	 * utilisation (ex.: download/, créé seulement à la première mise à jour) ouvrirait une
	 * fenêtre entre sa création et le prochain chargement de page où il resterait
	 * temporairement non protégé.
	 */
	public function protegerDossierDuWeb($directory) {
		if (!is_dir($directory)) {
			@mkdir($directory, 0775, true);
			if (!is_dir($directory)) {
				return;
			}
		}
		$htaccess = rtrim($directory, '/') . '/.htaccess';
		if (file_exists($htaccess)) {
			return;
		}
		$contenu = "# Généré automatiquement par SwitchBoard : ce dossier ne doit JAMAIS être\n"
			. "# accessible depuis le web (sauvegardes de la BD, configuration, secrets).\n"
			. "<IfModule mod_authz_core.c>\n"
			. "    Require all denied\n"
			. "</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\n"
			. "    Order allow,deny\n"
			. "    Deny from all\n"
			. "</IfModule>\n";
		@file_put_contents($htaccess, $contenu);
	}

	/**
	 * Fait sûr que le fichier $path existe, lance une exception s'il n'existe pas
	 * 
	 */
	protected function ensureFileExists($path) {


		if (!file_exists($path)) {
			throw new Exception("File does not exist at $path");
		}
	}

	/**
	 * Créé un fichier csv $path contenant le array $data
	 * 
	 */
	public function createCsvFile($path, $data) {


		$this->createFile($path);
		//Rien à écrire quand $data est vide (ex.: 0 appel dans la période) : le fichier reste
		//vide plutôt que de planter en cherchant les en-têtes d'une première ligne inexistante.
		if (!empty($data)) {
			// Écrire les en-têtes de colonne
			// Les 3 derniers paramètres (séparateur, délimiteur, échappement) sont les valeurs par
			// défaut historiques de fputcsv, à fournir explicitement depuis PHP 8.4 (sinon warning
			// de dépréciation : la valeur par défaut de $escape changera dans une version future).
			fputcsv($this->file, array_keys($data[0]), ",", '"', "\\");
			foreach ($data as $row) {
				fputcsv($this->file, $row, ",", '"', "\\");
			}
		}
		$this->closeFile();
	}

	/**
	 * copy récursif - non, le copy de php N'EST PAS récursif.
	 * Exemple de https://www.php.net/manual/en/function.copy.php#91010, note de gimmicklessgpt,
	 * trouvé via https://stackoverflow.com/questions/5424150/the-first-argument-to-copy-function-cannot-be-a-directory
	 *
	 * $exclusDeCeNiveau ne vaut que pour $src lui-même, pas pour ses sous-dossiers : à la racine, logs/
	 * contient des données réelles, alors qu'un dossier logs sous vendor/ fait partie d'une dépendance.
	 */
	function recurse_copy($src, $dst, array $exclusDeCeNiveau = []) {

		$src = rtrim($src,'/');
		$dir = opendir($src);

		@mkdir($dst, 0775, true); // Already sets directory to 775
		while(false !== ( $file = readdir($dir)) ) {

			if (( $file != '.' ) && ( $file != '..' ) && $file != NOM_REPERTOIRE_BACKUP_SANSLA_BASE && !in_array($file, $exclusDeCeNiveau, true)) {
				if ( is_dir($src . '/' . $file) ) {
					$this->recurse_copy($src . '/' . $file,$dst . '/' . $file);
				}
				else {
					copy($src . '/' . $file,$dst . '/' . $file);
				}
			}
		}
		closedir($dir);
	}

	/**
	 * Constitue un nom de backup avec suffixe si existe déjà
	 * 
	 * @param base_dir The base directory for the backup
	 * @param date The backup directory (in aaaa-mm-jj format)
	 * @return The name of the directory to use for the backup
	 */

	function set_backup_folder_name($base_dir, $date) {
			$folder_path = $base_dir . '/' . $date;
			if (!is_dir($folder_path)) {
					// Dossier n'existe pas, on retourne directement le nom de la date
					return $date;
			} else {
					// Dossier existe, on cherche un suffixe alphabétique libre
					$letter = 'a';
					$used_letters = [];
					$folders = glob($base_dir . '/' . $date . '*', GLOB_ONLYDIR);
					foreach ($folders as $folder) {
							$suffix = substr($folder, strlen($folder_path));
							if ($suffix !== '') {
									$used_letters[] = $suffix;
							}
					}
					while (in_array($letter, $used_letters)) {
							$letter++;
					}
					return $date . $letter;
			}
	}

	/**
	 * Backup switchboard code before installing the update
	 * Effectue un backup du code
	 *
	 * Les journaux, le cache des agendas et download/ n'y sont pas : ils ne servent à rien pour restaurer
	 * une installation, et les deux premiers contiennent des noms et des numéros. Un BD.sql oublié à la racine
	 * n'y est pas non plus : il passerait pour le dump de cette sauvegarde, et jobBackup.php en écrit un neuf.
	 *
	 * @param repertoireParent The parent directory of the backup (ou chemin source, BASE_DIRECTORY)
	 * @param repertoireBackup The backup directory (dossier de destination)
	 */
	public function backupSBcode($repertoireParent, $repertoireBackup) {

		$base_dir = $repertoireParent.BCK_SWITCHBOARD_DIRECTORY; // var/www/html/switchboard/backups/
		// Avant d'y écrire quoi que ce soit : le dossier va contenir une copie complète du
		// code (dont config/config.php) et un dump de la BD.
		$this->ensureDirectoryExists($base_dir);
		$this->protegerDossierDuWeb($base_dir);
		$date_folder_name = rtrim($repertoireBackup,'/');
		$uniqueFolder = $this->set_backup_folder_name($base_dir, $date_folder_name);

		$exclus = [basename(DOSSIER_JOURNAUX), basename(DOSSIER_CACHE_AGENDAS), basename(DOWNLOAD_DIRECTORY), FICHIER_BACKUP_BD];
		$this->recurse_copy($repertoireParent, $repertoireParent . BCK_SWITCHBOARD_DIRECTORY . $uniqueFolder, $exclus);
		return $uniqueFolder . '/';
	}

	/**
	 * Efface un dossier et son contenu. Ne lève rien : un fichier impossible à effacer ne doit pas arrêter
	 * celui qui nettoie, et il est laissé en place.
	 *
	 * @param dir The directory to delete - or at least to empty
	 * @param executermdir True to delete the dir, false to only empty it.
	 * @return bool Vrai si tout a été effacé.
	 */
	public function delTree($dir, $executermdir) {
		$files = @scandir($dir);
		if ($files === false) {
			return false;
		}
		$toutEfface = true;
		foreach (array_diff($files, ['.', '..']) as $file) {
			$chemin = "$dir/$file";
			// Un lien vers un dossier s'efface lui-même, jamais ce qu'il pointe
			$estEfface = is_dir($chemin) && !is_link($chemin) ? $this->delTree($chemin, true) : @unlink($chemin);
			$toutEfface = $toutEfface && $estEfface;
		}
		return $executermdir ? @rmdir($dir) : $toutEfface;
	}

	/**
	 * Vérifie qu'aucune entrée de l'archive ne sort de son dossier d'extraction
	 * (chemin absolu, ".." ou séparateur Windows), pour se protéger d'une archive
	 * malveillante (zip-slip) - ex.: en cas de dépôt ou de jeton GitHub compromis.
	 *
	 * @throws Exception Si une entrée suspecte est trouvée.
	 */
	private function assertZipIsSafe(ZipArchive $za) {
		for ($i = 0; $i < $za->numFiles; $i++) {
			$nom = $za->getNameIndex($i);
			if ($nom === false) {
				continue;
			}
			if (str_starts_with($nom, '/') || str_contains($nom, '..') || str_contains($nom, "\\") || preg_match('#^[a-zA-Z]:#', $nom)) {
				throw new Exception("Entrée suspecte dans l'archive (chemin non sécuritaire) : $nom");
			}
		}
	}

	/**
	 * Extrait une archive et vérifie le résultat. extractTo() ne suffit pas : son échec a déjà passé
	 * inaperçu, et un fichier tronqué par un quota plein ne le fait pas échouer. Les tailles sont donc comparées.
	 *
	 * @return string La première entrée de l'archive : le dossier racine, pour un zip de GitHub.
	 * @throws Exception Si l'archive est invalide, suspecte, ou mal extraite.
	 */
	public function extraireZip(string $fichierZip, string $destination): string {
		$archive = new ZipArchive();
		if ($archive->open($fichierZip) !== true) {
			throw new Exception(sprintf(_("Archive illisible : %s"), basename($fichierZip)));
		}
		$racine = $archive->getNameIndex(0);
		if ($racine === false) {
			throw new Exception(sprintf(_("Archive vide : %s"), basename($fichierZip)));
		}
		$this->assertZipIsSafe($archive);
		if (!$archive->extractTo($destination)) {
			throw new Exception(sprintf(_("Extraction impossible : %s"), basename($fichierZip)));
		}
		for ($i = 0; $i < $archive->numFiles; $i++) {
			$entree = $archive->statIndex($i);
			$chemin = rtrim($destination, '/').'/'.$entree['name'];
			$estBienExtraite = str_ends_with($entree['name'], '/') ? is_dir($chemin) : @filesize($chemin) === $entree['size'];
			if (!$estBienExtraite) {
				throw new Exception(sprintf(_("Extraction incomplète de %s : %s (disque plein ?)"), basename($fichierZip), $entree['name']));
			}
		}
		$archive->close();
		return $racine;
	}
}
