#!/usr/bin/env php
<?php
// Les rapports mensuels : chaque courriel est comparé à sa référence, avec des dates fixes. Le refactoring
// de l'étape 8 doit les laisser identiques.
require_once '/banc/commun/banc.php';

const PERIODE = '--start=2026-10-01 --end=2026-10-31';

// Lance le script comme la ligne de cron, avec des dates fixes, et retourne ['destinataire' => courriel]
function courrielsDuScript(string $script): array {
	foreach (glob(ETAT_BANC.'/courriel-*.eml') as $ancien) {
		unlink($ancien);
	}
	$commande = '/usr/local/bin/php -q '.APPLI."/src/$script ".PERIODE;
	exec('su -s /bin/sh www-data -c '.escapeshellarg($commande).' 2>&1', $sortie, $code);
	verifie($code === 0, "$script : sort sans erreur");
	verifie(!preg_match('/Fatal error|Uncaught/', implode("\n", $sortie)), "$script : aucune erreur fatale");
	$parDestinataire = [];
	foreach (courriels() as $courriel) {
		preg_match('/^To: (\S+)/m', $courriel, $trouve);
		$parDestinataire[$trouve[1] ?? '?'] = $courriel;
	}
	return $parDestinataire;
}

repartirAZero();

// --- Statistiques des appels ---
$statistiques = courrielsDuScript('genstatistics.php');
// Le compte principal n'a pas de statistiques d'appels : il n'a que le rapport de coûts
verifie(array_keys($statistiques) === ['autonome@banc.test', 'membre1@banc.test', 'membre2@banc.test'], "statistiques : un courriel par comité abonné (".implode(', ', array_keys($statistiques)).")");
foreach (['autonome', 'membre1', 'membre2'] as $comite) {
	comparerReference("statistiques-$comite.eml", $statistiques["$comite@banc.test"] ?? '');
}
verifie(str_contains($statistiques['autonome@banc.test'] ?? '', '25.5000'), "statistiques : le comité autonome voit le solde de son compte");
verifie(!str_contains($statistiques['membre1@banc.test'] ?? 'x', '142.2500'), "statistiques : le comité membre ne voit pas le solde du compte principal");

// --- Coûts par DID ---
$couts = courrielsDuScript('gendidcostreport.php');
foreach (['autonome', 'region', 'membre1', 'membre2'] as $comite) {
	comparerReference("couts-$comite.eml", $couts["$comite@banc.test"] ?? '');
}
verifie(str_contains($couts['region@banc.test'] ?? '', '5145550102') && str_contains($couts['region@banc.test'] ?? '', '5145550103') && str_contains($couts['region@banc.test'] ?? '', '142.2500'),
	"coûts : le compte principal voit tous les DID et le solde");
verifie(str_contains($couts['membre1@banc.test'] ?? '', '5145550102'), "coûts : le comité membre voit son DID");
verifie(!str_contains($couts['membre1@banc.test'] ?? 'x', '5145550103') && !str_contains($couts['membre1@banc.test'] ?? 'x', '142.2500'), "coûts : le comité membre ne voit ni l'autre DID ni le solde");

// --- Statistiques de la ligne provinciale ---
$provinciale = courrielsDuScript('genstatsLAA.php');
comparerReference('provinciale.eml', $provinciale['provincial@banc.test'] ?? '');
verifie(str_contains($provinciale['provincial@banc.test'] ?? '', 'région 87') && str_contains($provinciale['provincial@banc.test'] ?? '', 'région 88'), "ligne provinciale : les appels sont répartis par région");
verifie(str_contains($provinciale['provincial@banc.test'] ?? '', 'région 5145550199'), "ligne provinciale : un numéro hors des listes reste affiché");

// --- voip.ms en panne : le rapport le dit au lieu de donner des chiffres faux ---
simulerPanne('voip.ms', array_fill(0, 60, 500));
$enPanne = courrielsDuScript('gendidcostreport.php');
verifie(str_contains($enPanne['autonome@banc.test'] ?? '', "erreur d'extraction") || str_contains($enPanne['autonome@banc.test'] ?? '', 'Erreur HTTP'), "coûts, voip.ms en panne : le courriel signale l'erreur");
verifie(!str_contains($enPanne['autonome@banc.test'] ?? 'x', 'Coût total du compte'), "coûts, voip.ms en panne : aucun total inventé");
verifie(count($enPanne) === count(courriels()) && !array_key_exists('admin@banc.test', $enPanne), "coûts, voip.ms en panne : aucun courriel à l'admin pour une erreur de voip.ms");
$journauxVoipms = glob(DOSSIER_JOURNAUX.'*id2voipmslog.txt');
$journalVoipms = $journauxVoipms === [] ? '' : (string)file_get_contents($journauxVoipms[0]);
verifie(str_contains($journalVoipms, 'Erreur HTTP : 500') && !str_contains($journalVoipms, 'api_password'), "coûts, voip.ms en panne : l'erreur est au journal voipms du comité, sans mot de passe");

