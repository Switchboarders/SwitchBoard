<?php
// Test de l'agenda illisible : TraitementComite doit distinguer « agenda illisible » (aucun fichier
// utilisable : on ne touche à rien) de « personne de garde » (agenda lisible, aucun quart en
// cours : le groupe de sonnerie se vide, comme toujours). Les vrais TraitementComite et
// ClientVoipms et GroupeSonnerie tournent contre le faux serveur d'agendas et le faux compte voip.ms. Les pages du
// portail sont incluses telles quelles. Aucune vraie BD, aucun courriel, aucun réseau au-delà de
// 127.0.0.1.
//
// Usage : démarrer d'abord les deux serveurs factices :
//   php -S 127.0.0.1:8995 stubagendaserver.php &
//   php -S 127.0.0.1:8996 stubvoipmscompte.php &
// puis : php agendaillisible_test.php (demande les dépendances de composer install, comme
// l'application)

const SERVEUR_AGENDA = 'http://127.0.0.1:8995';
const JEAN = "5145550001";
const MARIE = "5145550002";
const NOM_GROUPE = "Comite essai";
const CHEMIN_SECRET = "/home/compte/public_html/calendarcache/comite.ical";

// Un die() sort avec le code 0 : sans ce garde-fou, un test arrêté en plein milieu passerait pour réussi
$testTermine = false;
register_shutdown_function(function () {
	global $testTermine;
	if (!$testTermine) {
		echo "\nECHEC: le script s'est arrêté avant la fin du test (die() ?).\n";
		exit(1);
	}
});

// Versions minimales des classes dont TraitementComite, ClientVoipms et GroupeSonnerie ont besoin
class DatabaseManager {
	public static array $listesConservees = [];
	public static string $adresseSbchat = '';
	public function getListeConservee($iduser) {
		return self::$listesConservees[$iduser] ?? null;
	}
	public function enregistrerListeConservee($iduser, $voipmsusername, $ringgroupname, array $membres, bool $resynchronisationComplete) {
		$maintenant = date("Y-m-d H:i:s");
		self::$listesConservees[$iduser] = [
			'voipmsusername' => $voipmsusername,
			'ringgroupname' => $ringgroupname,
			'lastupdate' => $maintenant,
			'lastfullsync' => $resynchronisationComplete ? $maintenant : self::$listesConservees[$iduser]['lastfullsync'],
			'membres' => array_values($membres),
		];
	}
	public function getRenvoisUtilisesAilleurs($iduser, $voipmsusername) {
		return [];
	}
	public function getSbchatinfobyId($iduser) {
		return ['sbchatcalendarurl' => self::$adresseSbchat, 'sbchatserveraddress' => 'http://127.0.0.1:1', 'sbchatapitoken' => 'jeton'];
	}
	public function getcurrentuserid() {
		return 1;
	}
	// Cette fausse mémoire ne retient rien : chaque cas part d'un comité sans courriel récent
	public function lireAlerteComite($iduser, $probleme) {
		return ['passagesdesuite' => 0, 'dernierenvoi' => null];
	}
	public function noterEnvoiAlerteComite($iduser, $probleme, $date) {}
}
class InformationsUtilisateur {
	public string $username = "comite";
	public string $voipmsusername = "comptetest";
	public string $voipmsapipassword = "bidon";
	public string $didnumber = "5145550000";
	public string $language = "fr";
	public string $ringgroupname = NOM_GROUPE;
	public string $calendarurl = "";
	public string $calendartype = "secreturl";
	public string $calendarusername = "agenda";
	public string $calendarpassword = "secret";
	public ?int $ringtime = null;
	public ?int $pressone = null;
	public $iduser = 1;
	public $databasemanager;
}
class Courriel {
	public int $envois = 0;
	private string $corps = "";
	public function __construct($adresse = "") {}
	public function addlinetomessagebody($ligne, $afficher = false) { $this->corps .= $ligne."\n"; }
	public function getMessagebody() { return $this->corps; }
	public function sendemail($titre) { $this->envois++; return true; }
	public function sendemailtoadmin($titre, $iduser) { $this->envois++; return true; }
}
class JournalDeTache {
	public static array $lignes = [];
	public function __construct(string $logfor, int $iduser = -1) {}
	public function writelinetolog($ligne) { self::$lignes[] = $ligne; return $ligne; }
	public function getCurrentlog() { return implode("\n", self::$lignes); }
	public function incrementtab() {}
	public function decrementtab() {}
	public function trimandcloselog() {}
}
class ClientSwitchBoardChat {
	public static array $changements = [];
	public static array $messages = [];
	public function __construct($adresse) {}
	public function change_call_center_respondents($jeton, $repondants) {
		self::$changements[] = $repondants;
		return ['querystatus' => 'success', 'message' => implode(",", $repondants)];
	}
	public function send_message_to_respondents($jeton, $repondants, $message) {
		self::$messages[] = $repondants;
		return ['querystatus' => 'success', 'message' => implode(",", $repondants)];
	}
}
// Une page qui agit répond par une redirection (redirigerVers() sort du script) : pour l'essai, elle lève cette exception. Définie avant
// l'amorce, dont authhelpers.php ne définit que les fonctions absentes. La sortie de l'action est lue dans ce que la page a gardé pour la suivante.
class RedirectionPourEssai extends Exception {}
function redirigerVers(string $adresse): never {
	throw new RedirectionPourEssai($adresse);
}
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/GroupeSonnerie.php';

