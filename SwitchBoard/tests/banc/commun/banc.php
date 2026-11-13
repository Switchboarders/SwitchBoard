<?php
// Ce que les scénarios PHP du banc ont en commun. Ils tournent en root dans le conteneur, et les crons
// passent à www-data par leur vraie ligne de crontab.
const APPLI = '/var/www/html/SwitchBoard/SwitchBoard';
const ETAT_BANC = '/var/banc-etat';
const PORTAIL = 'http://127.0.0.1/SwitchBoard/SwitchBoard/src/managementportal.php';

date_default_timezone_set('America/New_York');
require_once APPLI.'/tests/verifie.php';
require_once APPLI.'/src/commun/lesconstantes.php';

// Les appels des faux services que le scénario précédent a laissés ne sont pas les siens : l'empreinte des requêtes
// voip.ms (plus bas) ne doit dire que ce que ce scénario a fait.
@unlink(ETAT_BANC.'/journal.jsonl');

// lesconstantes.php coupe l'affichage des erreurs : un scénario qui plante doit dire pourquoi, et un
// scénario qui s'arrête avant finDuScenario() ne doit jamais passer pour réussi.
ini_set('display_errors', '1');
$scenarioTermine = false;
register_shutdown_function(function () {
	global $scenarioTermine;
	if (!$scenarioTermine) {
		echo "ECHEC: le scénario s'est arrêté avant la fin\n";
		exit(1);
	}
});

// Les comités inventés, sur une BD neuve et avec un état des faux services vide. Retourne les erreurs
// de la création de la BD (voir installerBD()) : aucune pour l'instant.
function repartirAZero(): array {
	viderEtatFaux();
	$erreurs = installerBD();
	require_once dirname(__DIR__).'/donnees/comites.php';
	chargerComites();
	return $erreurs;
}

function connexionBD(): mysqli {
	return new mysqli('db', 'banc', 'banc', 'switchboard');
}

// Une BD à la version actuelle, comme une installation neuve : le vrai constructeur de DatabaseManager crée la structure
// figée (commun/structure-figee-*.sql) puis applique lui-même les migrations de dbVersion.json au-dessus de sa version.
// Retourne les erreurs de cette création : la liste doit rester vide.
function installerBD(): array {
	viderBD();
	$erreurs = [];
	try {
		ob_start();
		new DatabaseManager();
	} catch (Throwable $erreur) {
		$erreurs[] = $erreur->getMessage();
	} finally {
		ob_end_clean();
	}
	return $erreurs;
}

function viderBD(): void {
	$bd = connexionBD();
	$tables = $bd->query("SELECT table_name FROM information_schema.tables WHERE table_schema = 'switchboard'");
	foreach ($tables->fetch_all() as [$table]) {
		$bd->query("DROP TABLE `$table`");
	}
}

function viderEtatFaux(): void {
	noterEmpreinteVoipms();
	array_map(fn($fichier) => is_dir($fichier) ? exec('rm -rf '.escapeshellarg($fichier)) : unlink($fichier), glob(ETAT_BANC.'/*'));
	array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*.ical'));
}

// Oublie les appels déjà reçus par les faux services. Le fichier est effacé et non vidé : créé ici, il
// serait à root, et les faux services (www-data) ne pourraient plus y écrire.
function viderJournalFaux(): void {
	noterEmpreinteVoipms();
	@unlink(ETAT_BANC.'/journal.jsonl');
}

// Comme verifie(), pour un défaut connu du code actuel : le dit sans rendre le banc rouge. Quand le
// défaut est corrigé, l'appel devient un verifie() (mise à jour : étape 3 du plan).
function connu(bool $conditionVoulue, string $description): void {
	echo ($conditionVoulue ? 'OK' : 'CONNU').": $description\n";
}

// Seuls ces comités reçoivent le cron de sonnerie : les autres, sans agenda, n'ajoutent pas de bruit
function sonnerieSeulementPour(array $idusers): void {
	$liste = implode(',', array_map('intval', $idusers));
	connexionBD()->query("UPDATE userservice SET ringgroupupdate = (iduser IN ($liste))");
}

// Les pings reçus par le faux service de signal de vie pour ce check : '/banc-ringgroup', '/banc-ringgroup/fail'...
function pingsSignalDeVie(string $check): array {
	$chemins = array_column(journalFaux('hc-ping.com'), 'chemin');
	return array_values(array_filter($chemins, fn($chemin) => str_starts_with($chemin, "/banc-$check")));
}

