<?php
/**
 * Installe la dernière version de SwitchBoard, depuis la console ou par SSH (src/miseajour.php).
 *
 * La règle qui prime : une panne à n'importe quel point avant le remplacement de src/, même un arrêt
 * brutal du processus, laisse l'ancien code en marche, et on peut relancer. Tout ce qui peut échouer est
 * donc fait et vérifié dans download/ avant de toucher à l'installation. L'ordre est décrit dans
 * ReadmeMajAuto.md.
 *
 * Le progrès sort par $signaler, et l'erreur par une exception : c'est à l'entrée de les afficher. Une fois
 * src/ remplacé, le code neuf est sur le disque et l'ancien est en mémoire : plus rien ne doit être chargé.
 */
class MiseAJour {
	const VERSION_PHP_MINIMALE = '8.3.0'; // plancher réel de composer.lock et du zip des dépendances
	const EXTENSIONS_REQUISES = ['curl', 'gettext', 'json', 'mbstring', 'mysqli', 'mysqlnd', 'openssl', 'pdo_mysql', 'zip'];
	const ESPACE_LIBRE_MINIMAL = 100 * 1024 * 1024;
	const SAUVEGARDES_GARDEES = 5;

	private DatabaseManager $bd;
	private Fichiers $fichiers;
	private UpdateManager $depot;
	private $signaler;
	private VerrouExclusif $verrouMiseAJour;
	private VerrouExclusif $verrouSonnerie;
	private string $dossierSauvegarde = '';

	public function __construct(DatabaseManager $bd, Fichiers $fichiers, UpdateManager $depot, callable $signaler) {
		$this->bd = $bd;
		$this->fichiers = $fichiers;
		$this->depot = $depot;
		$this->signaler = $signaler;
		$this->verrouMiseAJour = new VerrouExclusif(VerrouExclusif::MISE_A_JOUR);
		$this->verrouSonnerie = new VerrouExclusif(VerrouExclusif::SONNERIE);
	}

	/**
	 * @throws Exception Si la mise à jour n'a pas abouti : le message dit où on en est.
	 */
	public function executer(): void {
		$this->verifierEnvironnement();
		if (!$this->verrouMiseAJour->acquerirSansAttendre()) {
			throw new RuntimeException(_("Une autre mise à jour est déjà en cours."));
		}
		try {
			$this->viderDownload();
			if (glob(DOWNLOAD_DIRECTORY.'*') !== []) {
				throw new RuntimeException(_("Les restes d'un essai précédent dans download/ ne peuvent pas être effacés."));
			}
			$neuf = $this->preparerVersionNeuve();
			if ($this->estDejaAJour($neuf)) {
				($this->signaler)(sprintf(_("SwitchBoard est déjà à jour (version %s)."), $neuf['version']));
				$this->viderDownload();
				return;
			}
			$this->sauvegarderBD($neuf['migrations']);
			$this->prendreVerrouSonnerie();
			$this->migrer($neuf['migrations']);
			$this->remplacerDependances($neuf['vendor']);
			$this->remplacerCode($neuf);
			$this->viderDownload();
			$this->verrouSonnerie->liberer();
			$this->retirerVieillesSauvegardes();
		} finally {
			$this->verrouSonnerie->liberer();
			$this->verrouMiseAJour->liberer();
			if ($this->dossierSauvegarde !== '') {
				@rmdir($this->dossierSauvegarde); // seulement s'il est resté vide : une panne sans SQL à sauvegarder
			}
		}
		($this->signaler)(sprintf(_("Mise à jour terminée : la version %s est installée."), $neuf['version']));
	}