// Le vrai client, qui parle au faux compte, sauf pour les SMS : ils sont notés et ne partent pas
class ClientVoipmsEssai extends ClientVoipms {
	protected string $urlApi = "http://127.0.0.1:8996/rest.php";
	public array $smsEnvoyes = [];
	public function envoyerSms(string $numeroDestination, string $message) {
		$this->smsEnvoyes[] = $numeroDestination;
		return ['status' => 'success'];
	}
}
// Le vrai groupe de sonnerie, qui note ce que TraitementComite lui demande
class GroupeSonnerieEssai extends GroupeSonnerie {
	public array $listesRecues = [];
	public function mettreAJour($iduser, $participantslist, $phonenumberlist, array $reglageslist = [], bool $forcerResynchronisation = false) {
		$this->listesRecues[] = $phonenumberlist;
		return parent::mettreAJour($iduser, $participantslist, $phonenumberlist, $reglageslist, $forcerResynchronisation);
	}
}
// La seule différence avec la vraie classe : le cache est dans un dossier temporaire
class CalendarManagerEssai extends TraitementComite {
	public static string $dossierCache = "";
	public function generatecachefilename($type = "ringgroupandsms"){
		return self::$dossierCache."/comite".$type.".ical";
	}
}

require_once __DIR__.'/verifie.php';

$dossierTemporaire = sys_get_temp_dir()."/sb-agendaillisible-".getmypid();
mkdir($dossierTemporaire);
CalendarManagerEssai::$dossierCache = $dossierTemporaire;
$cache = $dossierTemporaire."/comiteringgroupandsms.ical";
$cacheSbchat = $dossierTemporaire."/comitesbchat.ical";
ini_set('error_log', $dossierTemporaire."/erreurs-php.log");

$fichierCompte = __DIR__."/stub-compte.json";
@unlink($fichierCompte);
function compte() {
	global $fichierCompte;
	return json_decode((string) @file_get_contents($fichierCompte), true);
}
function viderJournalVoipms() {
	global $fichierCompte;
	$etat = compte();
	if ($etat !== null) {
		$etat['journal'] = [];
		file_put_contents($fichierCompte, json_encode($etat));
	}
}
// Membres du groupe de sonnerie, en numéros de téléphone
function numerosDuGroupe(): array {
	$etat = compte();
	foreach ($etat['groupes'] ?? [] as $groupe) {
		if ($groupe['name'] === NOM_GROUPE) {
			return array_map(fn($id) => $etat['renvois'][$id]['phone_number'] ?? "renvoi effacé $id", $groupe['members']);
		}
	}
	return [];
}
function numerosDesRenvois(): array {
	return array_column(compte()['renvois'] ?? [], 'phone_number');
}
function journalContient(string $texte): bool {
	return str_contains(implode("\n", JournalDeTache::$lignes), $texte);
}

