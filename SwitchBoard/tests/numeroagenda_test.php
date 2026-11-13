<?php
// Le numéro de téléphone lu dans l'agenda (tâche 6.1). Trois chemins le lisent : la page de validation de l'agenda
// (TraitementComite::validerAgenda()), le groupe de sonnerie (refreshringgroup()) et les rappels SMS (sendremindersms()).
// Depuis la phase 2, les trois suivent une seule règle, NumeroTelephone, et donnent le même résultat pour chaque
// description. Chaque comportement changé par rapport à la phase 1 cite la décision des mainteneurs du 2026-11-09 qui
// l'a voulu (lettres A à J) et ce que le code faisait avant.
//
// Chaque cas passe par la vraie méthode, avec un fichier ICS écrit ici (le téléchargement et le cache ont leurs propres
// tests) et un faux ClientVoipms et un faux GroupeSonnerie qui notent ce qu'on lui demande, sans réseau.
//
// Usage : php numeroagenda_test.php [--tableau] (aucun réseau, aucune BD ; demande les dépendances de composer install).
// --tableau affiche aussi le résultat de chaque chemin pour chaque cas.

// Un die() sort avec le code 0 : sans ce garde-fou, un test arrêté en plein milieu passerait pour réussi
$testTermine = false;
register_shutdown_function(function () {
	global $testTermine;
	if (!$testTermine) {
		echo "\nECHEC: le script s'est arrêté avant la fin du test (die() ?).\n";
		exit(1);
	}
});

