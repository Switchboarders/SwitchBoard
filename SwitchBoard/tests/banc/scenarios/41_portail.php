#!/usr/bin/env php
<?php
// Le portail : chaque route de la table (src/modules/portail/Routes.php), pour un anonyme, un comité et un admin. Le
// contrôle d'accès est ce qui compte ici. La liste vient de la table elle-même : une route ajoutée est essayée d'office.
require_once '/banc/commun/banc.php';

const JOURNAL_NGINX = '/var/log/nginx/error.log';
const COMITE = 2;       // autonome
const AUTRE_COMITE = 4; // membre1, dont rien ne doit apparaître chez un autre comité
const DONNEES_AUTRE_COMITE = ['membre1', '5145550102'];
const CONSOLE = "<h1>Console d'administration</h1>";
const TABLEAU_DE_BORD = '<h1>Page principale</h1>';

// Les routes de la table qui exigent ce droit. La déconnexion fermerait la session : elle est essayée à part.
function routes(string $droit, bool $avecDeconnexion = false): array {
	$routes = array_keys(array_filter(Routes::PAGES, fn(array $route) => $route[2] === $droit));
	return $avecDeconnexion ? $routes : array_values(array_diff($routes, ['logout']));
}
function url(string $route, ?int $iduser = null): string {
	return urlPortail($route, $iduser);
}
function montreLAutreComite(string $page): bool {
	return array_filter(DONNEES_AUTRE_COMITE, fn(string $donnee) => str_contains($page, $donnee)) !== [];
}
// Les avertissements PHP écrits pendant cette requête, sans les chemins qui varient
function avertissementsDepuis(): array {
	$lignes = @file(JOURNAL_NGINX, FILE_IGNORE_NEW_LINES) ?: [];
	file_put_contents(JOURNAL_NGINX, '');
	$avertissements = [];
	foreach ($lignes as $ligne) {
		if (preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice):\s+(.*?)( in \S+ on line \d+|$)/', $ligne, $trouve)) {
			$avertissements[] = "{$trouve[1]}: {$trouve[2]}";
		}
	}
	return array_values(array_unique($avertissements));
}
function aUneErreurFatale(string $page): bool {
	return (bool)preg_match('/Fatal error|Uncaught |Stack trace/', $page);
}
// Une page s'ouvre par un lien (200, sans erreur) ; une page qui agit (Routes::ACTIONS) ne fait rien sur un GET : elle renvoie
function ouverte(array $reponse, string $route): bool {
	if (Routes::agit($route)) {
		return $reponse['code'] === 303 && str_starts_with(enTete($reponse, 'Location'), 'managementportal.php');
	}
	return $reponse['code'] === 200 && !aUneErreurFatale($reponse['page']);
}

// --- La table des routes : un seul droit par route, un seul contrôle, et les couches respectées ---
$admin = routes(Routes::ADMIN);
$connecte = routes(Routes::CONNECTE, true);
verifie(count($admin) > 0 && count($connecte) > 0, "la table des routes est lue (".count($admin)." routes d'admin, ".count($connecte)." pour toute personne connectée)");
$droitsInconnus = array_keys(array_filter(Routes::PAGES, fn(array $route) => !in_array($route[2], [Routes::ADMIN, Routes::CONNECTE], true)));
verifie($droitsInconnus === [], "chaque route déclare l'un des deux droits (".implode(', ', $droitsInconnus).")");
$classesInconnues = array_keys(array_filter(Routes::PAGES, fn(array $route) => !in_array($route[0], ['AdminPanel', 'UserAccess'], true)));
verifie($classesInconnues === [], "chaque route appelle AdminPanel ou UserAccess (".implode(', ', $classesInconnues).")");
$consoleOuverte = array_keys(array_filter(Routes::PAGES, fn(array $route) => $route[0] === 'AdminPanel' && $route[2] !== Routes::ADMIN));
verifie($consoleOuverte === [], "toute route de la console d'administration (AdminPanel) est réservée aux admins (".implode(', ', $consoleOuverte).")");
verifie(Routes::pageParDefaut(true) === 'adminpanel' && Routes::pageParDefaut(false) === 'mainpage', "sans page : la console pour un admin, le tableau de bord sinon");
verifie(Routes::trouver('nexistepas') === null && Routes::trouver('mainpage')['methode'] === 'displaymainuserpage', "trouver() donne la route d'une page, et rien pour une page inconnue");