// Adresse d'un agenda d'un seul quart, servi par le faux serveur
function quart(string $nom, string $description, int $debut, int $fin): string {
	return SERVEUR_AGENDA.'/quart?'.http_build_query(compact('nom', 'description', 'debut', 'fin'));
}

// Objets neufs, comme à chaque comité dans un cron
function comite(string $adresse, string $sorteAgenda = "secreturl"): object {
	$infos = new InformationsUtilisateur();
	$infos->calendarurl = $adresse;
	$infos->calendartype = $sorteAgenda;
	$infos->databasemanager = new DatabaseManager();
	$courriel = new Courriel();
	$voipms = new ClientVoipmsEssai($infos);
	$groupe = new GroupeSonnerieEssai($voipms, $infos, $infos->databasemanager, fn(string $message) => print($message));
	return (object)['courriel' => $courriel, 'voipms' => $voipms, 'groupe' => $groupe, 'agenda' => new CalendarManagerEssai($infos, $courriel, $voipms, $groupe)];
}
// Lance $action sur un comité neuf : le comité reçoit 'retour', 'ecran' et 'appels' (voip.ms)
function lancer(callable $action, string $adresse, string $sorteAgenda = "secreturl"): object {
	JournalDeTache::$lignes = [];
	ClientSwitchBoardChat::$changements = [];
	ClientSwitchBoardChat::$messages = [];
	viderJournalVoipms();
	$comite = comite($adresse, $sorteAgenda);
	ob_start();
	$comite->retour = $action($comite);
	$comite->ecran = ob_get_clean();
	$comite->appels = compte()['journal'] ?? [];
	return $comite;
}
// Un passage du cron de sonnerie
function passage(string $adresse, string $sorteAgenda = "secreturl", bool $forcer = false): object {
	return lancer(fn($comite) => $comite->agenda->refreshringgroup(1, $forcer), $adresse, $sorteAgenda);
}

$maintenant = time();
$quartDeJean = quart("Jean", "514-555-0001", $maintenant - 3600, $maintenant + 3600);
$quartDeMarie = quart("Marie", "514-555-0002", $maintenant - 3600, $maintenant + 3600);

// Jean est de garde et l'agenda est en cache : le point de départ de presque tous les cas
function remettreJeanDeGarde() {
	global $quartDeJean;
	passage($quartDeJean);
}
// Le cas central : rien n'a changé chez voip.ms et la fonction a retourné normalement
function verifieRienNeChange(string $cas, object $comite) {
	verifie($comite->groupe->listesRecues === [], "$cas: mettreAJour n'est pas appelée");
	verifie($comite->appels === [], "$cas: aucun appel à voip.ms (".implode(",", $comite->appels).")");
	verifie(numerosDuGroupe() === [JEAN], "$cas: le groupe de sonnerie garde son répondant");
	verifie(numerosDesRenvois() === [JEAN], "$cas: les renvois sont intacts");
	verifie(journalContient("groupe de sonnerie inchangé"), "$cas: le journal dit que le groupe reste inchangé");
	verifie(str_contains((string)$comite->retour, "inchangé"), "$cas: la fonction retourne un message ({$comite->retour})");
	verifie(str_contains($comite->ecran, "inchangé"), "$cas: le message est à l'écran (la page « Forcer » n'affiche que l'écran)");
}

// --- Témoin : agenda lisible, un répondant de garde -----------------------------------------
$comite = passage($quartDeJean);
verifie($comite->groupe->listesRecues === [[JEAN]], "Témoin: mettreAJour reçoit Jean");
verifie(numerosDuGroupe() === [JEAN], "Témoin: le groupe de sonnerie contient Jean");
verifie(str_contains((string)@file_get_contents($cache), "SUMMARY:Jean"), "Témoin: l'agenda est en cache");
verifie(str_contains($comite->ecran, "téléchargé avec succès"), "Témoin: le succès est annoncé");

