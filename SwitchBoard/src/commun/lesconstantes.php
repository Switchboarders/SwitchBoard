<?php
// L'amorce de SwitchBoard : chaque cron, page et script la charge en premier. Elle définit les chemins et les
// réglages, règle le fuseau horaire, charge vendor/ et l'autoload des classes de src/modules/.
// Elle n'écrit jamais rien sur la sortie : Docker/generate_mail_config.sh lit la sortie d'un php -r qui la charge,
// et msmtp lit celle de mailoauthtoken.php comme mot de passe.

// Chemin absolu de base, exemple : /var/www/html/switchboard/. Le seul chemin calculé depuis l'emplacement d'un
// fichier : tous les autres en découlent (CONVENTIONS.md).
define('BASE_DIRECTORY', dirname(__FILE__,3).'/');

// Dernier dossier du chemin de base (sans slash final)
define('BASE_DIRECTORY_LAST_FOLDER', basename(rtrim(BASE_DIRECTORY, '/')));

// Répertoires clés relatifs au baseDirectory
define('VENDOR_DIRECTORY', BASE_DIRECTORY."vendor/");
define('BCK_SWITCHBOARD_DIRECTORY', "backups/");
define('BACKUPS_DIRECTORY', BASE_DIRECTORY.BCK_SWITCHBOARD_DIRECTORY);
define('DOWNLOAD_DIRECTORY', BASE_DIRECTORY."download/");
define('SRC_DIRECTORY', BASE_DIRECTORY."src/");
define('CONFIG_DIRECTORY', BASE_DIRECTORY."config/");
define('COMMUN_DIRECTORY', SRC_DIRECTORY."commun/");
define('PORTAIL_DIRECTORY', SRC_DIRECTORY."portail/");
define('DOSSIER_TRADUCTIONS', SRC_DIRECTORY."languages/locale/");
// Données créées à l'exécution : hors de src/, que la mise à jour remplace
define('DOSSIER_JOURNAUX', BASE_DIRECTORY."logs/");
define('DOSSIER_CACHE_AGENDAS', BASE_DIRECTORY."calendarcache/");

// L'heure du Québec pour tout SwitchBoard, quel que soit le date.timezone du serveur (UTC sur certains
// hébergements cPanel) : les agendas, les rappels et les rapports en dépendent. America/New_York a les mêmes règles.
define('FUSEAU_HORAIRE', 'America/New_York');

// Attente avant le second essai d'un courriel, en secondes. defined() : le banc les raccourcit (image/attentes-courtes.php).
defined('DELAI_SECOND_ESSAI_COURRIEL') || define('DELAI_SECOND_ESSAI_COURRIEL', 5);
defined('DELAI_SECOND_ESSAI_CANARI') || define('DELAI_SECOND_ESSAI_CANARI', 30);

// Variables spécifiques au projet
// Le jeton GitHub est facultatif : le dépôt est public, et la mise à jour l'interroge sans
// jeton. Un jeton (lecture seule) ne sert qu'à lever la limite de GitHub pour les requêtes
// anonymes (60 par heure et par adresse IP), ou à suivre un dépôt privé (une copie, par
// exemple). Il n'est JAMAIS écrit dans le code source : il provient soit de la variable
// d'environnement SB_GITHUB_TOKEN (ou GITHUB_TOKEN, fournie ainsi par la stack Docker de ce
// projet - voir Docker/.env_sample), soit de $githubtoken dans config/config.php. Un jeton
// refusé par GitHub (révoqué, expiré) n'empêche pas la mise à jour : RepoManager refait la
// demande sans jeton, et la console d'administration le signale.
if (!function_exists('lireJetonGithub')):
	function lireJetonGithub() {
		$jeton = getenv('SB_GITHUB_TOKEN');
		if (is_string($jeton) && $jeton !== '') {
			return $jeton;
		}
		$jeton = getenv('GITHUB_TOKEN');
		if (is_string($jeton) && $jeton !== '') {
			return $jeton;
		}
		$fichierConfig = CONFIG_DIRECTORY.'config.php';
		if (is_readable($fichierConfig)) {
			// Inclus dans la portée de la fonction pour ne pas polluer les variables globales
			include $fichierConfig;
			if (isset($githubtoken) && is_string($githubtoken) && $githubtoken !== '') {
				return $githubtoken;
			}
		}
		return '';
	}