$table = php_strip_whitespace(APPLI.'/src/modules/portail/Routes.php');
verifie(!preg_match('/\b(echo|print|die|exit)\b|\$_[A-Z]+|\$GLOBALS|\b(header|http_response_code|setcookie|session_\w+|file_put_contents|fopen|fwrite|mail)\s*\(|\b(SELECT|INSERT|UPDATE|DELETE|ALTER|CREATE) /', $table),
	"Routes.php : ne lit pas la requête, n'écrit rien, ne s'arrête jamais, pas de SQL");
$entree = php_strip_whitespace(APPLI.'/src/managementportal.php');
verifie(!preg_match('/\$_GET\s*\[\s*["\']page["\']\s*\]\s*[!=]=/', $entree), "managementportal.php : plus aucune comparaison de \$_GET[\"page\"], tout passe par la table");

repartirAZero();
ecrireAgenda('autonome', [['nom' => 'Jean', 'description' => '514-555-0111', 'debut' => time() - 3600, 'fin' => time() + 3600]]);
avertissementsDepuis();

// --- Anonyme : toutes les routes montrent la connexion, et rien d'autre ---
foreach (array_merge([''], array_keys(Routes::PAGES)) as $route) {
	oublierSession();
	$reponse = requetePortail(url($route));
	verifie(str_contains($reponse['page'], 'name="username"'), "anonyme, $route : le formulaire de connexion s'affiche");
	verifie(!str_contains($reponse['page'], 'logout'), "anonyme, $route : aucun contenu du portail");
}
connu(($avertissements = avertissementsDepuis()) === [], "anonyme : aucun avertissement PHP (".implode(' | ', array_slice($avertissements, 0, 3)).")");

// --- Connexion ---
oublierSession();
$refus = requetePortail(PORTAIL, ['username' => 'autonome', 'password' => 'mauvais']);
verifie(str_contains($refus['page'], 'Mauvais mot de passe'), "connexion : un mauvais mot de passe est refusé");
verifie(str_contains(requetePortail(PORTAIL.'?page=mainpage')['page'], 'name="username"'), "connexion : un mauvais mot de passe n'ouvre aucune session");

// --- Comité : ses pages s'ouvrent, avec ou sans son propre iduser ; celles d'un autre comité et de l'admin, non ---
connecterAuPortail('autonome');
$reponse = requetePortail(PORTAIL);
verifie($reponse['code'] === 200 && str_contains($reponse['page'], TABLEAU_DE_BORD) && !str_contains($reponse['page'], CONSOLE), "comité, sans page : le tableau de bord s'affiche (code {$reponse['code']})");
foreach (routes(Routes::CONNECTE) as $route) {
	foreach ([null, COMITE] as $iduser) {
		$nom = $iduser === null ? $route : "$route avec son propre iduser";
		$reponse = requetePortail(url($route, $iduser));
		verifie(ouverte($reponse, $route), "comité, $nom : ".(Routes::agit($route) ? "un GET n'agit pas : il renvoie" : "la page s'affiche")." (code {$reponse['code']})");
		verifie(!str_contains($reponse['page'], 'name="username"'), "comité, $nom : la session tient");
	}
}
connu(($avertissements = avertissementsDepuis()) === [], "comité : aucun avertissement PHP (".implode(' | ', array_slice($avertissements, 0, 3)).")");
foreach (routes(Routes::CONNECTE, true) as $route) {
	$reponse = requetePortail(url($route, AUTRE_COMITE));
	verifie($reponse['code'] === 403 && !montreLAutreComite($reponse['page']), "comité, $route avec l'iduser d'un autre comité : refusé avec le code 403 (code {$reponse['code']}), sans ses données");
}
foreach (routes(Routes::ADMIN) as $route) {
	$reponse = requetePortail(url($route, AUTRE_COMITE));
	verifie($reponse['code'] === 403 && str_contains($reponse['page'], "Vous n'avez pas les privilèges") && !montreLAutreComite($reponse['page']),
		"comité, $route : refusé avec le code 403 (code {$reponse['code']}), sans aucune donnée");
}
verifie(!str_contains(requetePortail(url('mainpage'))['page'], 'name="username"'), "comité : la session tient après les refus, déconnexion comprise");
avertissementsDepuis();

