<?php
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
require_once SRC_DIRECTORY.'utils/repomanager.php';

class UpdateManager {
	private $zeRepoToken;
	private $ownerRepo;
	private $repoName;
	private $branchUsed;
	private $repertoireDownload;
	private $repomanagerobject;
	private $derniereErreur = '';
	private ?array $refResolue = null; // ['ref' => string, 'sha' => string|null]
	private string $versionLocale = '';   // version installée sur ce serveur
	private string $versionDistante = ''; // version disponible sur GitHub

	protected $file;
	protected $logManager;

	/**
	 * Construit un objet de la classe UpdateManager
	 *
	 */
	public function __construct(?JournalDeTache $logManager = null) {
		$this->logManager = $logManager;
		$this->zeRepoToken = TOKEN_REPO;
		$this->ownerRepo = OWNER_REPOSITORY;
		$this->repoName = REPOSITORY_NAME;
		$this->branchUsed = BRANCHE_POUR_MAJ;
		$this->repertoireDownload = DOWNLOAD_DIRECTORY;

		$this->repomanagerobject = new RepoManager($this->zeRepoToken);
	}

	/**
	 * Retourne le message de la dernière erreur de communication avec GitHub,
	 * ou une chaîne vide si la dernière vérification s'est bien déroulée.
	 * Permet de distinguer « aucune mise à jour disponible » de « GitHub injoignable ».
	 */
	public function getDerniereErreur() {
		return $this->derniereErreur;
	}

	/**
	 * Vrai si GitHub a refusé le jeton configuré (révoqué, expiré) : la vérification s'est faite sans jeton.
	 */
	public function jetonRefuse(): bool {
		return $this->repomanagerobject->jetonRefuse();
	}

	/**
	 * Résout la référence Git à utiliser pour les téléchargements de mise à jour, une
	 * seule fois par instance (mise en cache) : le SHA du commit de la dernière release
	 * publiée si elle existe (référence immuable, imposée par défaut), sinon la branche
	 * de repli (BRANCHE_POUR_MAJ, comportement historique - seul cas où aucune release
	 * n'a encore été publiée). SB_UPDATE_BRANCH permet de forcer une branche de
	 * développement à la place ; à ne jamais utiliser en production.
	 *
	 * @return array{ref:string, sha:?string} 'sha' est null quand 'ref' est une branche
	 * (aucune vérification d'intégrité possible dans ce cas).
	 */
	public function resolveRef(): array {
		if ($this->refResolue !== null) {
			return $this->refResolue;
		}
		$brancheDev = getenv('SB_UPDATE_BRANCH');
		if (is_string($brancheDev) && $brancheDev !== '') {
			$this->refResolue = ['ref' => $brancheDev, 'sha' => null];
			return $this->refResolue;
		}
		$release = $this->repomanagerobject->getLatestRelease($this->ownerRepo, $this->repoName);
		if ($release !== false) {
			$this->refResolue = ['ref' => $release['sha'], 'sha' => $release['sha']];
			return $this->refResolue;
		}
		$this->refResolue = ['ref' => $this->branchUsed, 'sha' => null];
		return $this->refResolue;
	}

	/**
	 * Vérifie si une mise à jour de Switchboard est disponible.
	 *
	 * @return True si une mise à jour est disponible; False autrement.
	 *
	 * @remarks Cette fonction peut s'arrêter via die.
	 */
	public function checkNewVersionAvailable() {
		$filemanagerobject = new Fichiers();

		// Obtenir la version du code au repo github
		$codeNouvelleVersion = $this->getCodeVersionFile();
		if($codeNouvelleVersion === false):
			return false;
		endif;
		$this->versionDistante = (string)$codeNouvelleVersion;

		//Obtenir la version du code du serveur
		// readFile() lève une exception si le fichier est absent (il ne retourne jamais
		// false) : sans ce try/catch, un codeVersion.json local manquant ferait planter
		// toute la console d'administration au lieu de signaler le problème.
		try {
			$codeVersionServeur = $filemanagerobject->readfile(FICHIER_LOCAL_CODE_VERSION);
		} catch (\Throwable $e) {
			$this->derniereErreur = $e->getMessage();
			echo "Erreur en lisant codeVersion.json du serveur (".FICHIER_LOCAL_CODE_VERSION.") <br>\n";
			return false;
		}
		if($codeVersionServeur === false) {
			echo "Erreur en lisant codeVersion.json du serveur <br>\n";
			return false;
		}

		$codeversiondecode = json_decode($codeVersionServeur);
		if($codeversiondecode === null) {
			echo "Erreur en décodant codeVersion.json du serveur <br>\n";
			return false;
		}
		$this->versionLocale = (string)$codeversiondecode->codeVersion;

		return !($this->versionLocale === $this->versionDistante);
	}

	/**
	 * Version du code actuellement installée sur ce serveur (src/updatejson/codeVersion.json),
	 * telle que lue lors du dernier checkNewVersionAvailable(). Chaîne vide si non lue.
	 */
	public function getVersionLocale() {
		return $this->versionLocale;
	}

	/**
	 * Version du code disponible sur GitHub, telle que lue lors du dernier
	 * checkNewVersionAvailable(). Chaîne vide si non lue (ex.: GitHub injoignable).
	 */
	public function getVersionDistante() {
		return $this->versionDistante;
	}

	/**
	 * Get version code from codeVersion.json file.
	 * Obtenir et retourner la version du code du repo github (/src/updatejson/codeVersion.json)
	 *
	 * @return SwitchBoard number version.
	 */
	private function getCodeVersionFile() {
		$filemanagerobject = new Fichiers();
		$this->derniereErreur = '';

		$refUtilisee = $this->resolveRef()['ref'];
		$result = $this->repomanagerobject->downloadFile($this->ownerRepo, $this->repoName, FICHIER_DISTANT_CODE_VERSION, $refUtilisee);

		if ($result['success'] == false):
			$this->derniereErreur = $result['message'];
			// Une demande sans jeton qui échoue (aucun jeton, ou jeton refusé) : la console
			// d'administration l'explique (tokenreminder.php) plutôt qu'un message d'erreur brut.
			if (TOKEN_REPO_CONFIGURE && !$this->jetonRefuse()) {
				echo 'Erreur en telechargeant le fichier codeVersion.json de github: '.$result['message']."<br>\n";
				echo "Référence pour mise à jour: $refUtilisee <br>\n";
			}
			return false;
		endif;

		$codeversionnouveaucontent = $filemanagerobject->readfile($this->repertoireDownload . NOM_FICHIER_CODE_VERSION);
		if($codeversionnouveaucontent === false) {
			echo "Erreur en lisant le nouveau codeVersion.json <br>\n";
			return false;
		}

		$codeversionnouveaudecode = json_decode($codeversionnouveaucontent);
		if($codeversionnouveaudecode === null) {
			echo "Erreur en décodant le nouveau codeVersion.json <br>\n";
			return false;
		}

		return $codeversionnouveaudecode->codeVersion;
	}

	/**
	 * Télécharge l'archive de cette référence (voir resolveRef()) dans $destination.
	 *
	 * @throws RuntimeException Si l'archive n'a pas pu être téléchargée au complet.
	 */
	public function telechargerArchive(string $reference, string $destination): void {
		$this->repomanagerobject->telechargerArchive($this->ownerRepo, $this->repoName, $reference, $destination);
	}
}