endif;
define('TOKEN_REPO', lireJetonGithub());
define('TOKEN_REPO_CONFIGURE', TOKEN_REPO !== '');
define('OWNER_REPOSITORY', "SwitchBoarders");

// Nom du dépôt GitHub - utilisé pour les chemins
define('REPOSITORY_NAME', "SwitchBoard");

// Branche utilisée pour les mises à jour quand le dépôt n'a publié aucune « release » (voir
// UpdateManager::resolveRef()) : la branche principale du dépôt public
define('BRANCHE_POUR_MAJ', "main");

// Noms de fichiers JSON version
define('NOM_FICHIER_DB_VERSION', "dbVersion.json");

// Dossier pour les fichiers updatejson
define('DOSSIER_UPDATEJSON', BASE_DIRECTORY."src/updatejson/");

// Chemins vers fichiers distants dans le repository GitHub
define('NOM_FICHIER_CODE_VERSION', "codeVersion.json");
define('FICHIER_LOCAL_CODE_VERSION', DOSSIER_UPDATEJSON.NOM_FICHIER_CODE_VERSION);
define('FICHIER_DISTANT_CODE_VERSION', REPOSITORY_NAME."/src/updatejson/".NOM_FICHIER_CODE_VERSION);

// Autres valeurs constantes de projet
define('SUFFIXE_FICHIER_DEPENDANCES', "precompileddependencies");

// Répertoire de backup sans slash final
define('NOM_REPERTOIRE_BACKUP_SANSLA_BASE', "backups");

// Nom du fichier de backup Base de Données
define('FICHIER_BACKUP_BD', "BD.sql");


// Fonctions de garde (CSRF, contrôle d'accès admin, protection des scripts batch) : chargées
// ici pour être disponibles partout, puisque tous les points d'entrée chargent ce fichier en premier.
require_once COMMUN_DIRECTORY.'authhelpers.php';

// Affichage des erreurs PHP à l'écran : contrôlé ici pour TOUS les points d'entrée
// (managementportal.php, token.php, les scripts batch), plutôt que codé en dur "on"
// dans chaque fichier. Désactivé par défaut : les messages d'erreur (chemins serveur,
// requêtes SQL, etc.) ne doivent pas être visibles publiquement en production. Les
// erreurs restent journalisées (log_errors reste au réglage de PHP.ini) même si non
// affichées.
//
// Displaying PHP errors on screen: controlled here for ALL entry points
// (managementportal.php, token.php, the batch scripts), instead of hard-coded "on" in
// each file. Off by default: error messages (server paths, SQL queries, etc.) must not
// be visible publicly in production. Errors are still logged (log_errors keeps whatever
// PHP.ini says) even when not displayed.
//
// Trois façons de l'activer, dans cet ordre de priorité :
//  1. SB_DEBUG=1 (variable d'environnement) - contrôle ops, prioritaire sur tout le reste
//     (utile pour forcer l'état voulu depuis Docker, quoi que fasse l'admin dans l'interface).
//  2. Le bouton de la console d'administration (voir adminpanel.php) - écrit dans le
//     fichier config/debugmode.flag ci-dessous. C'est l'état "courant", persistant entre
//     les requêtes, jusqu'au prochain changement (par le bouton ou par SB_DEBUG).
//  3. $modedebug = true; dans config/config.php - valeur de secours si aucun des deux
//     ci-dessus n'est présent (ancienne façon de faire, conservée pour compatibilité).
//
// Three ways to enable it, in this priority order:
//  1. SB_DEBUG=1 (environment variable) - ops-level control, wins over everything else
//     (useful to force the desired state from Docker regardless of what the admin does
//     in the UI).
//  2. The admin console toggle (see adminpanel.php) - writes to the config/debugmode.flag
//     file below. This is the "current" state, persisted across requests, until the next
//     change (via the toggle or via SB_DEBUG).
//  3. $modedebug = true; in config/config.php - fallback value if neither of the above
//     is present (the older way of doing it, kept for backward compatibility).
if (!function_exists('cheminFlagDebug')):
	function cheminFlagDebug() {
		// Dans config/ : déjà protégé du web (voir protegerDossierDuWeb() dans
		// Fichiers.php), comme config.php lui-même.
		// Inside config/: already blocked from the web (see protegerDossierDuWeb() in
		// Fichiers.php), same as config.php itself.
		return CONFIG_DIRECTORY.'debugmode.flag';
	}
