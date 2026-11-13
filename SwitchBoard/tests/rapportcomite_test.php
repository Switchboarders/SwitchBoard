<?php
// Test des types de compte (DatabaseManager::typeDeCompte()) et du rapport de coûts par DID
// envoyé à un comité local membre : RapportCoutsDid ne doit y mettre que le DID du comité,
// jamais les autres DID ni les totaux du compte, et le dire quand le DID du comité n'est pas dans
// le compte. La version complète (compte principal, comité local autonome) garde tous les DID.
//
// Usage : php rapportcomite_test.php (aucun réseau, aucune BD)

// Version minimale d'Courriel : garde seulement le corps du message
class Courriel {
	private string $messagebody = "";
	public function addlinetomessagebody(string $text, bool $echotext = false) {
		$this->messagebody .= $text."\n";
	}
	public function getMessagebody() {
		return $this->messagebody;
	}
}
class JournalDeTache {}
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/rapports/CorpsDuRapport.php';

require_once __DIR__.'/verifie.php';

function ligneDid(string $description, float $total): array {
	return ['description' => $description, 'currentMonthlyRate' => 1.10, 'calls' => 3,
		'answeredCalls' => 2, 'seconds' => 120, 'callCost' => 0.30, 'rentalFees' => 1.10,
		'sharedCostShare' => 0.02, 'totalCost' => $total];
}

// --- Types de compte (règle B, 2026-11-02) ---------------------------------------------------
verifie(DatabaseManager::typeDeCompte(true, 0) === DatabaseManager::COMPTE_PRINCIPAL, "case cochée, seul sur le compte voip.ms : compte principal");
verifie(DatabaseManager::typeDeCompte(true, 3) === DatabaseManager::COMPTE_PRINCIPAL, "case cochée, avec des comités : compte principal");
verifie(DatabaseManager::typeDeCompte(false, 0) === DatabaseManager::COMITE_AUTONOME, "seul sur son compte voip.ms : comité local autonome");
verifie(DatabaseManager::typeDeCompte(false, 1) === DatabaseManager::COMITE_MEMBRE, "un autre user (ex. son compte principal) sur le même compte voip.ms : comité local membre");
verifie(DatabaseManager::typeDeCompte(false, 3) === DatabaseManager::COMITE_MEMBRE, "plusieurs autres users sur le même compte voip.ms : comité local membre");

// Rapport d'un compte voip.ms payé par un compte principal, avec deux comités membres (mêmes
// clés que CoutsDid::buildReport())
$rapport = [
	'perDid' => [
		'5145550147' => ligneDid('Levis, QC', 1.42),
		'5145550148' => ligneDid('Baie-Comeau, QC', 1.43),
	],
	'shared' => ['nonAttributedCdrCost' => 0.04, 'otherAccountFees' => [], 'otherAccountFeesTotal' => 0.0,
		'sharedTotal' => 0.04, 'perDidShare' => 0.02, 'didCount' => 2],
	'grandTotal' => 2.85,
];

$manager = new RapportCoutsDid();
function corps(string $methode, array $arguments): string {
	global $manager;
	$courriel = new Courriel();
	$appel = new ReflectionMethod(RapportCoutsDid::class, $methode);
	$appel->setAccessible(true);
	$appel->invokeArgs($manager, array_merge([new CorpsDuRapport($courriel, null)], $arguments));
	return $courriel->getMessagebody();
}

// --- Version du comité local membre --------------------------------------------------------
$texte = corps('appendDidDuComiteToEmailBody', [$rapport, '5145550147']);
verifie(str_contains($texte, '5145550147'), "le DID du comité est dans son rapport");
verifie(!str_contains($texte, '5145550148') && !str_contains($texte, 'Baie-Comeau'), "aucun autre DID dans le rapport du comité");
verifie(!str_contains($texte, 'Coût total du compte'), "pas de total du compte dans le rapport du comité");
verifie(!str_contains($texte, 'Détail des frais non attribuables'), "pas de détail des frais partagés dans le rapport du comité");
verifie(str_contains($texte, 'Part des frais non attribuables à un DID en particulier : 0.02'), "la part du comité dans les frais partagés est dans son rapport");

// DID du comité absent du compte (mal saisi ou vide) : on le dit, sans rien montrer du compte
foreach (['5145551234', ''] as $didAbsent) {
	$texte = corps('appendDidDuComiteToEmailBody', [$rapport, $didAbsent]);
	verifie(str_contains($texte, 'introuvable') && !str_contains($texte, '5145550147') && !str_contains($texte, '5145550148'),
		"DID « $didAbsent » absent du compte : message, aucun DID du compte");
}

// --- Version complète (compte principal ou comité local autonome) --------------------------
$texte = corps('appendReportToEmailBody', [$rapport]);
verifie(str_contains($texte, '5145550147') && str_contains($texte, '5145550148'), "la version complète contient tous les DID");
verifie(str_contains($texte, 'Coût total du compte pour la période : 2.85'), "la version complète contient le total du compte");

echo $echecs === 0 ? "\nTous les tests passent.\n" : "\n$echecs test(s) en échec.\n";
exit($echecs === 0 ? 0 : 1);