// --- Cas 1 : téléchargement raté, aucun cache (juste après une mise à jour) -------------------
remettreJeanDeGarde();
unlink($cache);
$comite = passage(SERVEUR_AGENDA.'/absent');
verifieRienNeChange("Cas 1", $comite);
verifie($comite->courriel->envois === 2, "Cas 1: le téléchargement raté reste signalé (comité et admin : {$comite->courriel->envois})");

// --- Cas 2 : « Forcer » ne passe pas outre ---------------------------------------------------
$comite = passage(SERVEUR_AGENDA.'/absent', "secreturl", true);
verifieRienNeChange("Cas 2", $comite);

// --- Cas 3 : adresse d'agenda vide ------------------------------------------------------------
remettreJeanDeGarde();
unlink($cache);
$comite = passage('');
verifieRienNeChange("Cas 3", $comite);
verifie($comite->courriel->envois === 0, "Cas 3: aucun courriel");

// --- Cas 4 : téléchargement raté avec cache : le cache sert, rien d'autre ne change ------------
remettreJeanDeGarde();
$comite = passage(SERVEUR_AGENDA.'/absent');
verifie($comite->groupe->listesRecues === [[JEAN]], "Cas 4: le cache sert");
verifie(str_contains($comite->ecran, "Chargement à partir du cache"), "Cas 4: le cache est annoncé");
verifie(str_contains((string)$comite->retour, "aucun changement"), "Cas 4: passage normal ({$comite->retour})");
verifie($comite->courriel->envois === 2, "Cas 4: le téléchargement raté est signalé au comité et à l'admin ({$comite->courriel->envois})");

// --- Cas 5 : fichier sans VEVENT avec cache -----------------------------------------------------
remettreJeanDeGarde();
$cacheAvant = file_get_contents($cache);
$comite = passage(SERVEUR_AGENDA.'/sansvevent');
verifie(file_get_contents($cache) === $cacheAvant, "Cas 5: le cache n'est pas écrasé");
verifie($comite->groupe->listesRecues === [[JEAN]], "Cas 5: le cache sert");
verifie(numerosDuGroupe() === [JEAN], "Cas 5: le groupe de sonnerie garde son répondant");
verifie($comite->courriel->envois === 0, "Cas 5: aucun courriel ({$comite->courriel->envois})");
verifie(journalContient("sans aucun événement"), "Cas 5: une ligne au journal");

// --- Cas 6 : fichier sans VEVENT sans cache : illisible, sans courriel ---------------------------
remettreJeanDeGarde();
unlink($cache);
$comite = passage(SERVEUR_AGENDA.'/sansvevent');
verifieRienNeChange("Cas 6", $comite);
verifie($comite->courriel->envois === 0, "Cas 6: aucun courriel ({$comite->courriel->envois})");
verifie(!file_exists($cache), "Cas 6: rien n'est mis en cache");

// --- Cas 7 : une copie en cache sans événement n'est pas utilisable (laissée par l'ancien code) ---
foreach (["sans VEVENT" => "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n", "vide" => ""] as $sorte => $contenu) {
	remettreJeanDeGarde();
	file_put_contents($cache, $contenu);
	verifieRienNeChange("Cas 7, cache $sorte", passage(SERVEUR_AGENDA.'/absent'));
}

// --- Cas 8 : écriture du cache ratée ---------------------------------------------------------------
// Un dossier à la place du fichier temporaire : l'écriture échoue, même pour root (banc d'essai)
remettreJeanDeGarde();
$fichierTemporaire = $cache.".".getmypid().".tmp";
mkdir($fichierTemporaire);
$comite = passage($quartDeMarie);
rmdir($fichierTemporaire);
verifie($comite->groupe->listesRecues === [[JEAN]], "Cas 8: la copie précédente sert");
verifie(str_contains((string)file_get_contents($cache), "SUMMARY:Jean"), "Cas 8: la copie précédente est intacte");
verifie(!str_contains($comite->ecran, "téléchargé avec succès"), "Cas 8: le succès n'est pas annoncé");
verifie(journalContient("cache impossible"), "Cas 8: une ligne au journal");
verifie($comite->courriel->envois === 0, "Cas 8: aucun courriel ({$comite->courriel->envois})");