	private function verifierEnvironnement(): void {
		// Un hébergement mutualisé peut désactiver ces fonctions : la mise à jour reste relançable sans elles
		if (function_exists('ignore_user_abort')) {
			ignore_user_abort(true);
		}
		if (function_exists('set_time_limit')) {
			set_time_limit(0);
		}
		if (version_compare(PHP_VERSION, self::VERSION_PHP_MINIMALE, '<')) {
			throw new RuntimeException(sprintf(_("PHP %s minimum est requis : ce serveur a la version %s."), self::VERSION_PHP_MINIMALE, PHP_VERSION));
		}
		$manquantes = array_filter(self::EXTENSIONS_REQUISES, fn(string $extension) => !extension_loaded($extension));
		if ($manquantes !== []) {
			throw new RuntimeException(sprintf(_("Extensions PHP manquantes : %s."), implode(', ', $manquantes)));
		}
		$this->verifierDossiers();
		// disk_free_space() ignore les quotas de cPanel : ce n'est qu'une vérification au mieux. Les échecs
		// d'écriture sont attrapés plus loin, par la comparaison des tailles après l'extraction.
		$espaceLibre = function_exists('disk_free_space') ? @disk_free_space(BASE_DIRECTORY) : false;
		if ($espaceLibre !== false && $espaceLibre < self::ESPACE_LIBRE_MINIMAL) {
			throw new RuntimeException(sprintf(_("Espace disque insuffisant : %d Mo libres, %d Mo requis."), $espaceLibre / 1048576, self::ESPACE_LIBRE_MINIMAL / 1048576));
		}
	}

	private function verifierDossiers(): void {
		$this->fichiers->protegerDossierDuWeb(DOWNLOAD_DIRECTORY);
		$this->fichiers->protegerDossierDuWeb(BACKUPS_DIRECTORY);
		foreach ([BASE_DIRECTORY, SRC_DIRECTORY, DOWNLOAD_DIRECTORY, BACKUPS_DIRECTORY, CONFIG_DIRECTORY.'locks/'] as $dossier) {
			if (!is_writable($dossier)) {
				throw new RuntimeException(sprintf(_("Droit d'écriture manquant sur %s."), $dossier));
			}
		}
		// Sans ceci, acquerirSansAttendre() laisserait croire que le verrou est pris
		if (!$this->verrouSonnerie->estUtilisable() || !$this->verrouMiseAJour->estUtilisable()) {
			throw new RuntimeException(sprintf(_("Droit d'écriture manquant sur %s."), CONFIG_DIRECTORY.'locks/'));
		}
		$disques = array_unique(array_map(fn(string $dossier) => stat($dossier)['dev'], [SRC_DIRECTORY, DOWNLOAD_DIRECTORY, BACKUPS_DIRECTORY]));
		if (count($disques) > 1) {
			throw new RuntimeException(_("src/, download/ et backups/ doivent être sur le même disque : le remplacement du code se fait par renommage."));
		}
	}

	// Vide le dossier, y compris son .htaccess : il faut le remettre
	private function viderDownload(): void {
		$this->fichiers->delTree(DOWNLOAD_DIRECTORY, false);
		$this->fichiers->protegerDossierDuWeb(DOWNLOAD_DIRECTORY);
	}

	/**
	 * Télécharge, extrait et vérifie tout ce qui peut l'être avant de toucher à quoi que ce soit.
	 *
	 * @return array{src:string, version:string, migrations:list<array{noVersion:int, sql:string}>, vendor:?string}
	 * 'vendor' est le dossier des nouvelles dépendances, ou null si elles n'ont pas changé.
	 */
	private function preparerVersionNeuve(): array {
		($this->signaler)(_("Téléchargement de la nouvelle version..."));
		$reference = $this->depot->resolveRef();
		$archive = DOWNLOAD_DIRECTORY.REPOSITORY_NAME.'.zip';
		$this->depot->telechargerArchive($reference['ref'], $archive);
		$racine = $this->fichiers->extraireZip($archive, DOWNLOAD_DIRECTORY);
		$this->verifierReference($racine, $reference['sha']);

		$src = DOWNLOAD_DIRECTORY.$racine.REPOSITORY_NAME.'/src';
		foreach (['managementportal.php', 'commun/lesconstantes.php'] as $fichier) {
			if (!is_file("$src/$fichier")) {
				throw new RuntimeException(sprintf(_("L'archive téléchargée ne contient pas %s."), "src/$fichier"));
			}
		}
		$version = $this->lireVersionCode("$src/updatejson/".NOM_FICHIER_CODE_VERSION);
		if ($version === '') {
			throw new RuntimeException(sprintf(_("%s est illisible dans l'archive téléchargée."), NOM_FICHIER_CODE_VERSION));
		}
		$neuf = ['src' => $src, 'version' => $version, 'migrations' => $this->lireMigrations($src), 'vendor' => $this->preparerDependances($src)];
		$this->fixerModesDesDossiers($src);
		($this->signaler)(sprintf(_("Archive vérifiée : version %s."), $version));
		return $neuf;
	}

