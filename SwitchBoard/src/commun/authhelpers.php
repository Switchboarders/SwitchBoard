<?php
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';

/**
 * Fonctions de garde réutilisables : CSRF, contrôle d'accès administrateur, et
 * protection des scripts déclenchés par cron/web sans session utilisateur. Aussi les deux
 * éléments communs aux pages du portail : bouton de retour et nom de l'utilisateur.
 */

/**
 * Valide le jeton CSRF d'une requête POST (champ csrf_token). Termine la requête avec un message d'erreur si la requête
 * n'est pas un POST, ou si le jeton est absent ou invalide : rien qui agit ne s'accepte par une adresse (GET).
 */
if (!function_exists('requireCsrf')):
	function requireCsrf(): void {
		$jeton = $_POST['csrf_token'] ?? '';
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_SESSION['csrf_token']) || !is_string($jeton) || $jeton === '' || !hash_equals($_SESSION['csrf_token'], $jeton)) {
			http_response_code(403);
			die("<h1>"._("Jeton de sécurité invalide, action refusée.")."</h1>");
		}
	}
endif;

/**
 * Un bouton qui agit : un petit formulaire POST avec le jeton CSRF, jamais un lien (un lien se déclenche d'une autre page,
 * d'un favori ou d'un aperçu). La page cible fait l'action puis redirige (terminerEnregistrement()).
 *
 * $options :
 *   iduser       le compte visé, dans l'adresse (la page qui suit le lit comme n'importe quelle page par utilisateur)
 *   champs       [nom => valeur] envoyés dans le corps de la requête
 *   confirmation texte demandé avant d'envoyer
 *   forme        'lien' (un lien dans le texte, par défaut) ou 'menu' (une entrée du menu latéral)
 *   classe       classe du bouton en plus de sa forme (ex. 'danger')
 *   titre        balise qui enveloppe le bouton dans le formulaire (h2, h3), pour les boutons qui étaient des titres
 */
if (!function_exists('boutonAction')):
	function boutonAction(string $page, string $libelle, array $options = []): string {
		$adresse = "managementportal.php?page=".urlencode($page);
		if (isset($options['iduser'])) {
			$adresse .= "&iduser=".intval($options['iduser']);
		}
		$champs = '';
		foreach ($options['champs'] ?? [] as $nom => $valeur) {
			$champs .= "<input type='hidden' name='".htmlspecialchars((string)$nom, ENT_QUOTES)."' value='".htmlspecialchars((string)$valeur, ENT_QUOTES)."'>";
		}
		$confirmation = isset($options['confirmation'])
			? " onsubmit=\"return confirm(".htmlspecialchars(json_encode((string)$options['confirmation'], JSON_UNESCAPED_UNICODE), ENT_QUOTES).")\""
			: '';
		$forme = ($options['forme'] ?? 'lien') === 'menu' ? 'submenu-item bouton-menu' : 'bouton-lien';
		$classe = trim($forme.' '.preg_replace('/[^A-Za-z0-9_ -]/', '', (string)($options['classe'] ?? '')));
		$bouton = "<button type='submit' class='".$classe."'>".htmlspecialchars($libelle)."</button>";
		$titre = in_array($options['titre'] ?? '', ['h2', 'h3'], true) ? $options['titre'] : '';
		if ($titre !== '') {
			$bouton = "<$titre>$bouton</$titre>";
		}
		// En ligne dans le texte, sauf une entrée de menu ou un titre : un bloc
		$classeFormulaire = 'form-action'.(($forme === 'submenu-item bouton-menu' || $titre !== '') ? ' form-action-bloc' : '');
		return "<form class='$classeFormulaire' method='post' action='".htmlspecialchars($adresse, ENT_QUOTES)."'$confirmation>".csrfField().$champs.$bouton."</form>";
	}
endif;

/**
 * Post/Redirect/Get : un formulaire qui enregistre répond par une redirection vers la même page en GET, jamais par la
 * page elle-même. Sans cela, le bouton Précédent du navigateur redemande de renvoyer le formulaire, et F5 l'enregistre
 * de nouveau (#77).
 *
 * commencerEnregistrement() au début de ce que fait l'enregistrement (après requireCsrf()), terminerEnregistrement()
 * à la fin : ce que l'enregistrement a dit (les erreurs de saisie) est gardé en session, redirige, et la page qui suit
 * l'affiche une fois (Webpage::render()).
 */
if (!function_exists('commencerEnregistrement')):
	function commencerEnregistrement(): void {
		ob_start();
	}
endif;

if (!function_exists('redirigerVers')):
	/**
	 * Répond par un 303 vers cette adresse : rien de ce que la page a déjà écrit ne part avec la redirection.
	 */
	function redirigerVers(string $adresse): never {
		while (ob_get_level() > 0) {
			ob_end_clean();
		}
		http_response_code(303);
		header("Location: ".$adresse);
		exit;
	}