// --- Envoyer un rapport depuis une page : un bouton (POST avec le jeton CSRF) suivi d'une redirection vers la page.
// Une adresse ne l'envoie jamais (lien d'une autre page, F5, historique), et ce qui est parti est dit une seule fois.
@unlink(ETAT_BANC.'/panne-voip.ms');
oublierCourriels();
connecterAuPortail('autonome');
$mesStatistiques = PORTAIL.'?page=mystats';
$page = requetePortail($mesStatistiques)['page'];
verifie(substr_count($page, "name='reportaction' value='sendstats'") === 1 && substr_count($page, "name='reportaction' value='senddidcost'") === 1 && !str_contains($page, 'reportaction=send'),
	"page mes statistiques : les deux envois sont des boutons (un formulaire POST chacun), plus des liens");
$page = requetePortail($mesStatistiques.'&reportaction=sendstats')['page'];
verifie(courriels() === [] && !str_contains($page, 'Courriel envoyé'), "page mes statistiques : l'ancienne adresse (GET) n'envoie rien");
$sansJeton = requetePortail($mesStatistiques, ['reportaction' => 'sendstats']);
verifie($sansJeton['code'] === 403 && courriels() === [], "page mes statistiques : un envoi sans jeton est refusé, rien ne part (code {$sansJeton['code']})");
$reponse = requetePortail($mesStatistiques, ['reportaction' => 'sendstats', 'csrf_token' => jetonCsrfDeLaSession()]);
verifie(redirigeVers($reponse, 'managementportal.php?page=mystats'), "page mes statistiques : l'envoi répond par une redirection vers la page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(count(courrielsA('autonome@banc.test')) === 1 && count(courriels()) === 1, "page mes statistiques : le rapport part par courriel, une fois");
// La page qui suit montre encore le rapport qui vient de partir, même si le corps du courriel est vidé après l'envoi
$page = suivreRedirection($reponse)['page'];
verifie(substr_count($page, 'Courriel envoyé') === 1 && str_contains($page, 'Voici les statistiques'), "page mes statistiques : la page qui suit dit l'envoi une fois et montre le rapport envoyé");
verifie(lectures($mesStatistiques, 'Courriel envoyé') === [0, 0] && count(courriels()) === 1, "page mes statistiques : le rechargement ne renvoie rien et ne redit pas l'envoi");

// La console de l'admin, pour un comité membre d'un compte principal : l'écran montre tout le compte, le courriel
// seulement le DID du comité ; juste après l'envoi, l'écran montre ce que le comité a reçu
connecterAuPortail('admin');
oublierCourriels();
$rapportDuMembre = PORTAIL.'?page=userstatsreport&iduser=4';
$coutsAffiches = fn(string $page): string => (string)strstr($page, 'Répartition des coûts par DID');
$avant = $coutsAffiches(requetePortail($rapportDuMembre)['page']);
verifie(str_contains($avant, 'vous voyez tout le compte') && str_contains($avant, '5145550102') && str_contains($avant, '5145550103'), "console, comité membre : l'admin voit les coûts de tout le compte");
$fauxJeton = requetePortail($rapportDuMembre, ['reportaction' => 'senddidcost', 'csrf_token' => 'faux']);
verifie($fauxJeton['code'] === 403 && courriels() === [], "console, comité membre : un envoi avec un faux jeton est refusé, rien ne part (code {$fauxJeton['code']})");
$reponse = requetePortail($rapportDuMembre, ['reportaction' => 'senddidcost', 'csrf_token' => jetonCsrfDeLaSession()]);
verifie(redirigeVers($reponse, 'managementportal.php?page=userstatsreport&iduser=4'), "console, comité membre : l'envoi des coûts redirige vers la page de ce comité (".enTete($reponse, 'Location').")");
$recus = courrielsA('membre1@banc.test');
verifie(count($recus) === 1 && count(courriels()) === 1 && str_contains($recus[0], '5145550102') && !str_contains($recus[0], '5145550103') && !str_contains($recus[0], '142.2500'),
	"console, comité membre : le comité reçoit un courriel, avec seulement son DID");
$apres = $coutsAffiches(suivreRedirection($reponse)['page']);
verifie(str_contains($apres, 'Courriel envoyé') && str_contains($apres, 'Voici ce que le comité a reçu') && str_contains($apres, '5145550102') && !str_contains($apres, '5145550103'),
	"console, comité membre : la page qui suit dit l'envoi et montre ce que le comité a reçu");
$rechargee = $coutsAffiches(requetePortail($rapportDuMembre)['page']);
verifie(!str_contains($rechargee, 'Courriel envoyé') && str_contains($rechargee, '5145550103') && count(courriels()) === 1, "console, comité membre : au rechargement, rien ne repart et l'écran remontre tout le compte");

finDuScenario();