// L'ensemble des renvois du compte, en numéros de téléphone triés
function renvoisVoipms(string $compte): array {
	$etat = json_decode((string)@file_get_contents(ETAT_BANC."/voipms-$compte.json"), true) ?? [];
	$numeros = array_column($etat['renvois'] ?? [], 'phone_number');
	sort($numeros);
	return $numeros;
}

// Dépose l'agenda d'un comité. Un quart : ['nom', 'description', 'debut' (timestamp), 'fin' (timestamp)]
function ecrireAgenda(string $comite, array $quarts): void {
	$date = fn(int $moment) => gmdate('Ymd\THis\Z', $moment);
	$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Banc//FR\r\n";
	foreach ($quarts as $indice => $quart) {
		$ics .= "BEGIN:VEVENT\r\nUID:quart$indice@banc.test\r\nDTSTAMP:{$date(time())}\r\nSUMMARY:{$quart['nom']}\r\n"
			."DESCRIPTION:{$quart['description']}\r\nDTSTART:{$date($quart['debut'])}\r\nDTEND:{$date($quart['fin'])}\r\nEND:VEVENT\r\n";
	}
	file_put_contents(ETAT_BANC."/agenda-$comite.ics", $ics."END:VCALENDAR\r\n");
}

// Les $codes HTTP que le faux service de cet hôte répondra à ses prochaines requêtes
function simulerPanne(string $hote, array $codes): void {
	file_put_contents(ETAT_BANC."/panne-$hote", implode("\n", $codes)."\n");
}

// Lance la vraie ligne de Docker/cronjobs qui contient $motif. Retourne ['code', 'sortie'].
function lancerCron(string $motif): array {
	foreach (file('/etc/banc/cronjobs', FILE_IGNORE_NEW_LINES) as $ligne) {
		if ($ligne !== '' && $ligne[0] !== '#' && str_contains($ligne, $motif)) {
			$commande = preg_replace('/^(\S+\s+){5}/', '', $ligne);
			file_put_contents('/var/log/cron.log', '');
			exec($commande, $sortie, $code);
			return ['code' => $code, 'sortie' => (string)file_get_contents('/var/log/cron.log')];
		}
	}
	throw new RuntimeException("Aucune ligne de cron ne contient : $motif");
}

// Toutes les vraies lignes de cron, sans leur horaire
function lignesCron(): array {
	$lignes = array_filter(file('/etc/banc/cronjobs', FILE_IGNORE_NEW_LINES), fn($ligne) => $ligne !== '' && $ligne[0] !== '#');
	return array_values(array_map(fn($ligne) => preg_replace('/^(\S+\s+){5}/', '', $ligne), $lignes));
}

// Compare ce courriel à sa référence dans reference/. lancer.sh --nouvelles-references remplace les
// références par les résultats : à relire avant de les commiter, car elles deviennent la vérité.
function comparerReference(string $nom, string $contenu): void {
	$sortie = '/var/banc-sortie/reference';
	@mkdir($sortie, 0755, true);
	file_put_contents("$sortie/$nom", $contenu);
	$attendu = @file_get_contents("/banc/reference/$nom");
	if ($attendu === $contenu) {
		verifie(true, "référence $nom : identique");
		return;
	}
	if (getenv('BANC_NOUVELLES_REFERENCES') === '1') {
		echo "OK: référence $nom : remplacée\n";
		return;
	}
	verifie(false, "référence $nom : différente".($attendu === false ? ' (aucune référence)' : ''));
	file_put_contents('/tmp/attendu', (string)$attendu);
	file_put_contents('/tmp/obtenu', $contenu);
	passthru('diff /tmp/attendu /tmp/obtenu | head -30');
}