endif;

if (!function_exists('terminerEnregistrement')):
	/**
	 * $valeurs : ce que la page qui suit lira par valeursDEnregistrement() (les pages qui montrent leurs messages
	 * par des variables plutôt que par un affichage).
	 * $page : la page qui suit, si ce n'est pas celle de la requête (une action qui n'a pas de page à elle).
	 * $parametres : [nom => valeur] ajoutés à l'adresse de la page qui suit (ex. l'état d'affichage à garder).
	 */
	function terminerEnregistrement(array $valeurs = [], ?string $page = null, array $parametres = []): never {
		$_SESSION['messages_enregistrement'] = (string)ob_get_clean();
		$_SESSION['valeurs_enregistrement'] = $valeurs;
		// La page demandée, reconstruite : jamais une adresse qui vient de la requête
		$adresse = "managementportal.php?page=".urlencode($page ?? (string)($_GET['page'] ?? ''));
		if (!empty($_GET['iduser'])) {
			$adresse .= "&iduser=".intval($_GET['iduser']);
		}
		if ($parametres !== []) {
			$adresse .= "&".http_build_query($parametres);
		}
		redirigerVers($adresse);
	}
endif;

/**
 * Une page qui agit (voir Routes::ACTIONS) n'agit qu'en POST. Ouverte par un GET, elle montre ce que la dernière
 * action a dit, une seule fois (la valeur « sortie » que terminerEnregistrement() a gardée) ; s'il n'y a rien à
 * montrer (adresse rouverte, favori, rafraîchissement), elle renvoie au tableau de bord.
 */
if (!function_exists('resultatDeLAction')):
	function resultatDeLAction(): array {
		$valeurs = valeursDEnregistrement();
		if (!is_string($valeurs['sortie'] ?? null)) {
			redirigerVers("managementportal.php");
		}
		return $valeurs;
	}
endif;

/**
 * Ce que le dernier enregistrement a dit : lu une seule fois, puis oublié.
 */
if (!function_exists('messagesDEnregistrement')):
	function messagesDEnregistrement(): string {
		$messages = $_SESSION['messages_enregistrement'] ?? '';
		unset($_SESSION['messages_enregistrement']);
		return is_string($messages) ? $messages : '';
	}
endif;

/**
 * Les valeurs que le dernier enregistrement a laissées pour cette page : lues une seule fois, puis oubliées.
 */
if (!function_exists('valeursDEnregistrement')):
	function valeursDEnregistrement(): array {
		$valeurs = $_SESSION['valeurs_enregistrement'] ?? [];
		unset($_SESSION['valeurs_enregistrement']);
		return is_array($valeurs) ? $valeurs : [];
	}
endif;

/**
 * Les messages que TraitementComite::validerAgenda() retourne, en HTML. Tout ce qui vient de l'agenda est échappé :
 * n'importe qui qui écrit dans l'agenda écrirait sinon dans la page.
 */
if (!function_exists('htmlDeLaValidation')):
	function htmlDeLaValidation(array $messages): string {
		$html = '';
		foreach ($messages as $message) {
			$classe = $message['type'] === 'avertissement' ? 'validation-warning' : 'formated-output';
			$html .= "<div class='$classe'>".htmlspecialchars($message['texte'])."</div>";
		}
		return $html;
	}
endif;

/**
 * Retourne le champ caché à inclure dans chaque formulaire POST protégé par CSRF.
 */
if (!function_exists('csrfField')):
	function csrfField(): string {
		$jeton = $_SESSION['csrf_token'] ?? '';
		return "<input type='hidden' name='csrf_token' value='".htmlspecialchars($jeton, ENT_QUOTES)."'>";
	}
endif;

/**
 * Vrai si l'utilisateur courant est administrateur (privilegelevel <= 0 ; -1 = administrateur
 * principal, 0 = administrateur). Faux pour une session restée ouverte après la suppression de son
 * utilisateur : getcurrentuserprivilegelevel() retourne alors false, que les comparaisons « > 0 » et
 * « < 1 » prenaient pour un administrateur.
 */
if (!function_exists('estAdmin')):
	function estAdmin(DatabaseManager $databasemanager): bool {
		$niveau = $databasemanager->getcurrentuserprivilegelevel();
		return is_numeric($niveau) && $niveau <= 0;
	}
endif;

/**
 * Vrai si la session est ouverte au nom d'un utilisateur qui n'existe plus : supprimé pendant qu'il était connecté.
 */
if (!function_exists('estSessionSansUtilisateur')):
	function estSessionSansUtilisateur(DatabaseManager $databasemanager): bool {
		$iduser = $databasemanager->getcurrentuserid();
		return !empty($_SESSION['authenticated']) && $iduser !== false && empty($databasemanager->getuserbyid($iduser));
	}
endif;

/**
 * Vrai si l'utilisateur courant peut agir sur le compte $iduser : le sien, ou n'importe lequel
 * s'il est administrateur.
 */