// --- Admin : tout s'ouvre, y compris avec l'iduser d'un comité ---
connecterAuPortail('admin');
$reponse = requetePortail(PORTAIL);
verifie($reponse['code'] === 200 && str_contains($reponse['page'], CONSOLE), "admin, sans page : la console s'affiche (code {$reponse['code']})");
foreach (routes(Routes::CONNECTE) as $route) {
	$reponse = requetePortail(url($route));
	verifie(ouverte($reponse, $route), "admin, $route : ".(Routes::agit($route) ? "un GET n'agit pas : il renvoie" : "la page s'affiche")." (code {$reponse['code']})");
}
foreach (array_merge(routes(Routes::CONNECTE), routes(Routes::ADMIN)) as $route) {
	$reponse = requetePortail(url($route, COMITE));
	verifie(ouverte($reponse, $route), "admin, $route avec l'iduser d'un comité : ".(Routes::agit($route) ? "un GET n'agit pas : il renvoie" : "la page s'affiche")." (code {$reponse['code']})");
}
connu(($avertissements = avertissementsDepuis()) === [], "admin : aucun avertissement PHP (".implode(' | ', array_slice($avertissements, 0, 3)).")");

// --- Suggestions de cron : le PHP de l'installation (problème 14) ---
$cron = requetePortail(url('croncommands'))['page'];
verifie(str_contains($cron, PHP_BINDIR.'/php -q '.SRC_DIRECTORY.'refreshallringgroups.php') && substr_count($cron, ' -q ') === 8, "suggestions de cron : les 8 commandes utilisent le PHP de l'installation (".PHP_BINDIR."/php)");

// --- « Forcer le changement des répondants » du comité de son choix, depuis la console de l'admin (#78) ---
connecterAuPortail('admin');
$console = requetePortail(PORTAIL)['page'];
foreach ([COMITE, AUTRE_COMITE] as $iduser) {
	verifie(preg_match("/<form class='form-action[^']*' method='post' action='managementportal.php\?page=refreshringgroup&amp;iduser=$iduser'[^>]*>(?:(?!<\/form>).)*name='csrf_token'/s", $console) === 1, "console : le comité $iduser, qui a un agenda, offre « Forcer » par un bouton POST avec le jeton");
}
verifie(preg_match("/page=refreshringgroup&amp;iduser=(1|3|7)'/", $console) !== 1, "console : ni l'admin, ni le compte principal, ni la ligne provinciale (sans agenda) n'offrent « Forcer »");
viderJournalFaux();
$reponse = ouvrirPage('refreshringgroup', COMITE);
verifie($reponse['code'] === 200 && str_contains($reponse['page'], '<i>autonome</i>') && str_contains($reponse['page'], 'Le résultat de l'), "admin, « Forcer » pour un comité : la page dit lequel et affiche le résultat (code {$reponse['code']})");
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111']], "admin, « Forcer » pour un comité : le répondant de l'agenda de ce comité est dans son groupe de sonnerie");
verifie(array_unique(methodesVoipms('banc-region')) === [] && journalFaux('voip.ms') !== [], "admin, « Forcer » pour un comité : seul le compte de ce comité est appelé");
$sansJeton = requetePortail(PORTAIL.'?page=refreshringgroup&iduser='.COMITE, ['x' => '1']);
verifie($sansJeton['code'] === 403, "admin, « Forcer » pour un comité sans jeton : refusé (code {$sansJeton['code']})");

// --- Page inconnue ---
$reponse = requetePortail(url('nexistepas'));
verifie($reponse['code'] === 404 && str_contains($reponse['page'], 'Problème avec le paramètre page'), "une page inconnue répond 404 et le dit (code {$reponse['code']})");
$reponse = requetePortail(PORTAIL.'?page[]=adminpanel');
verifie($reponse['code'] === 404 && str_contains($reponse['page'], 'Problème avec le paramètre page'), "un paramètre page qui n'est pas un texte : 404 (code {$reponse['code']})");

