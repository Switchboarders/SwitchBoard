<?php
// Test des repères de l'agenda (issue #62) : TraitementComite::lireReperes() et
// reglagesDuRepondant(). Les repères (faire1->o, nbsec->20) sont retirés de la description avant
// de chercher le numéro de téléphone (NumeroTelephone) : ils ne doivent jamais fausser le numéro, et
// une description sans repère doit donner le même numéro qu'avant leur retrait. Vérifie aussi que l'ordre des
// répondants ne dépend pas de l'ordre des événements dans l'agenda (TraitementComite::trierRepondants()).
//
// Usage : php reperes_test.php (aucun réseau, aucune BD ; demande les dépendances de
// composer install, comme l'application)

// Versions minimales des classes dont TraitementComite a besoin
class InformationsUtilisateur {
	public ?int $ringtime = null;
	public ?int $pressone = null;
}
class Courriel {}
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/GroupeSonnerie.php';

require_once __DIR__.'/verifie.php';

// Sans passer par le constructeur, qui ouvre une connexion à la vraie BD
$infos = new InformationsUtilisateur();
$calendrier = (new ReflectionClass(TraitementComite::class))->newInstanceWithoutConstructor();
$propriete = new ReflectionProperty(TraitementComite::class, 'userinformations');
$propriete->setAccessible(true);
$propriete->setValue($calendrier, $infos);

// Lit une description : repères, numéro trouvé dans le texte nettoyé, avertissements
function lire(string $description): array {
	global $calendrier;
	$lu = $calendrier->lireReperes($description);
	return [$lu['reperes'], NumeroTelephone::lireDans($lu['texte'])->numero, $lu['avertissements']];
}

// --- Descriptions sans repère : le numéro est celui de la description entière ----------------
$sansRepere = ["514-555-1234", "Jean: 514 555 1234", "(514) 555-1234", "1-514-555-1234",
	"Jean 514.555.1234 poste 22", "", "Appeler le 514-555-1234 ou le 450-555-9876"];
foreach ($sansRepere as $description) {
	[$reperes, $numero, $avertissements] = lire($description);
	verifie($numero === NumeroTelephone::lireDans($description)->numero && $reperes === [] && $avertissements === [],
		"Sans repère, même numéro que dans la description entière : « $description » -> « $numero »");
}

// --- Repères lus et retirés ----------------------------------------------------------------
[$reperes, $numero] = lire("514-555-1234 faire1->n nbsec->30");
verifie($reperes === ['faire1' => 0, 'nbsec' => 30] && $numero === "5145551234", "Repères après le numéro");

// Repères avant le numéro, sans « : » : leurs chiffres ne doivent jamais passer pour le numéro
[$reperes, $numero] = lire("Jean faire1->o nbsec->30 514-555-1234");
verifie($reperes === ['faire1' => 1, 'nbsec' => 30] && $numero === "5145551234", "Repères avant le numéro, sans « : » ($numero)");

[$reperes, $numero] = lire("Tél: 514-555-1234 NbSec -> 45 FAIRE1->Oui");
verifie($reperes === ['nbsec' => 45, 'faire1' => 1] && $numero === "5145551234", "Majuscules et espaces autour de ->");

foreach (['o', 'oui', 'y', 'yes', '1'] as $valeur) {
	verifie(lire("514-555-1234 faire1->$valeur")[0] === ['faire1' => 1], "faire1->$valeur = oui");
}
foreach (['n', 'non', 'no', '0'] as $valeur) {
	verifie(lire("514-555-1234 faire1->$valeur")[0] === ['faire1' => 0], "faire1->$valeur = non");
}

// HTML que Google peut mettre dans une description
[$reperes, $numero] = lire("514-555-1234<br>nbsec->20<br>faire1->non");
verifie($reperes === ['nbsec' => 20, 'faire1' => 0] && $numero === "5145551234", "Repères séparés par <br>");
[$reperes, $numero] = lire("514-555-1234 nbsec->20&nbsp;faire1->y");
verifie($reperes === ['nbsec' => 20, 'faire1' => 1] && $numero === "5145551234", "Repères séparés par &nbsp;");
[$reperes] = lire("514-555-1234 nbsec->20,faire1->n;");
verifie($reperes === ['nbsec' => 20, 'faire1' => 0], "Repères séparés par une virgule");