if (!function_exists('accesUtilisateurPermis')):
	function accesUtilisateurPermis(DatabaseManager $databasemanager, $iduser): bool {
		return intval($iduser) === $databasemanager->getcurrentuserid() || estAdmin($databasemanager);
	}
endif;

/**
 * Termine la requête si l'utilisateur courant n'a pas le droit d'agir sur le compte $iduser,
 * p. ex. un utilisateur ordinaire qui forge le paramètre iduser dans l'URL.
 */
if (!function_exists('requireAccesUtilisateur')):
	function requireAccesUtilisateur(DatabaseManager $databasemanager, $iduser): void {
		if (!accesUtilisateurPermis($databasemanager, $iduser)) {
			http_response_code(403);
			die(_("Vous n'avez pas les privilèges nécessaires pour effectuer cette tâche"));
		}
	}
endif;

/**
 * Termine la requête si l'utilisateur courant n'est pas administrateur (voir estAdmin()).
 */
if (!function_exists('requireAdmin')):
	function requireAdmin(DatabaseManager $databasemanager): void {
		if (!estAdmin($databasemanager)) {
			http_response_code(403);
			die(_("Vous n'avez pas les privilèges nécessaires pour effectuer cette tâche"));
		}
	}
endif;

/**
 * Bouton « ‹ » des pages d'administration : retour à la console. Un lien fixe plutôt que
 * history.back(), qui ramène au formulaire après un envoi et ne fait rien dans un nouvel onglet.
 * Une page n'a qu'un bouton, en haut.
 */
if (!function_exists('boutonRetourConsole')):
	function boutonRetourConsole(): string {
		return "<a href='managementportal.php?page=adminpanel' class='backbutton'>&#8249;</a>";
	}
endif;

/**
 * Bouton « ‹ » des pages qui servent à la fois à l'admin et à l'utilisateur : un admin qui
 * travaille sur un utilisateur (iduser dans l'URL) revient à la console, tous les autres
 * reviennent au tableau de bord.
 */
if (!function_exists('boutonRetour')):
	function boutonRetour(DatabaseManager $databasemanager): string {
		if (!empty($_GET['iduser']) && estAdmin($databasemanager)) {
			return boutonRetourConsole();
		}
		return "<a href='managementportal.php?page=mainpage' class='backbutton'>&#8249;</a>";
	}
endif;

/**
 * Ligne « Nom d'utilisateur SwitchBoard : nom » des pages par utilisateur, pour savoir sur quel
 * comité on travaille. Vide si l'utilisateur courant n'a pas le droit de voir ce nom (iduser
 * forgé dans l'URL) ou si l'iduser n'existe pas.
 */
if (!function_exists('ligneNomUtilisateur')):
	function ligneNomUtilisateur(DatabaseManager $databasemanager, $iduser): string {
		if (!accesUtilisateurPermis($databasemanager, $iduser)) {
			return "";
		}
		$utilisateur = $databasemanager->getuserbyid(intval($iduser));
		if (empty($utilisateur)) {
			return "";
		}
		return "<div class='login-title'>"._("Nom d'utilisateur SwitchBoard :")." <i>".htmlspecialchars($utilisateur['username'])."</i></div><br>";
	}
endif;

/**
 * Lit la clé partagée permettant de déclencher un script batch via le web (pour les
 * hébergements type cPanel où seul un cron web est disponible). Même convention de
 * lecture que le jeton GitHub et la clé de chiffrement : variable d'environnement
 * CRON_WEB_KEY, sinon $cronwebkey dans config/config.php.
 */
if (!function_exists('lireCleCronWeb')):
	function lireCleCronWeb(): string {
		$cle = getenv('CRON_WEB_KEY');
		if (is_string($cle) && $cle !== '') {
			return $cle;
		}
		$fichierConfig = CONFIG_DIRECTORY.'config.php';
		if (is_readable($fichierConfig)) {
			include $fichierConfig;
			if (isset($cronwebkey) && is_string($cronwebkey) && $cronwebkey !== '') {
				return $cronwebkey;
			}
		}
		return '';
	}
endif;

/**
 * Protège les scripts batch (src/*.php) qui n'ont pas de session utilisateur :
 * autorise toujours l'exécution en CLI (cron classique), et en HTTP seulement si
 * ?key= correspond à la clé partagée configurée. Termine la requête sinon.
 */
if (!function_exists('requireCliOrCronKey')):
	function requireCliOrCronKey(): void {
		if (php_sapi_name() === 'cli') {
			return;
		}
		$cleAttendue = lireCleCronWeb();
		$cleRecue = $_GET['key'] ?? '';
		if ($cleAttendue !== '' && is_string($cleRecue) && $cleRecue !== '' && hash_equals($cleAttendue, $cleRecue)) {
			return;
		}
		http_response_code(403);
		die("Accès refusé.");
	}
endif;