// --- Aucune action ne s'accepte par une adresse : POST seulement, avec le jeton ---
$tousLesSources = shell_exec('grep -rIl "csrftoken" '.escapeshellarg(APPLI.'/src').' --include=*.php --include=*.js 2>/dev/null | grep -v "/languages/\|/vendor/"');
verifie(trim((string)$tousLesSources) === '', "CSRF : plus aucun code ne lit ni n'écrit un jeton dans une adresse (csrftoken) : ".trim((string)$tousLesSources));
$bd = connexionBD();
$jeton = jetonCsrfDeLaSession();
$sansJeton = requetePortail(PORTAIL.'?page=adminpanel', ['action' => 'suppressuser', 'iduser' => 5]);
verifie($sansJeton['code'] === 403, "CSRF : supprimer un utilisateur sans jeton est refusé (code {$sansJeton['code']})");
requetePortail(PORTAIL.'?page=adminpanel&action=suppressuser&iduser=5&csrftoken='.urlencode($jeton));
verifie($bd->query('SELECT COUNT(*) FROM `user` WHERE iduser = 5')->fetch_row()[0] == 1, "CSRF : un lien GET (même avec le jeton) ne supprime pas l'utilisateur");
$sansJeton = requetePortail(PORTAIL.'?page=mainpage', ['action' => 'deletealllogs']);
verifie($sansJeton['code'] === 403, "CSRF : effacer les journaux sans jeton est refusé (code {$sansJeton['code']})");
$sansJeton = requetePortail(PORTAIL.'?page=adminpanel', ['action' => 'toggledebug']);
$debugActif = fn() => trim((string)@file_get_contents(cheminFlagDebug())) === '1';
verifie($sansJeton['code'] === 403 && !$debugActif(), "CSRF : le mode debug sans jeton est refusé (code {$sansJeton['code']})");
requetePortail(PORTAIL.'?page=adminpanel&action=toggledebug&csrftoken='.urlencode($jeton));
verifie(!$debugActif(), "CSRF : un lien GET ne bascule pas le mode debug");

// Les mêmes actions, par leur bouton : elles agissent, puis la page redirige (F5 ne les refait pas)
$reponse = requetePortail(PORTAIL.'?page=adminpanel', ['action' => 'toggledebug', 'csrf_token' => $jeton]);
verifie($reponse['code'] === 303 && enTete($reponse, 'Location') === 'managementportal.php?page=adminpanel' && $debugActif(), "mode debug : le bouton le bascule et redirige vers la console (code {$reponse['code']})");
requetePortail(PORTAIL.'?page=adminpanel', ['action' => 'toggledebug', 'csrf_token' => $jeton]);
verifie(!$debugActif(), "mode debug : un deuxième clic le remet comme avant");
@unlink(cheminFlagDebug());
$bd->query("INSERT INTO `user` (iduser, username, password, privilegelevel) VALUES (9, 'asupprimer', 'x', 10)");
$reponse = requetePortail(PORTAIL.'?page=adminpanel', ['action' => 'suppressuser', 'iduser' => 9, 'csrf_token' => $jeton]);
verifie($reponse['code'] === 303 && $bd->query('SELECT COUNT(*) FROM `user` WHERE iduser = 9')->fetch_row()[0] == 0, "supprimer un utilisateur : le bouton le supprime et redirige (code {$reponse['code']})");