endif;
if (!function_exists('modeDebugForceParEnv')):
	// Indique si SB_DEBUG force l'état (le bouton de la console ne peut alors rien changer) :
	// utilisé par l'interface pour l'expliquer plutôt que de laisser croire que le bouton
	// n'a pas fonctionné.
	// Reports whether SB_DEBUG is forcing the state (the console button can then change
	// nothing): used by the UI to explain this, rather than letting the button look broken.
	function modeDebugForceParEnv() {
		$valeur = getenv('SB_DEBUG');
		return is_string($valeur) && $valeur !== '';
	}
endif;
if (!function_exists('modeDebugActif')):
	function modeDebugActif() {
		$valeur = getenv('SB_DEBUG');
		if (is_string($valeur) && $valeur !== '') {
			return $valeur === '1' || strtolower($valeur) === 'true';
		}
		$fichierFlag = cheminFlagDebug();
		if (is_readable($fichierFlag)) {
			return trim(file_get_contents($fichierFlag)) === '1';
		}
		$fichierConfig = CONFIG_DIRECTORY.'config.php';
		if (is_readable($fichierConfig)) {
			include $fichierConfig;
			if (isset($modedebug)) {
				return (bool)$modedebug;
			}
		}
		return false;
	}
endif;
if (!function_exists('definirModeDebug')):
	// Écrit l'état choisi depuis la console d'administration dans le fichier drapeau.
	// Sans effet si SB_DEBUG force déjà l'état (l'appelant doit vérifier
	// modeDebugForceParEnv() avant d'inviter l'admin à cliquer).
	// Writes the state chosen from the admin console into the flag file. Has no effect
	// if SB_DEBUG already forces the state (the caller should check
	// modeDebugForceParEnv() before inviting the admin to click).
	function definirModeDebug(bool $actif) {
		if (modeDebugForceParEnv()) {
			return false;
		}
		return @file_put_contents(cheminFlagDebug(), $actif ? '1' : '0') !== false;
	}
endif;
if (modeDebugActif()) {
	ini_set('display_startup_errors', 1);
	ini_set('display_errors', 1);
	error_reporting(-1);
} else {
	ini_set('display_startup_errors', 0);
	ini_set('display_errors', 0);
	error_reporting(E_ALL);
}

// Retourne $detail (message d'erreur technique, requête SQL, etc.) seulement en mode
// debug ; sinon un message générique, pour ne pas exposer de détails internes (schéma
// de la base de données, messages du pilote SQL) aux utilisateurs en production.
if (!function_exists('messageErreurTechnique')):
	function messageErreurTechnique($detail) {
		return modeDebugActif() ? $detail : "Une erreur technique est survenue. Consultez les journaux du serveur pour plus de détails.";
	}
endif;

date_default_timezone_set(FUSEAU_HORAIRE);

// miseajour.php doit pouvoir démarrer sans vendor/ (installation ratée) : c'est la mise à jour qui le remet en place.
if (is_file(VENDOR_DIRECTORY.'autoload.php')) {
	require_once VENDOR_DIRECTORY.'autoload.php';
}

// Les classes de src/modules/<module>/<Classe>.php se chargent seules, sans namespace : déplacer une classe d'un
// module à l'autre ne change rien pour ses appelants. Les dépendances de vendor/ ont leur propre autoload.
spl_autoload_register(function (string $classe): void {
	$fichiers = glob(SRC_DIRECTORY.'modules/*/'.$classe.'.php');
	if ($fichiers !== false && $fichiers !== []) {
		require_once $fichiers[0];
	}
});

// Chargés ici, et non par l'autoload ni par les appelants : voir oauthcourriel.php
require_once SRC_DIRECTORY.'commun/certificatssbchat.php';
require_once SRC_DIRECTORY.'commun/oauthcourriel.php';
require_once SRC_DIRECTORY.'commun/surveillance.php';