	// Quand une release est publiée, le dossier racine de l'archive doit être celui de son commit. GitHub le
	// nomme {owner}-{repo}-{sha}/, avec un sha tantôt court, tantôt complet : on le compare comme un préfixe.
	private function verifierReference(string $racine, ?string $shaAttendu): void {
		if ($shaAttendu === null) {
			return;
		}
		$prefixe = strtolower(OWNER_REPOSITORY.'-'.REPOSITORY_NAME.'-');
		$nomDossier = strtolower(rtrim($racine, '/'));
		$shaDuDossier = str_starts_with($nomDossier, $prefixe) ? substr($nomDossier, strlen($prefixe)) : '';
		if ($shaDuDossier === '' || !str_starts_with(strtolower($shaAttendu), $shaDuDossier)) {
			throw new RuntimeException(sprintf(_("L'archive téléchargée ne correspond pas à la version attendue (dossier %s, commit attendu %s)."), $racine, $shaAttendu));
		}
	}

	private function lireVersionCode(string $fichier): string {
		$contenu = json_decode((string)@file_get_contents($fichier), true);
		return is_array($contenu) ? (string)($contenu['codeVersion'] ?? '') : '';
	}

	/**
	 * Le SQL est lu ici, dans l'archive : celui de l'ancien src/ ne contient pas les nouvelles entrées.
	 *
	 * @return list<array{noVersion:int, sql:string}> Les entrées plus récentes que la BD, en ordre croissant.
	 */
	private function lireMigrations(string $src): array {
		$contenu = json_decode((string)@file_get_contents("$src/updatejson/".NOM_FICHIER_DB_VERSION), true);
		if (!is_array($contenu) || !is_array($contenu['versions'] ?? null)) {
			throw new RuntimeException(sprintf(_("%s est illisible dans l'archive téléchargée."), NOM_FICHIER_DB_VERSION));
		}
		$versionBD = $this->bd->getDbVersion();
		if ($versionBD === false) {
			throw new RuntimeException(_("La version de la base de données est introuvable (table parameters, ligne 'db version')."));
		}
		$migrations = [];
		$precedente = 0;
		foreach ($contenu['versions'] as $entree) {
			$noVersion = (int)($entree['noVersion'] ?? 0);
			if ($noVersion <= $precedente) {
				throw new RuntimeException(sprintf(_("%s n'est pas en ordre croissant (version %d)."), NOM_FICHIER_DB_VERSION, $noVersion));
			}
			$precedente = $noVersion;
			$sql = $this->lireSql($entree, $src);
			if ($noVersion > (int)$versionBD) {
				$migrations[] = ['noVersion' => $noVersion, 'sql' => $sql];
			}
		}
		return $migrations;
	}

	private function lireSql(array $entree, string $src): string {
		if (isset($entree['sql'])) {
			return (string)$entree['sql'];
		}
		$fichier = "$src/updatejson/".($entree['file'] ?? '');
		if (!is_file($fichier)) {
			throw new RuntimeException(sprintf(_("Le fichier de la migration %d est absent de l'archive téléchargée."), (int)$entree['noVersion']));
		}
		return (string)file_get_contents($fichier);
	}

