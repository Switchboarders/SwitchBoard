#!/usr/bin/env php
<?php
// L'instantané du HTML de chaque page du portail (tâche 9.1, séparation traitement / vue) : la page rendue, pour un comité
// et pour un admin, est comparée à sa référence dans reference/page-*.html. Séparer le traitement de la vue ne doit changer
// aucun octet de ce que voit la personne ; un écart voulu se régénère avec lancer.sh --nouvelles-references et se relit
// par git diff. Les jetons CSRF (différents à chaque session) sont remplacés par JETON avant la comparaison. Chaque page est
// lue deux fois : si deux lectures diffèrent, la page n'est pas stable et le banc le dit (rien n'est alors comparé).
require_once '/banc/commun/banc.php';

const COMITE = 2; // autonome

function normaliser(string $page): string {
	// Les en-têtes HTTP de la réponse ne comptent pas, seulement la page
	$page = preg_replace('/\A.*?\r?\n\r?\n/s', '', $page, 1);
	$page = preg_replace('/[0-9a-f]{32,64}/', 'JETON', $page);
	// Les numéros de version (code et schéma de BD) changent à chaque version : ils ne sont pas ce que l'instantané garde
	$page = preg_replace('/(Version du code installée|version du schéma de BD) : \d+/', '$1 : N', $page);
	// L'heure du jour (le journal d'exécution de la page principale) et les durées d'exécution changent à chaque lecture
	$page = preg_replace('/\d{2}-\d{2}-\d{4} \d{2}:\d{2}(:\d{2})?/', 'DATE', $page);
	return preg_replace('/(Temps d(?:&#039;|\')exécution|Temps d(?:&#039;|\')execution)\s*:\s*[0-9.]+/', '$1 : DUREE', $page);
}
function instantane(string $role, string $route, ?int $iduser = null): void {
	$adresse = PORTAIL."?page=$route".($iduser === null ? '' : "&iduser=$iduser");
	// Une première lecture, jetée : la page d'informations du compte crée son jeton à la toute première ouverture (un GET qui
	// modifie, noté dans archives/REFONTE-2026-11.md) et le dit alors dans la page
	requetePortail($adresse);
	$premiere = requetePortail($adresse);
	$seconde = requetePortail($adresse);
	$nom = "page-$role-$route".($iduser === null ? '' : "-$iduser").'.html';
	$a = normaliser($premiere['page']);
	if ($a !== normaliser($seconde['page'])) {
		verifie(false, "$nom : la page n'est pas stable (deux lectures différentes)");
		file_put_contents('/tmp/lecture1', $a);
		file_put_contents('/tmp/lecture2', normaliser($seconde['page']));
		passthru('diff /tmp/lecture1 /tmp/lecture2 | head -20');
		return;
	}
	verifie($premiere['code'] === 200, "$nom : la page s'affiche (code {$premiere['code']})");
	comparerReference($nom, $a);
}

repartirAZero();

// Pages que le portail n'ouvre pas pour agir (jeton CSRF) : toutes les autres routes
$pages = array_values(array_diff(array_keys(Routes::PAGES), Routes::ACTIONS, ['logout']));
$admin = array_values(array_filter($pages, fn(string $p) => Routes::PAGES[$p][2] === Routes::ADMIN));
$connecte = array_values(array_filter($pages, fn(string $p) => Routes::PAGES[$p][2] === Routes::CONNECTE));
// Les pages d'admin qui visent un comité
$avecComite = ['userloginandprivileges', 'userservice', 'userdiagnostic', 'manageuserssbchat', 'userstatsreport'];

connecterAuPortail('autonome');
foreach ($connecte as $route) {
	instantane('comite', $route);
}

connecterAuPortail('admin');
foreach ($admin as $route) {
	instantane('admin', $route, in_array($route, $avecComite, true) ? COMITE : null);
}
// Un admin qui ouvre les pages d'un comité (iduser dans l'adresse)
foreach (['userinfo', 'sbchatinfo', 'sbchatagreement', 'sbchatmacros', 'mystats'] as $route) {
	instantane('admin', $route, COMITE);
}

finDuScenario();