// --- CSRF : une page qui agit n'agit qu'en POST, avec son jeton (voip.ms, SwitchBoard Chat, SMS) ---
verifie(Routes::ACTIONS !== [] && array_diff(Routes::ACTIONS, array_keys(Routes::PAGES)) === [], "CSRF : chaque page qui agit est dans la table des routes (".implode(', ', Routes::ACTIONS).")");
foreach (Routes::ACTIONS as $route) {
	$estAdmin = Routes::PAGES[$route][2] === Routes::ADMIN;
	connecterAuPortail($estAdmin ? 'admin' : 'autonome');
	$jeton = jetonCsrfDeLaSession();
	$iduser = $estAdmin ? "&iduser=".COMITE : '';
	viderJournalFaux();
	$sansJeton = requetePortail(PORTAIL."?page=$route$iduser", ['x' => '1']);
	$mauvaisJeton = requetePortail(PORTAIL."?page=$route$iduser", ['csrf_token' => 'faux']);
	$enGet = requetePortail(PORTAIL."?page=$route$iduser&csrftoken=".urlencode($jeton));
	verifie($sansJeton['code'] === 403 && $mauvaisJeton['code'] === 403, "CSRF : $route en POST sans jeton ou avec un faux jeton est refusé (codes {$sansJeton['code']} et {$mauvaisJeton['code']})");
	verifie(ouverte($enGet, $route), "CSRF : $route en GET, même avec le jeton, n'agit pas : il renvoie (code {$enGet['code']})");
	verifie(journalFaux('voip.ms') === [] && journalFaux('sbchat.banc.test') === [], "CSRF : $route refusé ou ouvert par un lien n'a rien demandé à voip.ms ni à SwitchBoard Chat");
	$avecJeton = ouvrirPage($route, $estAdmin ? COMITE : null);
	verifie($avecJeton['code'] === 200 && !aUneErreurFatale($avecJeton['page']), "CSRF : $route par son bouton (POST avec le jeton) agit et montre la page qui suit (code {$avecJeton['code']})");
}
// Le résultat d'une action se montre une seule fois : rouvrir la page ne le remontre pas, et n'agit pas
connecterAuPortail('autonome');
ouvrirPage('refreshringgroup');
$encore = requetePortail(PORTAIL.'?page=refreshringgroup');
verifie($encore['code'] === 303 && enTete($encore, 'Location') === 'managementportal.php', "CSRF : rouvrir la page « Forcer » après son résultat renvoie au tableau de bord (code {$encore['code']})");
$menu = requetePortail(PORTAIL.'?page=userinfo')['page'];
foreach (['refreshringgroup', 'refreshsbchat', 'remindersms', 'remindersmsjustbefore', 'remindersbchat', 'remindersbchatjustbefore'] as $route) {
	verifie(preg_match("/<form class='form-action form-action-bloc' method='post' action='managementportal.php\?page=$route'>(?:(?!<\/form>).)*name='csrf_token'(?:(?!<\/form>).)*<button type='submit' class='submenu-item bouton-menu'>/s", $menu) === 1, "CSRF : le menu offre $route par un bouton POST avec le jeton de la session");
	verifie(!str_contains($menu, "href=\"managementportal.php?page=$route"), "CSRF : le menu n'offre plus $route par un lien");
}

// --- Déconnexion ---
$reponse = requetePortail(url('logout'));
verifie(str_contains($reponse['page'], 'déconnecté'), "déconnexion : le portail le dit");
verifie(str_contains(requetePortail(url('mainpage'))['page'], 'name="username"'), "déconnexion : le portail redemande la connexion");

// --- Une session restée ouverte après la suppression de son utilisateur est fermée ---
// Avant, getcurrentuserprivilegelevel() retournait false, que « > 0 » prenait pour un admin : la console s'ouvrait.
// Le mode debug affiche les avertissements PHP dans la page, pour voir que la fermeture n'en produit aucun.
$hachage = password_hash(MOT_DE_PASSE_PORTAIL, PASSWORD_DEFAULT);
foreach (array_merge([''], array_keys(Routes::PAGES)) as $route) {
	$bd->query("INSERT INTO `user` (iduser, username, password, privilegelevel) VALUES (8, 'supprime', '$hachage', 10)");
	oublierSession();
	$connecte = !str_contains(requetePortail(PORTAIL, ['username' => 'supprime', 'password' => MOT_DE_PASSE_PORTAIL])['page'], 'name="username"');
	foreach (['user', 'userinfo', 'userservice', 'usertokenaccess'] as $table) {
		$bd->query("DELETE FROM `$table` WHERE iduser = 8");
	}
	definirModeDebug(true);
	$reponse = requetePortail(url($route, AUTRE_COMITE));
	@unlink(cheminFlagDebug());
	$avertissement = preg_match('/\b(Warning|Notice|Deprecated|Fatal error)(<\/b>)?:/', $reponse['page']) === 1;
	$resteFermee = str_contains(requetePortail(url('mainpage'))['page'], 'name="username"');
	verifie($connecte && str_contains($reponse['page'], 'name="username"') && !str_contains($reponse['page'], 'logout') && !montreLAutreComite($reponse['page']) && !$avertissement && $resteFermee,
		"session d'un utilisateur supprimé, $route : renvoyée à la page de connexion, sans aucune donnée ni avertissement PHP, et reste fermée");
}
avertissementsDepuis();

// --- Dossiers protégés du web ---
$racine = 'http://127.0.0.1/SwitchBoard/SwitchBoard';
foreach (['config/config.php', 'backups/x', 'download/x', 'vendor/autoload.php', 'logs/x.txt', 'calendarcache/x.ical', '.git/config'] as $chemin) {
	verifie(requetePortail("$racine/$chemin")['code'] === 403, "web : $chemin est bloqué");
}

finDuScenario();