	// Extraites ici et non au moment de les remplacer : une archive de dépendances corrompue doit arrêter la
	// mise à jour avant la migration, sinon la BD serait en avance sur le code.
	private function preparerDependances(string $src): ?string {
		$zips = glob("$src/".SUFFIXE_FICHIER_DEPENDANCES.'*.zip');
		if (count($zips) !== 1) {
			throw new RuntimeException(sprintf(_("L'archive téléchargée doit contenir un seul zip de dépendances (trouvés : %d)."), count($zips)));
		}
		$zipActuel = SRC_DIRECTORY.basename($zips[0]);
		$aChange = !is_file($zipActuel) || hash_file('sha256', $zipActuel) !== hash_file('sha256', $zips[0]);
		if (!$aChange && is_file(VENDOR_DIRECTORY.'autoload.php')) {
			return null;
		}
		if (is_dir(VENDOR_DIRECTORY) && !is_writable(VENDOR_DIRECTORY)) {
			throw new RuntimeException(sprintf(_("Droit d'écriture manquant sur %s."), VENDOR_DIRECTORY));
		}
		$dossier = DOWNLOAD_DIRECTORY.'dependances';
		$this->fichiers->extraireZip($zips[0], $dossier);
		if (!is_file("$dossier/vendor/autoload.php")) {
			throw new RuntimeException(_("Le zip de dépendances ne contient pas vendor/autoload.php."));
		}
		return "$dossier/vendor";
	}

	// Mêmes droits que ceux que donnait la copie de src/ par recurse_copy()
	private function fixerModesDesDossiers(string $src): void {
		$mode = 0775 & ~umask();
		chmod($src, $mode);
		$elements = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
		foreach ($elements as $element) {
			if ($element->isDir()) {
				chmod($element->getPathname(), $mode);
			}
		}
	}

	private function estDejaAJour(array $neuf): bool {
		return $neuf['version'] === $this->lireVersionCode(FICHIER_LOCAL_CODE_VERSION) && $neuf['migrations'] === [] && $neuf['vendor'] === null;
	}

	private function sauvegarderBD(array $migrations): void {
		$nomDossier = $this->fichiers->set_backup_folder_name(rtrim(BACKUPS_DIRECTORY, '/'), date('Y-m-d'));
		$this->dossierSauvegarde = BACKUPS_DIRECTORY.$nomDossier;
		if (!@mkdir($this->dossierSauvegarde, 0775, true)) {
			throw new RuntimeException(sprintf(_("Impossible de créer le dossier de sauvegarde %s."), $this->dossierSauvegarde));
		}
		if (trim(implode('', array_column($migrations, 'sql'))) === '') {
			return;
		}
		($this->signaler)(_("Sauvegarde de la base de données en cours (étape la plus longue)..."));
		$fichier = $this->dossierSauvegarde.'/'.FICHIER_BACKUP_BD;
		$this->bd->backupDatabase($fichier);
		if ((int)@filesize($fichier) === 0) {
			throw new RuntimeException(sprintf(_("La sauvegarde de la base de données est vide : %s."), $fichier));
		}
		($this->signaler)(_("Sauvegarde de la base de données terminée : ").$fichier);
	}

	private function prendreVerrouSonnerie(): void {
		if (!$this->verrouSonnerie->acquerirSansAttendre()) {
			throw new RuntimeException(_("Le cron des groupes de sonnerie est en cours. Rien n'a changé : réessayez dans quelques minutes."));
		}
	}

	private function migrer(array $migrations): void {
		if ($migrations === []) {
			return;
		}
		// Le téléchargement et la sauvegarde ont pu dépasser le délai d'inactivité de la connexion
		$this->bd->refreshdbconnexion();
		$versionAtteinte = (int)$this->bd->getDbVersion();
		foreach ($migrations as $migration) {
			($this->signaler)(sprintf(_("Migration de la base de données vers la version %d..."), $migration['noVersion']));
			try {
				$this->bd->appliquerMigration($migration['sql'], $migration['noVersion']);
			} catch (\mysqli_sql_exception $erreur) {
				// Le DDL de MariaDB ne se défait pas, et une instruction déjà reçue par le serveur va au bout même
				// si PHP est tué : le compteur peut alors être en retard sur le schéma.
				throw new RuntimeException(sprintf(_("La migration %d a échoué. La base de données est à la version %d et l'ancien code reste en place. Erreur : %s. Si l'erreur dit qu'une table ou une colonne existe déjà, un essai interrompu a pu l'appliquer sans avancer le compteur : vérifiez le schéma, corrigez la ligne 'db version' de la table parameters, puis relancez."),
					$migration['noVersion'], $versionAtteinte, $erreur->getMessage()), 0, $erreur);
			}
			$versionAtteinte = $migration['noVersion'];
		}
	}