// --- Repères inconnus ou invalides : retirés quand même, avec un avertissement ----------------
[$reperes, $numero, $avertissements] = lire("Jean nbseq->20 514-555-1234");
verifie($reperes === [] && $numero === "5145551234" && count($avertissements) === 1 && str_contains($avertissements[0], "nbseq->20"),
	"Faute de frappe : avertissement, et ses chiffres ne touchent pas le numéro ($numero)");
foreach (["nbsec->0", "nbsec->61", "nbsec->90", "nbsec->2.5", "nbsec->", "faire1->peut-être", "faire1->2"] as $repere) {
	[$reperes, $numero, $avertissements] = lire("Jean $repere 514-555-1234");
	verifie($reperes === [] && $numero === "5145551234" && count($avertissements) === 1,
		"Valeur invalide « $repere » : avertissement, numéro intact ($numero)");
}
// Ce qui suit la flèche est un numéro : il reste dans le texte
[$reperes, $numero, $avertissements] = lire("Jean -> 514-555-1234");
verifie($reperes === [] && $numero === "5145551234" && count($avertissements) === 1, "« Jean -> numéro » : le numéro n'est pas avalé ($numero)");
[$reperes, $numero] = lire("Jean: nbsec->514-555-1234");
verifie($reperes === [] && $numero === "5145551234", "Numéro collé à la flèche : gardé ($numero)");
[$reperes] = lire("514-555-1234 nbsec->1 nbsec->60");
verifie($reperes === ['nbsec' => 60], "Limites 1 et 60 acceptées (le dernier repère l'emporte)");

// --- Priorité : agenda, sinon comité, sinon valeur par défaut ---------------------------------
$r = $calendrier->reglagesDuRepondant([]);
verifie($r['ringtime'] === 25 && $r['pressone'] === 1, "Sans repère ni réglage du comité : 25 s, « Faire le 1 » (".$r['description'].")");
$infos->ringtime = 40; $infos->pressone = 0;
$r = $calendrier->reglagesDuRepondant([]);
verifie($r['ringtime'] === 40 && $r['pressone'] === 0, "Réglage du comité (".$r['description'].")");
$r = $calendrier->reglagesDuRepondant(['nbsec' => 15, 'faire1' => 1]);
verifie($r['ringtime'] === 15 && $r['pressone'] === 1, "L'agenda l'emporte sur le comité (".$r['description'].")");
$r = $calendrier->reglagesDuRepondant(['faire1' => 1]);
verifie($r['ringtime'] === 40 && $r['pressone'] === 1, "Repère pour un seul réglage, le comité pour l'autre (".$r['description'].")");
$infos->ringtime = 90;
$r = $calendrier->reglagesDuRepondant([]);
verifie($r['ringtime'] === 25, "Durée du comité hors limites (90) : valeur par défaut");

// --- Ordre des répondants : le même quel que soit l'ordre des événements dans l'agenda ----------
$neuf = strtotime("2026-10-23 09:00"); $dix = strtotime("2026-10-23 10:00");
$albert = ['nom' => 'albert', 'numero' => '4185550002', 'reglages' => [], 'debut' => $neuf];
$dany = ['nom' => 'dany', 'numero' => '4185550001', 'reglages' => [], 'debut' => $neuf];
$claire = ['nom' => 'claire', 'numero' => '4185550000', 'reglages' => [], 'debut' => $dix];
$attendu = ['dany', 'albert', 'claire'];
foreach ([[$albert, $dany, $claire], [$dany, $albert, $claire], [$claire, $albert, $dany], [$claire, $dany, $albert]] as $agenda) {
	$noms = array_column(TraitementComite::trierRepondants($agenda), 'nom');
	verifie($noms === $attendu, "Ordre lu « ".implode(", ", array_column($agenda, 'nom'))." » trié en « ".implode(", ", $noms)." » (début du quart, puis numéro)");
}
verifie(TraitementComite::trierRepondants([]) === [], "Personne de garde : liste vide");

echo $echecs === 0 ? "\nTous les cas des repères réussissent.\n" : "\n$echecs cas en échec.\n";
exit($echecs === 0 ? 0 : 1);