// Dossier de cache absent : aucune copie, et rien à lire
remettreJeanDeGarde();
CalendarManagerEssai::$dossierCache = $dossierTemporaire."/absent";
$comite = passage($quartDeMarie);
CalendarManagerEssai::$dossierCache = $dossierTemporaire;
verifieRienNeChange("Cas 8, sans copie", $comite);
verifie(!str_contains($comite->ecran, "téléchargé avec succès"), "Cas 8, sans copie: le succès n'est pas annoncé");
verifie($comite->courriel->envois === 0, "Cas 8, sans copie: aucun courriel ({$comite->courriel->envois})");

// --- Cas 9 : personne de garde : rien ne change par rapport à avant --------------------------------
$quartsAilleurs = [
	"quart plus tard aujourd'hui" => quart("Jean", "514-555-0001", $maintenant + 6 * 3600, $maintenant + 8 * 3600),
	"seul quart hors de la fenêtre de lecture (0 événement après filtre)" => quart("Jean", "514-555-0001", $maintenant + 30 * 86400, $maintenant + 30 * 86400 + 3600),
];
foreach ($quartsAilleurs as $sorte => $adresse) {
	remettreJeanDeGarde();
	$comite = passage($adresse);
	verifie($comite->groupe->listesRecues === [[]], "Cas 9, $sorte: mettreAJour reçoit une liste vide");
	verifie(str_contains((string)$comite->retour, "missing_members"), "Cas 9, $sorte: voip.ms refuse le groupe vide ({$comite->retour})");
	verifie(numerosDesRenvois() === [], "Cas 9, $sorte: le renvoi est effacé, comme avant");
	verifie(str_contains((string)file_get_contents($cache), "BEGIN:VEVENT"), "Cas 9, $sorte: l'agenda est en cache");
}

// --- Cas 10 : CalDAV -----------------------------------------------------------------------------
remettreJeanDeGarde();
$comite = passage(SERVEUR_AGENDA.'/caldavvide', "caldav");
verifie($comite->groupe->listesRecues === [[JEAN]], "Cas 10: réponse sans événement avec cache : le cache sert");
verifie($comite->courriel->envois === 0, "Cas 10: réponse sans événement : aucun courriel ({$comite->courriel->envois})");
verifie(journalContient("Aucun événement"), "Cas 10: réponse sans événement : une ligne au journal");

remettreJeanDeGarde();
unlink($cache);
$comite = passage(SERVEUR_AGENDA.'/caldavvide', "caldav");
verifieRienNeChange("Cas 10, sans cache", $comite);
verifie($comite->courriel->envois === 0, "Cas 10, sans cache: aucun courriel ({$comite->courriel->envois})");

// Une vraie panne CalDAV reste signalée, et la page de diagnostic voit toujours l'agenda vide
$comite = passage(SERVEUR_AGENDA.'/absent', "caldav");
verifie($comite->courriel->envois === 2, "Cas 10: une vraie panne CalDAV reste signalée ({$comite->courriel->envois})");
$erreurDiagnostic = '';
try {
	comite(SERVEUR_AGENDA.'/caldavvide', "caldav")->agenda->telechargerAgenda();
} catch (Exception $e) {
	$erreurDiagnostic = $e->getMessage();
}
verifie($erreurDiagnostic !== '', "Cas 10: la page de diagnostic est prévenue ($erreurDiagnostic)");

// --- Cas 11 : SBChat -------------------------------------------------------------------------------
$rafraichirChat = fn($comite) => $comite->agenda->refreshsbchat(1);
DatabaseManager::$adresseSbchat = quart("@lita", "", $maintenant - 3600, $maintenant + 3600);
$comite = lancer($rafraichirChat, '');
verifie(ClientSwitchBoardChat::$changements === [["@lita"]], "Cas 11: témoin : les répondants changent quand l'agenda est lisible");

