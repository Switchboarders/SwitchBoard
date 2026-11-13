<?php

// Tampon de sortie : certains fichiers (ex.: AdminPanel::__construct()) émettent déjà du HTML
// avant même le routage par page - sans ce tampon, une page qui doit répondre avec un fichier
// binaire (téléchargement CSV/Excel, voir AdminPanel::displayMultiAccountCostReportPage())
// obtiendrait un avertissement "headers already sent" et/ou des octets HTML parasites avant le
// contenu du fichier téléchargé, le corrompant. Le tampon est vidé (ob_end_clean()) juste avant
// d'envoyer les en-têtes de téléchargement ; pour toutes les autres pages, il est simplement
// laissé se vider normalement en fin de script (comportement inchangé).
ob_start();

// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php.
require_once dirname(__FILE__, 1).'/commun/lesconstantes.php';
require_once SRC_DIRECTORY.'utils/isHttps.php';
// 'secure' est conditionnel à HTTPS pour ne pas casser le développement local en HTTP.
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => isHttps()]);
session_start();
if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
require_once PORTAIL_DIRECTORY.'useraccess.php';
require_once PORTAIL_DIRECTORY.'adminpanel.php';
require_once PORTAIL_DIRECTORY.'webpage.php';
require_once SRC_DIRECTORY.'updatemanager.php';
require_once SRC_DIRECTORY.'utils/verrouexclusif.php';
class ManagementPortal{

	public DatabaseManager $databasemanager;
	public Langue $languagemanager;
	public UserAccess $useraccess;
	public AdminPanel $adminpanel;
	public InformationsUtilisateur $userinformations;

	function __construct(){
		$this->protegerDossiersSensibles();
		$this->databasemanager = new DatabaseManager();
		$this->fermerSessionSansUtilisateur();
		$this->languagemanager = new Langue();
		$this->languagemanager->setLanguage();
		$this->useraccess = new UserAccess();
		$this->verifyextensions();
		$this->verifyifadminaccountexist();
		$this->displayloginpageifnotauthentified();
		$this->userinformations = new InformationsUtilisateur();

		$this->adminpanel = new AdminPanel($this->useraccess,$this->databasemanager,$this->userinformations);
		$this->displaymanagementportal();
	}

	/**
	 * Ferme la session d'un utilisateur supprimé pendant qu'il était connecté : la personne revient à la page de
	 * connexion, comme un anonyme. Fait avant tout le reste, qui lit cet utilisateur (langue, droits, pages). La
	 * session est vidée et change d'identifiant, et l'ancienne est effacée du serveur ; elle reste ouverte, vide,
	 * comme celle d'un anonyme, pour qu'une connexion envoyée dans la même requête fonctionne.
	 */
	private function fermerSessionSansUtilisateur(): void {
		if (estSessionSansUtilisateur($this->databasemanager)) {
			$_SESSION = [];
			session_regenerate_id(true);
		}
	}

	/**
	 * Dépose un .htaccess "interdit" dans les dossiers qui ne doivent jamais être servis sur
	 * le web (sauvegardes, configuration, téléchargements temporaires). Beaucoup
	 * d'installations (cPanel/Apache) vivent directement dans public_html : sans cela,
	 * backups/BD.sql - un dump complet de la base - est téléchargeable par qui devine l'URL,
	 * et backups/ contient aussi une copie de config/config.php (mot de passe BD, jeton
	 * GitHub, clé de chiffrement).
	 *
	 * Fait à chaque chargement : ce sont cinq file_exists() quand les fichiers sont déjà en
	 * place (coût négligeable), et cela protège aussi les installations existantes, que la
	 * mise à jour automatique ne peut pas atteindre autrement (elle ne remplace que src/).
	 * Les crons n'attendent pas cette page : JournalDeTache et TraitementComite déposent eux-mêmes
	 * le .htaccess quand ils créent logs/ ou calendarcache/.
	 * La pile Docker fournie bloque déjà ces dossiers côté nginx : ceci vise Apache.
	 */
	private function protegerDossiersSensibles(){
		$filemanager = new Fichiers();
		// calendarcache/ : copie des agendas (noms et numéros des répondants) ; logs/ : journaux.
		foreach ([BACKUPS_DIRECTORY, CONFIG_DIRECTORY, DOWNLOAD_DIRECTORY,
				DOSSIER_CACHE_AGENDAS, DOSSIER_JOURNAUX] as $dossier) {
			$filemanager->protegerDossierDuWeb($dossier);
		}
	}

