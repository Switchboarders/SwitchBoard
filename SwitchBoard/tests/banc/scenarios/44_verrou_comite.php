#!/usr/bin/env php
<?php
// Le verrou par comité (ARCHITECTURE.md, « Les passages automatiques ») de bout en bout : le vrai cron et le vrai portail contre le faux voip.ms.
// Tant qu'une mise à jour d'un comité est en cours (ici : un autre processus tient son verrou), ni le cron ni la page
// « Forcer » ne le mettent à jour une 2e fois en même temps ; les autres comités ne sont pas touchés ; le verrou
// libéré, tout reprend. Le comité sauté n'est pas un échec (le signal de vie reste bon).
require_once '/banc/commun/banc.php';

const JEAN = '5145550111';
const MARIE = '5145550112';
const AUTONOME = 2;
const MEMBRE1 = 4;

$maintenant = time();
$quart = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => $maintenant - 3600, 'fin' => $maintenant + 3600];

// La page « Forcer ». La connexion montre la page d'accueil, qui lit les renvois du comité chez voip.ms : le journal est
// vidé après elle, pour que $requetesDeLaPage ne compte que les requêtes de la page « Forcer » elle-même.
function pageForcer(string $utilisateur, ?int $iduser = null): string {
	global $requetesDeLaPage;
	connecterAuPortail($utilisateur);
	viderJournalFaux();
	$page = ouvrirPage('refreshringgroup', $iduser)['page'];
	$requetesDeLaPage = array_merge($requetesDeLaPage ?? [], methodesVoipms('banc-autonome'));
	return $page;
}

repartirAZero();
sonnerieSeulementPour([AUTONOME, MEMBRE1]);
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
ecrireAgenda('membre1', [$quart('Marie', '514-555-0112')]);

// --- Le comité « autonome » est en cours de mise à jour : un autre processus tient son verrou ---
$bloqueur = proc_open(['su', '-s', '/bin/sh', 'www-data', '-c',
	"php -r 'require \"".APPLI."/src/utils/verrouexclusif.php\"; \$verrou = VerrouExclusif::pourComite(".AUTONOME."); \$verrou->acquerirSansAttendre(); sleep(15);'"], [], $tuyaux);
sleep(2);

// Le cron saute ce comité, traite l'autre, et ne crie pas à l'échec
viderJournalFaux();
$cron = lancerCron('refreshallringgroups.php');
verifie(str_contains($cron['sortie'], 'déjà en cours de mise à jour'), "cron : le comité verrouillé est sauté");
verifie(methodesVoipms('banc-autonome') === [], "cron : aucune requête à voip.ms pour le comité verrouillé (".implode(',', methodesVoipms('banc-autonome')).")");
verifie(groupesVoipms('banc-autonome') === [], "cron : le groupe du comité verrouillé n'est pas touché");
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [MARIE]], "cron : l'autre comité est mis à jour normalement");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "cron : sauter un comité n'est pas un échec (".implode(',', pingsSignalDeVie('ringgroup')).")");

// La page « Forcer » du comité verrouillé, par lui ou par un admin, dit qu'une mise à jour est en cours et n'envoie rien
$page = pageForcer('autonome');
verifie(str_contains($page, 'déjà en cours'), "page « Forcer » du comité : elle dit qu'une mise à jour est en cours");
$page = pageForcer('admin', AUTONOME);
verifie(str_contains($page, 'déjà en cours'), "page « Forcer » d'un admin pour ce comité : elle dit qu'une mise à jour est en cours");
verifie($requetesDeLaPage === [], "pages « Forcer » : aucune requête à voip.ms pour le comité verrouillé (".implode(',', $requetesDeLaPage).")");

// Un autre comité n'est pas gêné par le verrou
$page = pageForcer('membre1');
verifie(!str_contains($page, 'déjà en cours'), "page « Forcer » d'un autre comité : pas gênée par le verrou");
verifie(!empty(methodesVoipms('banc-region')), "page « Forcer » d'un autre comité : elle a parlé à voip.ms");
proc_close($bloqueur);

// --- Le verrou est libéré (fin du processus) : tout reprend ---
viderJournalFaux();
lancerCron('refreshallringgroups.php');
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => [JEAN]], "verrou libéré : le cron met le comité à jour");
$page = pageForcer('autonome');
verifie(!str_contains($page, 'déjà en cours'), "verrou libéré : la page « Forcer » du comité fonctionne");
verifie(!str_contains($page, 'Erreur'), "verrou libéré : sans erreur à l'écran");

finDuScenario();