foreach (["téléchargement raté" => SERVEUR_AGENDA.'/absent', "adresse vide" => ''] as $sorte => $adresse) {
	@unlink($cacheSbchat);
	DatabaseManager::$adresseSbchat = $adresse;
	$comite = lancer($rafraichirChat, '');
	verifie(ClientSwitchBoardChat::$changements === [], "Cas 11, $sorte: aucun appel qui change les répondants");
	verifie(str_contains((string)$comite->retour, "illisible"), "Cas 11, $sorte: la fonction retourne un message ({$comite->retour})");
	verifie(journalContient("illisible"), "Cas 11, $sorte: une ligne au journal");
}

// --- Cas 12 : rappels SMS --------------------------------------------------------------------------
$midiDemain = strtotime('tomorrow noon');
$rappelSms = fn($comite) => $comite->agenda->sendremindersms(1, True, "daybefore");
$comite = lancer($rappelSms, quart("*Jean", "514-555-0001", $midiDemain, $midiDemain + 3600));
verifie($comite->voipms->smsEnvoyes === [JEAN], "Cas 12: témoin : le rappel part quand l'agenda est lisible");

unlink($cache);
$comite = lancer($rappelSms, SERVEUR_AGENDA.'/absent');
verifie($comite->voipms->smsEnvoyes === [], "Cas 12: aucun SMS");
verifie(str_contains((string)$comite->retour, "illisible"), "Cas 12: la fonction retourne un message ({$comite->retour})");
verifie(journalContient("illisible"), "Cas 12: une ligne au journal");

// --- Cas 13 : rappels SBChat -----------------------------------------------------------------------
$rappelChat = fn($comite) => $comite->agenda->sendremindersbchat(1, "daybefore");
@unlink($cacheSbchat);
DatabaseManager::$adresseSbchat = quart("@lita", "", $midiDemain, $midiDemain + 3600);
lancer($rappelChat, '');
verifie(ClientSwitchBoardChat::$messages === [["@lita"]], "Cas 13: témoin : le rappel part quand l'agenda est lisible");

unlink($cacheSbchat);
DatabaseManager::$adresseSbchat = SERVEUR_AGENDA.'/absent';
$comite = lancer($rappelChat, '');
verifie(ClientSwitchBoardChat::$messages === [], "Cas 13: aucun message");
verifie(str_contains((string)$comite->retour, "illisible"), "Cas 13: la fonction retourne un message ({$comite->retour})");
verifie(journalContient("illisible"), "Cas 13: une ligne au journal");

// --- Cas 14 : validation ---------------------------------------------------------------------------
$valider = fn($comite) => print(htmlDeLaValidation($comite->agenda->validerAgenda()));
$comite = lancer($valider, $quartDeJean);
verifie(str_contains($comite->ecran, "Les données sont entrées correctement"), "Cas 14: témoin : un agenda lisible est validé");

unlink($cache);
$comite = lancer($valider, SERVEUR_AGENDA.'/absent');
verifie(!str_contains($comite->ecran, "Les données sont entrées correctement"), "Cas 14: un agenda illisible n'est pas déclaré correct");
verifie(str_contains($comite->ecran, "illisible"), "Cas 14: la page le dit");

// --- Cas 15 : une erreur d'analyse lève une exception au lieu d'arrêter le script --------------------
$fonctions = [
	'validate' => $valider,
	'sendremindersms' => $rappelSms,
	'refreshringgroup' => fn($comite) => $comite->agenda->refreshringgroup(1),
	'refreshsbchat' => $rafraichirChat,
	'sendremindersbchat' => $rappelChat,
];
remettreJeanDeGarde();
DatabaseManager::$adresseSbchat = SERVEUR_AGENDA.'/dateinvalide';
foreach ($fonctions as $nom => $fonction) {
	$niveauTampon = ob_get_level();
	$exception = null;
	try {
		lancer($fonction, SERVEUR_AGENDA.'/dateinvalide');
	} catch (\Exception $e) {
		$exception = $e;
	}
	while (ob_get_level() > $niveauTampon) {
		ob_end_clean();
	}
	verifie($exception !== null, "Cas 15: $nom lève une exception (".($exception ? $exception->getMessage() : "aucune").")");
}
verifie(numerosDuGroupe() === [JEAN] && numerosDesRenvois() === [JEAN], "Cas 15: l'exception ne touche pas au groupe de sonnerie");
verifie(ClientSwitchBoardChat::$changements === [] && ClientSwitchBoardChat::$messages === [], "Cas 15: l'exception n'envoie rien à SBChat");
$comite = passage($quartDeMarie);
verifie(numerosDuGroupe() === [MARIE], "Cas 15: le script continue : le comité suivant est traité");