	function verifyextensions(){
		if (!extension_loaded('mysqlnd')) {
			echo "Extension nd_mysqli and mysqlnd are required. If your using cpanel, you can usually make the change in PHP Selector. You must deactivate mysqli before proceeding and activate mysqlnd instead.";
			exit();
		}
	}
	
	function verifyifadminaccountexist(){
		// Dans le cas où aucun administrateur n'a encore été inscrit
		// If no admin account has been created
		if(!$this->databasemanager->isadminaccountexist()){
			// Here the admin form has not been completed
			// Le formulaire d'entrée d'administrateur n'est pas complété
			if(empty($_POST['createormodifyusername'])||empty($_POST['createormodifypassword'])||empty($_POST['createormodifypasswordverification'])){
				$title = "Créer compte administrateur/Create admin account";
				$description = "Ce compte aura le contrôle total du système. / Admin have total control over the system.";
				$this->useraccess->displayusermodificationpage($title,$description);
				exit();
			}
			requireCsrf();
			$hashed_password = password_hash($_POST['createormodifypassword'], PASSWORD_DEFAULT);
			$this->databasemanager->createormodifyuser($_POST['createormodifyusername'],$hashed_password,$privilegelevel=-1);
			$user = $this->databasemanager->authenticateuser($_POST['createormodifyusername'],$_POST['createormodifypassword']);
			if ($user !== False){
				// Régénère l'identifiant de session : ce compte vient d'obtenir tous les
				// privilèges, on évite la fixation de session au même titre qu'une connexion.
				session_regenerate_id(true);
				$_SESSION['authenticated'] = true;
				$_SESSION['iduser'] = intval($user['iduser']);
				$_SESSION['username'] = $user['username'];
			}
			return;
		}
	}
	
	function displayloginpageifnotauthentified(){
		// Script d'authentification
		if(!empty($_POST["username"])){
			$user = $this->databasemanager->authenticateuser($_POST['username'], $_POST['password'] ?? '');
			if ($user !== False){
				// Régénère l'identifiant de session après une authentification réussie
				// pour éviter la fixation de session.
				session_regenerate_id(true);
				$_SESSION['authenticated'] = true;
				$_SESSION['iduser'] = intval($user['iduser']);
				$_SESSION['username'] = $user['username'];
			} else {
				// TODO Non considere, devrait correspondre au langage de l admin
				echo "<h1>"._("Mauvais mot de passe")."</h1>";
			}
		}
		if (!$this->useraccess->testifauthorized()){
			$this->useraccess->displayloginpage();
			exit();
		}
	}
	
	function displaymanagementportal(){
		if (!$this->useraccess->testifauthorized()) exit();
		// Une action passe avant la page, quelle qu'elle soit : elle a son propre contrôle, et elle ne s'accepte qu'en POST
		if (($_POST['action'] ?? '') === 'deletealllogs') {
			requireAdmin($this->databasemanager);
			requireCsrf();
			commencerEnregistrement();
			JournalDeTache::deletealllogs();
			echo _("Tous les journaux ont été effacés.");
			terminerEnregistrement();
		}

		$page = empty($_GET['page']) ? Routes::pageParDefaut(estAdmin($this->databasemanager)) : $_GET['page'];
		$route = is_string($page) ? Routes::trouver($page) : null;
		if ($route === null) {
			http_response_code(404);
			echo _("Problème avec le paramètre page");
			return;
		}
		$this->controlerDroit($route['droit']);
		// Une page qui agit (voip.ms, SwitchBoard Chat, SMS) n'agit qu'en POST, avec son jeton : aucun lien, favori ou autre page
		// ne la déclenche. Ouverte par un GET, elle montre seulement ce que la dernière action a dit.
		if (Routes::agit($page) && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
			requireCsrf();
		}
		$objets = [AdminPanel::class => $this->adminpanel, UserAccess::class => $this->useraccess];
		$objets[$route['classe']]->{$route['methode']}();
	}

	/**
	 * Le seul contrôle d'accès des pages : celui que leur route déclare (voir Routes). Tout droit autre que CONNECTE
	 * est traité comme ADMIN, pour qu'une faute de frappe dans la table ferme la page au lieu de l'ouvrir à tous.
	 */
	private function controlerDroit(string $droit): void {
		if ($droit !== Routes::CONNECTE) {
			requireAdmin($this->databasemanager);
			return;
		}
		// Une page par utilisateur ne montre que son propre compte, sauf à un admin
		if (!empty($_GET['iduser'])) {
			requireAccesUtilisateur($this->databasemanager, $_GET['iduser']);
		}
	}

}
$managementportal = new ManagementPortal();