// Une requête au portail, comme derrière le proxy. Le cookie est marqué « secure » : curl ne le renverrait
// pas en HTTP, alors on le garde ici. Retourne ['code', 'page']. oublierSession() repart sans session.
function requetePortail(string $url, array $champs = []): array {
	global $cookiePortail;
	$c = curl_init($url);
	curl_setopt_array($c, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 300,
		CURLOPT_HTTPHEADER => ['X-Forwarded-Proto: https', 'Cookie: '.($cookiePortail ?? '')]]);
	if ($champs !== []) {
		curl_setopt($c, CURLOPT_POSTFIELDS, http_build_query($champs));
	}
	$reponse = (string)curl_exec($c);
	if (preg_match_all('/^Set-Cookie: (PHPSESSID=[^;]+)/mi', $reponse, $trouve)) {
		$cookiePortail = end($trouve[1]);
	}
	return ['code' => curl_getinfo($c, CURLINFO_HTTP_CODE), 'page' => $reponse];
}
function oublierSession(): void {
	global $cookiePortail;
	$cookiePortail = '';
}
// Le jeton CSRF de la session ouverte, lu dans un lien de l'en-tête (ceux des pages qui agissent le portent) ; vide sans session
function jetonCsrfDeLaSession(): string {
	// La page des informations du compte : son menu a les boutons (un formulaire chacun), et elle ne demande rien à voip.ms (l'empreinte du scénario le verrait)
	return jetonDuFormulaire(PORTAIL.'?page=userinfo');
}
// L'adresse d'une page du portail, telle qu'on l'ouvre par un lien (GET)
function urlPortail(string $route, ?int $iduser = null): string {
	return PORTAIL."?page=$route".($iduser === null ? '' : "&iduser=$iduser");
}
// Une page qui agit (Routes::agit) ne s'ouvre pas par un lien : on la déclenche comme son bouton, par un POST avec le jeton de
// la session, et on retourne la page où la redirection mène. Une autre page s'ouvre par un GET.
function ouvrirPage(string $route, ?int $iduser = null): array {
	if (!Routes::agit($route)) {
		return requetePortail(urlPortail($route, $iduser));
	}
	$envoi = requetePortail(urlPortail($route, $iduser), ['csrf_token' => jetonCsrfDeLaSession()]);
	return suivreRedirection($envoi);
}
function connecterAuPortail(string $utilisateur): void {
	oublierSession();
	requetePortail(PORTAIL, ['username' => $utilisateur, 'password' => MOT_DE_PASSE_PORTAIL]);
}

// Les appels reçus par les faux services, du plus ancien au plus récent
function journalFaux(?string $hote = null): array {
	$lignes = @file(ETAT_BANC.'/journal.jsonl', FILE_IGNORE_NEW_LINES) ?: [];
	$appels = array_map(fn($ligne) => json_decode($ligne, true), $lignes);
	return array_values(array_filter($appels, fn($appel) => $hote === null || $appel['hote'] === $hote));
}

// Les noms des méthodes voip.ms appelées pour ce compte
function methodesVoipms(string $compte): array {
	$appels = array_filter(journalFaux('voip.ms'), fn($appel) => ($appel['params']['api_username'] ?? '') === $compte);
	return array_values(array_map(fn($appel) => $appel['params']['method'] ?? '', $appels));
}

// Le numéro de chaque membre du groupe de sonnerie de ce compte, par nom de groupe
function groupesVoipms(string $compte): array {
	$etat = json_decode((string)@file_get_contents(ETAT_BANC."/voipms-$compte.json"), true) ?? [];
	$groupes = [];
	foreach ($etat['groupes'] ?? [] as $groupe) {
		$groupes[$groupe['name']] = array_map(fn($id) => $etat['renvois'][$id]['phone_number'] ?? "renvoi efface $id", $groupe['members']);
	}
	return $groupes;
}

// Les formulaires du portail (Post/Redirect/Get, #77)
function jetonDuFormulaire(string $adresse): string {
	preg_match("/name='csrf_token' value='([^']+)'/", requetePortail($adresse)['page'], $trouve);
	return html_entity_decode($trouve[1] ?? '', ENT_QUOTES);
}
function enTete(array $reponse, string $nom): string {
	return preg_match('/^'.preg_quote($nom, '/').': (.*?)\r?$/mi', $reponse['page'], $trouve) === 1 ? $trouve[1] : '';
}
function ligneDeLaBd(string $sql): array {
	$bd = connexionBD();
	return $bd->query($sql)->fetch_assoc() ?? [];
}
// Le corps de la réponse, sans les en-têtes
function corps(array $reponse): string {
	return preg_replace('/^.*?\r?\n\r?\n/s', '', $reponse['page'], 1);
}