	private function remplacerDependances(?string $vendorNeuf): void {
		if ($vendorNeuf === null) {
			return;
		}
		($this->signaler)(_("Remplacement des dépendances..."));
		$this->echangerDossier(rtrim(VENDOR_DIRECTORY, '/'), $vendorNeuf, $this->dossierSauvegarde.'/vendor');
	}

	private function remplacerCode(array $neuf): void {
		($this->signaler)(_("Remplacement du code..."));
		try {
			$this->echangerDossier(rtrim(SRC_DIRECTORY, '/'), $neuf['src'], $this->dossierSauvegarde.'/src');
		} catch (RuntimeException $erreur) {
			if ($neuf['vendor'] !== null) {
				$this->echangerDossier(rtrim(VENDOR_DIRECTORY, '/'), $this->dossierSauvegarde.'/vendor', $neuf['vendor']);
			}
			throw $erreur;
		}
		if (function_exists('opcache_reset')) {
			@opcache_reset(); // refusé si opcache.restrict_api le demande : rien de grave, les fichiers sont nouveaux
		}
	}

	/**
	 * Seulement après une réussite : une série d'échecs ne doit pas effacer les bonnes sauvegardes. Le verrou
	 * de la mise à jour est encore tenu, sinon une autre mise à jour verrait son dossier vide partir ici.
	 *
	 * Seuls les dossiers dont le nom commence par une date sont regardés : un dossier posé à la main n'est pas
	 * à nous. Un dossier sans src/ est un essai raté. Le nouveau code est déjà en place, mais tout ce qui sert
	 * ici est en mémoire, et un échec ne fait qu'un message.
	 */
	private function retirerVieillesSauvegardes(): void {
		$dossiers = glob(BACKUPS_DIRECTORY.'[0-9][0-9][0-9][0-9]-[0-9][0-9]-[0-9][0-9]*', GLOB_ONLYDIR) ?: [];
		rsort($dossiers, SORT_STRING);
		$completes = array_filter($dossiers, fn(string $dossier) => is_dir("$dossier/src"));
		$gardes = array_slice($completes, 0, self::SAUVEGARDES_GARDEES);
		$gardes[] = $this->dossierSauvegarde;
		foreach (array_diff($dossiers, $gardes) as $dossier) {
			if (!$this->fichiers->delTree($dossier, true)) {
				($this->signaler)(sprintf(_("La vieille sauvegarde %s n'a pas pu être effacée complètement."), basename($dossier)));
			}
		}
	}

	// Déplace $actuel vers $sauvegarde puis $neuf vers $actuel. $actuel ne doit jamais rester absent : si le
	// second renommage échoue, l'ancien dossier revient.
	private function echangerDossier(string $actuel, string $neuf, string $sauvegarde): void {
		$existait = is_dir($actuel);
		if ($existait && !@rename($actuel, $sauvegarde)) {
			throw new RuntimeException(sprintf(_("Impossible de déplacer %s vers %s : rien n'a été remplacé."), $actuel, $sauvegarde));
		}
		if (@rename($neuf, $actuel)) {
			return;
		}
		if ($existait && !@rename($sauvegarde, $actuel)) {
			throw new RuntimeException(sprintf(_("%s est absent : remettez-le à la main depuis %s."), $actuel, $sauvegarde));
		}
		throw new RuntimeException(sprintf(_("Impossible de mettre le nouveau %s en place : l'ancien a été remis."), basename($actuel)));
	}
}