// --- Cas 16 : pages du portail ------------------------------------------------------------------------
class PortailEssai {
	public $calendarmanager;
	public $databasemanager;
	public function initiatecalendar() {}
}
// Les pages sont incluses depuis un objet qui a un $webpageparent, comme dans Webpage
class PageEssai {
	public $webpageparent;
	public function afficher(string $fichier) {
		include $fichier;
	}
}
// Un agenda qui plante, avec un chemin de fichier dans le message, comme la librairie ICal
class AgendaEnPanne {
	public function __call($methode, $arguments) {
		throw new Exception("The file path or URL '".CHEMIN_SECRET."' does not exist.");
	}
}
// Retourne [HTML de la page, exception qui s'en est échappée ou null]
function afficherPage(string $page, $agenda): array {
	$portail = new PortailEssai();
	$portail->calendarmanager = $agenda;
	$portail->databasemanager = new DatabaseManager();
	$pageEssai = new PageEssai();
	$pageEssai->webpageparent = $portail;
	$niveauTampon = ob_get_level();
	$echappee = null;
	$redirigee = false;
	// Ces pages n'agissent qu'en POST (le bouton) : c'est ce que le portail leur envoie
	$_SERVER['REQUEST_METHOD'] = 'POST';
	unset($_SESSION['valeurs_enregistrement']);
	ob_start();
	try {
		$pageEssai->afficher(dirname(__FILE__,2).'/src/portail/pages/utilisateur/'.$page);
	} catch (RedirectionPourEssai) {
		$redirigee = true;
	} catch (\Throwable $e) {
		$echappee = $e;
	}
	$html = "";
	while (ob_get_level() > $niveauTampon) {
		$html = ob_get_clean().$html;
	}
	// Ce que la page a dit : sa sortie directe, ou, pour une page qui agit, ce qu'elle garde pour la page qui suit la redirection
	return [$html.($redirigee ? (string)($_SESSION['valeurs_enregistrement']['sortie'] ?? '') : ''), $echappee];
}
$pages = ['refreshringgrouppage.php', 'refreshsbchat.php', 'validationpage.php', 'remindersmspage.php',
	'remindersmsjustbeforepage.php', 'remindersbchatpage.php', 'remindersbchatjustbeforepage.php'];
foreach ($pages as $page) {
	[$html, $echappee] = afficherPage($page, new AgendaEnPanne());
	verifie($echappee === null, "Cas 16, $page: l'exception ne s'échappe pas de la page");
	verifie(str_contains($html, "n'a pas pu être traité") || str_contains($html, "n&#039;a pas pu être traité"), "Cas 16, $page: un message lisible");
	verifie(!str_contains($html, "calendarcache") && !str_contains($html, "#0 ") && !str_contains($html, "Exception"), "Cas 16, $page: ni trace ni chemin de fichier");

	@unlink($cache);
	@unlink($cacheSbchat);
	DatabaseManager::$adresseSbchat = '';
	[$html, $echappee] = afficherPage($page, comite('')->agenda);
	verifie($echappee === null && str_contains($html, "illisible"), "Cas 16, $page: agenda illisible : la page le dit");
}
verifie(str_contains((string)@file_get_contents($dossierTemporaire."/erreurs-php.log"), CHEMIN_SECRET), "Cas 16: la cause reste dans le journal d'erreurs de PHP");

array_map('unlink', glob($dossierTemporaire."/*"));
rmdir($dossierTemporaire);
@unlink($fichierCompte);

$testTermine = true;
echo $echecs === 0 ? "\nTous les tests de l'agenda illisible ont réussi.\n" : "\n$echecs test(s) en échec.\n";
exit($echecs === 0 ? 0 : 1);