// Combien de fois la page à cette adresse contient ce texte, à deux lectures de suite : ce que l'enregistrement a dit ne
// s'affiche qu'une fois ([1, 0]), jamais au rechargement
function lectures(string $adresse, string $texte): array {
	return [substr_count(requetePortail($adresse)['page'], $texte), substr_count(requetePortail($adresse)['page'], $texte)];
}
// Une réponse 303 vers cette adresse, sans rien d'autre dans le corps
function redirigeVers(array $reponse, string $adresse): bool {
	return $reponse['code'] === 303 && enTete($reponse, 'Location') === $adresse && trim(corps($reponse)) === '';
}
// La page où mène la redirection d'une réponse (Post/Redirect/Get)
function suivreRedirection(array $reponse): array {
	$suite = enTete($reponse, 'Location');
	return $suite === '' ? $reponse : requetePortail(dirname(PORTAIL).'/'.$suite);
}

// Le contenu brut de chaque courriel envoyé
function courriels(): array {
	$fichiers = glob(ETAT_BANC.'/courriel-*.eml');
	sort($fichiers);
	return array_map('file_get_contents', $fichiers);
}
function courrielsA(string $adresse): array {
	return array_values(array_filter(courriels(), fn($courriel) => preg_match('/^To: '.preg_quote($adresse, '/').'\s*$/m', $courriel) === 1));
}
function oublierCourriels(): void {
	array_map('unlink', glob(ETAT_BANC.'/courriel-*.eml'));
}

// La mémoire des alertes d'un problème : ['passagesdesuite', 'dernierenvoi'], ou null sans ligne
function alerteComite(int $iduser, string $probleme): ?array {
	$bd = connexionBD();
	$requete = $bd->prepare('SELECT passagesdesuite, dernierenvoi FROM alertecomite WHERE iduser = ? AND probleme = ?');
	$requete->bind_param('is', $iduser, $probleme);
	$requete->execute();
	return $requete->get_result()->fetch_assoc();
}
// Comme si le dernier courriel de ces problèmes était parti il y a 25 heures. L'application écrit cette date en heure
// locale : on la calcule ici, pas par NOW() de MariaDB, qui est en UTC.
function reculerDernierEnvoi(int $iduser, array $problemes): void {
	$date = date('Y-m-d H:i:s', time() - 25 * 3600);
	$bd = connexionBD();
	$requete = $bd->prepare('UPDATE alertecomite SET dernierenvoi = ? WHERE iduser = ? AND probleme = ?');
	foreach ($problemes as $probleme) {
		$requete->bind_param('sis', $date, $iduser, $probleme);
		$requete->execute();
	}
}

// Empreinte des requêtes voip.ms du scénario : méthode et paramètres de chacune (sans identifiants), dans l'ordre, par
// compte. Elle est comparée à reference/voipms-<scénario>.txt : tout changement de ce que l'application demande à
// voip.ms (méthode, paramètre, ordre, nombre de requêtes) rend le banc rouge, même si le résultat final est le même.
// Chaque « --- » sépare le journal d'un passage de celui du suivant.
$empreinteVoipms = [];
function noterEmpreinteVoipms(): void {
	global $empreinteVoipms;
	$requetes = journalFaux('voip.ms');
	if ($requetes === []) {
		return;
	}
	foreach ($requetes as $requete) {
		$params = $requete['params'];
		$compte = (string)($params['api_username'] ?? '');
		unset($params['api_username'], $params['api_password']);
		// Le texto dit l'heure et le jour du quart, qui suivent l'horloge : seul le reste compte
		if (isset($params['message'])) {
			$params['message'] = preg_replace(['/\d{1,2}:\d{2}/', '/(demain le )\d+/'], ['HH:MM', '${1}JJ'], $params['message']);
		}
		ksort($params);
		$empreinteVoipms[] = $compte.' | '.($params['method'] ?? '?').' | '.http_build_query($params);
	}
	$empreinteVoipms[] = '---';
}
function comparerEmpreinteVoipms(): void {
	global $empreinteVoipms;
	noterEmpreinteVoipms();
	if ($empreinteVoipms === []) {
		return;
	}
	comparerReference('voipms-'.basename($_SERVER['argv'][0], '.php').'.txt', implode("\n", $empreinteVoipms)."\n");
}

function finDuScenario(): never {
	global $echecs, $scenarioTermine;
	comparerEmpreinteVoipms();
	$scenarioTermine = true;
	exit($echecs === 0 ? 0 : 1);
}