// Versions minimales des classes dont TraitementComite a besoin
class DatabaseManager {
	public function lireAlerteComite($iduser, $probleme) {
		return ['passagesdesuite' => 0, 'dernierenvoi' => null];
	}
	public function noterEnvoiAlerteComite($iduser, $probleme, $date) {}
}
class InformationsUtilisateur {
	public string $username = "comite";
	public ?int $ringtime = null;
	public ?int $pressone = null;
	public $iduser = 1;
	public $databasemanager;
}
class Courriel {
	public int $envois = 0;
	public string $corps = "";
	public function addlinetomessagebody($ligne, $afficher = false) { $this->corps .= $ligne."\n"; }
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
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/GroupeSonnerie.php';

class ClientVoipmsEssai extends ClientVoipms {
	public array $smsEnvoyes = [];
	public array $textesEnvoyes = [];
	public function envoyerSms(string $numeroDestination, string $message) {
		$this->smsEnvoyes[] = $numeroDestination;
		$this->textesEnvoyes[] = $message;
		return ['status' => 'success'];
	}
}
class GroupeSonnerieEssai extends GroupeSonnerie {
	public array $listesRecues = [];
	public function mettreAJour($iduser, $participantslist, $phonenumberlist, array $reglageslist = [], bool $forcerResynchronisation = false) {
		$this->listesRecues[] = $phonenumberlist;
		return "success";
	}
	public function modePassage(): string { return "essai"; }
	public function renvoisManquants(): array { return []; }
}
// La seule différence avec la vraie classe : l'agenda est un fichier écrit par le test
class CalendarManagerEssai extends TraitementComite {
	public static string $fichier = "";
	public function cachecalendarfile($type = "ringgroupandsms"){
		return self::$fichier;
	}
}

require_once __DIR__.'/verifie.php';

CalendarManagerEssai::$fichier = sys_get_temp_dir()."/sb-numeroagenda-".getmypid().".ics";

// Texte d'une propriété ICS, échappé comme le font Google et Outlook
function texteIcs(string $texte): string {
	return str_replace(["\\", ",", ";", "\n"], ["\\\\", "\\,", "\\;", "\\n"], $texte);
}
// Un événement : ['titre' => ?string, 'description' => ?string, 'debut' => timestamp, 'fin' => timestamp] ; null = propriété absente
function evenementIcs(array $evenement, int $indice): string {
	$date = fn(int $moment) => gmdate('Ymd\THis\Z', $moment);
	$ics = "BEGIN:VEVENT\r\nUID:quart$indice@essai\r\nDTSTAMP:{$date(time())}\r\n";
	if ($evenement['titre'] !== null) $ics .= "SUMMARY:".texteIcs($evenement['titre'])."\r\n";
	if ($evenement['description'] !== null) $ics .= "DESCRIPTION:".texteIcs($evenement['description'])."\r\n";
	return $ics."DTSTART:{$date($evenement['debut'])}\r\nDTEND:{$date($evenement['fin'])}\r\nEND:VEVENT\r\n";
}
function ecrireAgenda(array $evenements, string $entete = ""): void {
	$ics = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Essai//FR\r\n".$entete;
	foreach ($evenements as $indice => $evenement) {
		$ics .= is_string($evenement) ? $evenement : evenementIcs($evenement, $indice);
	}
	file_put_contents(CalendarManagerEssai::$fichier, $ics."END:VCALENDAR\r\n");
}

// Lance un chemin sur un comité neuf. Le comité reçoit l'écran (en texte et en HTML), le journal et les avertissements de PHP.
function lancer(callable $action): object {
	JournalDeTache::$lignes = [];
	$infos = new InformationsUtilisateur();
	$infos->databasemanager = new DatabaseManager();
	$courriel = new Courriel();
	$voipms = new ClientVoipmsEssai($infos);
	$groupe = new GroupeSonnerieEssai($voipms, $infos, $infos->databasemanager, fn(string $message) => print($message));
	$comite = (object)['courriel' => $courriel, 'voipms' => $voipms, 'groupe' => $groupe, 'agenda' => new CalendarManagerEssai($infos, $courriel, $voipms, $groupe), 'avertissements' => []];
	set_error_handler(function ($niveau, $message) use ($comite) {
		$comite->avertissements[] = $message;
		return true;
	});
	ob_start();
	$action($comite);
	$comite->html = ob_get_clean();
	restore_error_handler();
	$comite->ecran = html_entity_decode(strip_tags(str_replace("<br>", "\n", $comite->html)), ENT_QUOTES);
	$comite->journal = implode("\n", JournalDeTache::$lignes);
	return $comite;
}
$valider = fn($comite) => print(htmlDeLaValidation($comite->agenda->validerAgenda()));
$rafraichir = fn($comite) => $comite->agenda->refreshringgroup(1);
$rappelVeille = fn($comite) => $comite->agenda->sendremindersms(1, True, "daybefore");

// Le genre d'un refus, d'après le message (décision G : un seul message, le même partout)
function genreDuRefus(string $texte): ?string {
	if (str_contains($texte, "Numéro absent pour")) return "absent";
	if (str_contains($texte, "Numéro invalide pour")) return "invalide";
	return null;
}

$maintenant = time();
$enCours = ['debut' => $maintenant - 3600, 'fin' => $maintenant + 3600];
$midiDemain = strtotime('tomorrow noon');
$demain = ['debut' => $midiDemain, 'fin' => $midiDemain + 3600];
$heures = fn(array $quart) => "(événement du ".date('d-m-Y H:i', $quart['debut'])." au ".date('d-m-Y H:i', $quart['fin']).")";

// Page de validation : « accepte », ou « refuse:<genre> »
function cheminValidation(?string $description): string {
	global $enCours, $valider;
	ecrireAgenda([['titre' => "Jean", 'description' => $description] + $enCours]);
	$comite = lancer($valider);
	if (str_contains($comite->ecran, "Les données sont entrées correctement")) return "accepte";
	return "refuse:".(genreDuRefus($comite->ecran) ?? "?");
}
// Groupe de sonnerie, quart en cours : « accepte:<numéros envoyés à voip.ms> », ou « refuse:<genre> »
function cheminGroupe(?string $description): string {
	global $enCours, $rafraichir;
	ecrireAgenda([['titre' => "Jean", 'description' => $description] + $enCours]);
	$comite = lancer($rafraichir);
	$numeros = $comite->groupe->listesRecues[0] ?? null;
	if ($numeros !== null && $numeros !== []) return "accepte:".implode(",", $numeros);
	return "refuse:".(genreDuRefus($comite->ecran) ?? "?");
}
// Rappel SMS la veille, quart de demain marqué d'un * : « accepte:<numéros textés> », « refuse:<genre> », ou « ignore »
// (aucun SMS et aucun message)
function cheminSms(?string $description): string {
	global $demain, $rappelVeille;
	ecrireAgenda([['titre' => "*Jean", 'description' => $description] + $demain]);
	$comite = lancer($rappelVeille);
	if ($comite->voipms->smsEnvoyes !== []) return "accepte:".implode(",", $comite->voipms->smsEnvoyes);
	$genre = genreDuRefus($comite->ecran);
	return $genre === null ? "ignore" : "refuse:".$genre;
}

// --- Le numéro lu dans la description : la même règle pour les trois chemins -----------------------------------------
// [description, résultat attendu, décision qui l'a changé et résultat d'avant (validation / groupe / SMS), ou "" s'il
// n'a pas changé à part le message (décision G)]. Titre : « Jean ». La validation ne montre pas le numéro : pour elle,
// « accepte:… » veut dire « accepte ».
$cas = [
	["514-555-1234",                              "accepte:5145551234", ""],
	["Jean: 514-555-1234",                        "accepte:5145551234", ""],
	["Jean : 514 555 1234",                       "accepte:5145551234", ""],
	["514 555 1234",                              "accepte:5145551234", ""],
	["514.555.1234",                              "accepte:5145551234", ""],
	["(514) 555-1234",                            "accepte:5145551234", ""],
	["Jean 514-555-1234",                         "accepte:5145551234", ""],
	// Mise en forme libre : parenthèses seules ou absentes, plusieurs séparateurs (correction du coordinateur, données réelles)
	["514) 555-1234",                             "accepte:5145551234", "correction du coordinateur, données réelles (avant : accepté ; refusé par la première règle de la phase 2)"],
	["Jean 999) 999-9999",                        "accepte:9999999999", "correction du coordinateur, données réelles (avant : accepté ; refusé par la première règle de la phase 2)"],
	["(514 555-1234",                             "accepte:5145551234", ""],
	["(514)555-1234",                             "accepte:5145551234", ""],
	["+1 (514) 555-1234",                         "accepte:5145551234", "A (avant : SMS refusé, longueur)"],
	["514 - 555 - 1234",                          "accepte:5145551234", ""],
	["514 / 555-1234",                            "accepte:5145551234", ""],
	["514 555 - 1234",                            "accepte:5145551234", ""],
	["514.555 1234",                              "accepte:5145551234", ""],
	// Le 1 initial
	["1-514-555-1234",                            "accepte:5145551234", "A (avant : SMS refusé, longueur)"],
	["1 514 555 1234",                            "accepte:5145551234", "A (avant : SMS refusé, longueur)"],
	["+1 514 555 1234",                           "accepte:5145551234", "A (avant : SMS refusé, longueur)"],
	["Tél: 1-514-555-1234",                       "accepte:5145551234", ""],
	["15145551234",                               "accepte:5145551234", "C (avant : refusé partout, longueur)"],
	["Jean: 15145551234",                         "accepte:5145551234", "C (avant : refusé partout, longueur)"],
	// Sans « : », le premier numéro bien formé, où qu'il soit
	["Jean 514.555.1234",                         "accepte:5145551234", "F (avant : refusé, non numérique ; SMS ignoré)"],
	["Dr. Jean 514-555-1234",                     "accepte:5145551234", "F (avant : refusé partout, longueur)"],
	["M. Jean 514-555-123",                       "refuse:invalide",    "E (avant : accepté partout comme « .514555123 »)"],
	// Deux numéros : le premier
	["Jean: 514-555-1234 ou 450-555-9876",        "accepte:5145551234", ""],
	["514-555-1234 / 450-555-9876",               "accepte:5145551234", ""],
	["Jean 514-555-1234 ou 450-555-9876",         "accepte:5145551234", "F (avant : refusé, longueur ; SMS ignoré)"],
	// Aucun numéro
	["",                                          "refuse:absent",      ""],
	[null,                                        "refuse:absent",      ""],
	["Jean sera là",                              "refuse:absent",      ""],
	["Jean: à confirmer",                         "refuse:absent",      ""],
	// D'autres chiffres dans le texte : heure, adresse, poste
	["Jean, 9h à 17h, 514-555-1234",              "accepte:5145551234", "F (avant : refusé, longueur ; SMS ignoré)"],
	["Rendez-vous à 10:30, tél 514-555-1234",     "accepte:5145551234", ""],
	["123 rue Principale, 514-555-1234",          "accepte:5145551234", "F (avant : refusé partout, longueur)"],
	["Adresse: 123 rue Principale, 514-555-1234", "accepte:5145551234", ""],
	["Jean 514-555-1234 poste 22",                "accepte:5145551234", "F (avant : refusé, longueur ; SMS ignoré)"],
	// 9 ou 11 chiffres
	["514-555-123",                               "refuse:invalide",    ""],
	["Jean: 514-555-123",                         "refuse:invalide",    "G (avant : « n'est pas inscrit », comme sans numéro)"],
	["25145551234",                               "refuse:invalide",    "D (avant : accepté partout comme 2514555123)"],
	// HTML de Google (&nbsp; devient une espace dans la librairie ICal, avant toute lecture)
	["514-555-1234<br>Jean",                      "accepte:5145551234", ""],
	["Jean<br>514-555-1234",                      "accepte:5145551234", ""],
	["Tél&nbsp;: 514-555-1234",                   "accepte:5145551234", ""],
	["<p>Jean&nbsp;514-555-1234</p>",             "accepte:5145551234", ""],
	['<a href="tel:+15145551234">514-555-1234</a>', "accepte:5145551234", "C (avant : refusé partout, longueur)"],
	// Espace insécable d'Outlook
	["514\u{00A0}555\u{00A0}1234",                "accepte:5145551234", ""],
	// Repères
	["514-555-1234 faire1->n nbsec->30",          "accepte:5145551234", ""],
	["Jean faire1->o nbsec->30 514-555-1234",     "accepte:5145551234", ""],
];
$tableau = in_array('--tableau', $argv, true);
foreach ($cas as [$description, $attendu, $decision]) {
	$validation = cheminValidation($description);
	$groupe = cheminGroupe($description);
	$sms = cheminSms($description);
	$nom = $description === null ? "(description absente)" : "« $description »";
	$marque = $decision === "" ? "" : " [décision $decision]";
	$validationAttendue = str_starts_with($attendu, "accepte:") ? "accepte" : $attendu;
	verifie($validation === $validationAttendue, "$nom, validation : $validation (attendu : $validationAttendue)$marque");
	verifie($groupe === $attendu, "$nom, groupe de sonnerie : $groupe (attendu : $attendu)$marque");
	verifie($sms === $attendu, "$nom, rappel SMS : $sms (attendu : $attendu)$marque");
	if ($tableau) {
		printf("TABLEAU | %-45s | %-18s | %-22s | %-22s |%s\n", $nom, $validation, $groupe, $sms, $marque);
	}
}

// --- Décision G : un seul message de refus, le même partout ----------------------------------------------------------
// Il cite le titre et ce qui a été lu comme numéro, jamais la description entière (repères, HTML), avec l'heure de l'événement.
$descriptionInvalide = "<b>514-555-123</b> nbsec->30";
$messageInvalide = "Numéro invalide pour « Jean » : « 514-555-123 » ".$heures($enCours).". Il faut 10 chiffres, avec ou sans 1 devant.";
ecrireAgenda([['titre' => "Jean", 'description' => $descriptionInvalide] + $enCours]);
$validation = lancer($valider);
verifie(str_contains($validation->ecran, $messageInvalide), "G, validation : le message cite le titre et ce qui a été lu (".trim($validation->ecran).")");
$groupe = lancer($rafraichir);
verifie(str_contains($groupe->journal, $messageInvalide) && str_contains($groupe->ecran, $messageInvalide), "G, groupe de sonnerie : le même message, au journal et à l'écran");
verifie($groupe->courriel->envois === 1 && str_contains($groupe->courriel->corps, $messageInvalide), "G, groupe de sonnerie : le même message dans le courriel au comité");
foreach (['validation' => $validation->ecran, 'groupe de sonnerie' => $groupe->journal.$groupe->courriel->corps] as $chemin => $texte) {
	verifie(!str_contains($texte, "nbsec->30") && !str_contains($texte, "<b>"), "G, $chemin : ni les repères ni le HTML de la description");
}
ecrireAgenda([['titre' => "*Jean", 'description' => $descriptionInvalide] + $demain]);
$sms = lancer($rappelVeille);
$messageInvalideDemain = "Numéro invalide pour « *Jean » : « 514-555-123 » ".$heures($demain).". Il faut 10 chiffres, avec ou sans 1 devant.";
verifie(str_contains($sms->ecran, $messageInvalideDemain), "B, rappel SMS : le même message que le groupe de sonnerie (".trim($sms->ecran).")");
verifie(str_contains($sms->journal, $messageInvalideDemain), "I, rappel SMS : le refus va au journal du rappel (avant : à l'écran seulement)");
verifie($sms->courriel->envois === 0 && $sms->voipms->smsEnvoyes === [], "I, rappel SMS : jamais de courriel, et aucun SMS");

$messageAbsent = "Numéro absent pour « Jean » ".$heures($enCours).".";
ecrireAgenda([['titre' => "Jean", 'description' => "à confirmer"] + $enCours]);
$validation = lancer($valider);
$groupe = lancer($rafraichir);
verifie(str_contains($validation->ecran, $messageAbsent) && str_contains($groupe->journal, $messageAbsent), "G, aucun numéro : « Numéro absent », avec l'heure de l'événement ($messageAbsent)");

// --- Décision H : tout ce qui vient de l'agenda est échappé dans la page de validation ---------------------------------
ecrireAgenda([['titre' => "<b>Jean</b>", 'description' => "<i>514-555-123</i>"] + $enCours, ['titre' => "<b>Lise</b>", 'description' => "aucun"] + $enCours,
	['titre' => "<b>Paul</b>", 'description' => "514-555-1234 nbseq-><u>20</u> nbsec->30"] + $enCours]);
$html = lancer($valider)->html;
verifie(!str_contains($html, "<b>") && !str_contains($html, "<i>") && !str_contains($html, "<u>"), "H, validation : aucune balise de l'agenda n'arrive dans la page (avant : titre et description d'un numéro invalide tels quels)");
verifie(str_contains($html, "&lt;b&gt;Jean&lt;/b&gt;") && str_contains($html, "&lt;b&gt;Lise&lt;/b&gt;") && str_contains($html, "&lt;b&gt;Paul&lt;/b&gt;"),
	"H, validation : les titres sont échappés (numéro invalide, numéro absent, repères)");

// --- Défaut 7 : titre absent, une chaîne vide sans avertissement de PHP ------------------------------------------------
ecrireAgenda([['titre' => null, 'description' => "514-555-1234"] + $enCours]);
$validation = lancer($valider);
$groupe = lancer($rafraichir);
verifie(str_contains($validation->ecran, "Les données sont entrées correctement"), "titre absent, numéro valide : la validation accepte");
verifie($groupe->groupe->listesRecues === [["5145551234"]], "titre absent, numéro valide : le répondant est dans le groupe de sonnerie");
ecrireAgenda([['titre' => null, 'description' => "514-555-1234"] + $demain]);
$sms = lancer($rappelVeille);
verifie($sms->voipms->smsEnvoyes === [], "titre absent : aucun SMS (pas d'astérisque)");
verifie($groupe->avertissements === [] && $sms->avertissements === [] && $validation->avertissements === [],
	"titre absent : aucun avertissement de PHP (avant : stripos() et strpos() recevaient null) (".implode(" ; ", array_merge($groupe->avertissements, $sms->avertissements)).")");
ecrireAgenda([['titre' => null, 'description' => ""] + $enCours]);
$groupe = lancer($rafraichir);
verifie(str_contains($groupe->journal, "Numéro absent pour «  » ".$heures($enCours)."."), "titre absent, aucun numéro : le message donne l'heure de l'événement pour le retrouver");

// --- Quels événements chaque chemin regarde (inchangé) ---------------------------------------------------------------
// Validation : tout l'agenda, sans dates ni répétitions dépliées. Groupe de sonnerie : 2 jours avant, 1 après, et
// seulement les quarts en cours entrent dans le groupe, mais un numéro invalide est signalé pour toute la fenêtre.
// Rappel SMS : 1 jour avant, 3 après, et seulement les quarts de la période du rappel.
$ilYaDixJours = ['debut' => $maintenant - 10 * 86400, 'fin' => $maintenant - 10 * 86400 + 3600];
ecrireAgenda([['titre' => "Paul", 'description' => "514-555-123"] + $ilYaDixJours]);
$validation = lancer($valider);
$groupe = lancer($rafraichir);
verifie(genreDuRefus($validation->ecran) === "invalide", "quart d'il y a 10 jours, numéro invalide : la validation le signale");
verifie(genreDuRefus($groupe->journal) === null && $groupe->courriel->envois === 0, "quart d'il y a 10 jours, numéro invalide : le groupe de sonnerie ne le voit pas");

$hier = ['debut' => $maintenant - 86400, 'fin' => $maintenant - 86400 + 3600];
ecrireAgenda([['titre' => "Paul", 'description' => "514-555-123"] + $hier, ['titre' => "Jean", 'description' => "514-555-1234"] + $enCours]);
$groupe = lancer($rafraichir);
verifie($groupe->groupe->listesRecues === [["5145551234"]] && genreDuRefus($groupe->journal) === "invalide" && $groupe->courriel->envois === 1,
	"quart fini hier, numéro invalide : le groupe de sonnerie le signale (journal et courriel), même s'il n'est plus de garde");

// Un long quart commencé il y a 3 jours, encore en cours : hors de la fenêtre du groupe de sonnerie (gardé : les
// mainteneurs jugent que ces quarts n'arrivent pas en vrai)
ecrireAgenda([['titre' => "Jean", 'description' => "514-555-1234", 'debut' => $maintenant - 3 * 86400, 'fin' => $maintenant + 3600]]);
$validation = lancer($valider);
$groupe = lancer($rafraichir);
verifie(str_contains($validation->ecran, "Les données sont entrées correctement"), "quart en cours commencé il y a 3 jours : la validation l'accepte");
verifie($groupe->groupe->listesRecues === [[]], "quart en cours commencé il y a 3 jours : absent du groupe de sonnerie (fenêtre de 2 jours avant)");

// --- Décision J : les heures sont justes quel que soit le fuseau que l'agenda déclare ----------------------------------
// Un quart « Maintenant » (en cours) et un quart « PlusTard » (dans 1 h 30, pour 3 heures), écrits à l'heure locale du
// fuseau de l'agenda, comme Google ou Outlook. Avant, un agenda de Los Angeles était lu 3 heures trop tôt : le groupe
// prenait « PlusTard ».
$heureLocale = fn(int $moment, string $fuseau) => (new DateTime("@$moment"))->setTimezone(new DateTimeZone($fuseau))->format('Ymd\THis');
$quartLocal = fn(string $nom, string $numero, int $debut, int $fin, string $fuseau) => "BEGIN:VEVENT\r\nUID:$nom@essai\r\nSUMMARY:$nom\r\nDESCRIPTION:$numero\r\n"
	."DTSTART;TZID=$fuseau:{$heureLocale($debut, $fuseau)}\r\nDTEND;TZID=$fuseau:{$heureLocale($fin, $fuseau)}\r\nEND:VEVENT\r\n";
$deuxQuarts = fn(string $fuseau) => [
	$quartLocal("Maintenant", "509-555-0001", $maintenant - 3600, $maintenant + 3600, $fuseau),
	$quartLocal("PlusTard", "509-555-0002", $maintenant + 5400, $maintenant + 16200, $fuseau),
];
// Le journal affiche l'heure du Québec, comme avant pour les agendas de l'Est
$debutAuQuebec = "Début du shift : ".date('d-m-Y H:i', $maintenant - 3600);
foreach (['America/Los_Angeles', 'America/Toronto', 'America/Detroit', 'America/New_York', 'UTC'] as $fuseau) {
	$decision = in_array($fuseau, ['America/Los_Angeles', 'UTC'], true) ? " [décision J : avant, le quart de l'heure locale de l'agenda relue comme une heure du Québec]" : " (inchangé)";
	foreach (["X-WR-TIMEZONE" => "X-WR-TIMEZONE:$fuseau\r\n", "VTIMEZONE" => "BEGIN:VTIMEZONE\r\nTZID:$fuseau\r\nEND:VTIMEZONE\r\n"] as $sorte => $entete) {
		ecrireAgenda($deuxQuarts($fuseau), $entete);
		$groupe = lancer($rafraichir);
		verifie($groupe->groupe->listesRecues === [["5095550001"]] && str_contains($groupe->journal, $debutAuQuebec),
			"agenda en $fuseau ($sorte) : le groupe de sonnerie prend le quart en cours, et le journal donne l'heure du Québec (".json_encode($groupe->groupe->listesRecues).")$decision");
	}
}
// Google écrit souvent les heures en UTC (Z), avec le fuseau de l'agenda en X-WR-TIMEZONE
ecrireAgenda([['titre' => "Maintenant", 'description' => "509-555-0001"] + $enCours, ['titre' => "PlusTard", 'description' => "509-555-0002", 'debut' => $maintenant + 5400, 'fin' => $maintenant + 16200]],
	"X-WR-TIMEZONE:America/Los_Angeles\r\n");
$groupe = lancer($rafraichir);
verifie($groupe->groupe->listesRecues === [["5095550001"]], "agenda de Los Angeles, heures en UTC : le quart en cours [décision J]");

// Le texte du SMS donne l'heure du Québec : un quart de demain midi au Québec, écrit 9 h à l'heure de Los Angeles
ecrireAgenda([$quartLocal("*Jean", "514-555-1234", $midiDemain, $midiDemain + 3600, 'America/Los_Angeles')], "X-WR-TIMEZONE:America/Los_Angeles\r\n");
$sms = lancer($rappelVeille);
$plage = date('H:i', $midiDemain)." à ".date('H:i', $midiDemain + 3600);
verifie($sms->voipms->smsEnvoyes === ["5145551234"] && str_contains($sms->voipms->textesEnvoyes[0] ?? '', $plage),
	"agenda de Los Angeles : le SMS dit « $plage », l'heure du Québec (".($sms->voipms->textesEnvoyes[0] ?? '').") [décision J]");
ecrireAgenda([$quartLocal("*Jean", "514-555-1234", $midiDemain, $midiDemain + 3600, 'America/Toronto')], "X-WR-TIMEZONE:America/Toronto\r\n");
$sms = lancer($rappelVeille);
verifie(str_contains($sms->voipms->textesEnvoyes[0] ?? '', $plage), "agenda de Toronto : le SMS dit « $plage », comme avant");

@unlink(CalendarManagerEssai::$fichier);
$testTermine = true;
echo $echecs === 0 ? "\nTous les cas du numéro lu dans l'agenda réussissent.\n" : "\n$echecs cas en échec.\n";
exit($echecs === 0 ? 0 : 1);
