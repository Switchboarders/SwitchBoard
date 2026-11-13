#!/usr/bin/env php
<?php
// Ce que les pages du portail disent de voip.ms : la page principale (adresse IP et renvois), « Forcer le changement de
// répondants » (les messages du changement, ligne par ligne) et le diagnostic d'un comité. Chaque page est comparée à sa
// référence, dans plusieurs situations : voip.ms répond bien, refuse les identifiants, ne répond pas ou refuse une
// requête. Les requêtes envoyées à voip.ms sont comparées aussi (empreinte de banc.php, reference/voipms-*.txt).
require_once '/banc/commun/banc.php';

const AUTONOME = 2;
const JOURNAL_NGINX = '/var/log/nginx/error.log';

function quart(string $nom, string $telephone, int $decalageDebut = -3600, int $decalageFin = 3600): array {
	return ['nom' => $nom, 'description' => $telephone, 'debut' => time() + $decalageDebut, 'fin' => time() + $decalageFin];
}
function avertissementsPhp(): array {
	$lignes = @file(JOURNAL_NGINX, FILE_IGNORE_NEW_LINES) ?: [];
	file_put_contents(JOURNAL_NGINX, '');
	return array_values(array_filter($lignes, fn(string $ligne) => preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)/', $ligne) === 1));
}
// Le contenu de la page, sans ce qui change d'un passage à l'autre (jeton CSRF, dates, heures)
function normaliser(string $page): string {
	$page = preg_replace('/^.*?\r?\n\r?\n/s', '', $page, 1);
	// Les journaux d'exécution au bas de la page principale racontent aussi les scénarios d'avant : pas de référence
	$page = preg_replace('/<h2>Journaux d.exécution.*$/s', '', $page);
	$page = preg_replace('/(name=.csrf_?token.\s+value=.)[^\'"]+/i', '$1JETON', $page);
	$page = preg_replace('/\d{2}-\d{2}-\d{4} \d{2}:\d{2}(:\d{2})?/', 'JJ-MM-AAAA HH:MM', $page);
	$page = preg_replace('/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?/', 'AAAA-MM-JJ HH:MM', $page);
	return $page;
}
function page(string $route, ?int $iduser = null): string {
	return normaliser(ouvrirPage($route, $iduser)['page']);
}
function voirPage(string $nom, string $utilisateur, string $route, ?int $iduser = null): string {
	connecterAuPortail($utilisateur);
	avertissementsPhp();
	$contenu = page($route, $iduser);
	verifie(avertissementsPhp() === [] && !preg_match('/Fatal error|Uncaught |Stack trace/', $contenu), "$nom : aucun avertissement PHP, aucune erreur fatale");
	comparerReference("voipms-page-$nom.html", $contenu);
	return $contenu;
}
function changerMotDePasseVoipms(int $iduser, string $motDePasse): void {
	$chiffre = (new Chiffrement())->encrypt($motDePasse);
	$bd = connexionBD();
	$requete = $bd->prepare('UPDATE userinfo SET voipmsapipassword = ? WHERE iduser = ?');
	$requete->bind_param('si', $chiffre, $iduser);
	$requete->execute();
}
function changerNomDuGroupe(int $iduser, string $nom): void {
	$bd = connexionBD();
	$requete = $bd->prepare('UPDATE userinfo SET ringgroupname = ? WHERE iduser = ?');
	$requete->bind_param('si', $nom, $iduser);
	$requete->execute();
}

repartirAZero();
sonnerieSeulementPour([AUTONOME]);

// --- Page principale : voip.ms répond, aucun renvoi encore ---
$principale = voirPage('principale-sans-renvoi', 'autonome', 'mainpage');
verifie(str_contains($principale, '203.0.113.10'), "page principale : l'adresse IP vue par voip.ms est affichée");

// --- Forcer : premier envoi (rien chez voip.ms), puis les renvois sont là ---
ecrireAgenda('autonome', [quart('Jean', '514-555-0111'), quart('Marie', '(514) 555-0112 nbsec->20 faire1->n')]);
$forcee = voirPage('forcer-premier-envoi', 'autonome', 'refreshringgroup');
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111', '5145550112']], "forcer : les deux répondants sont dans le groupe");
voirPage('principale-avec-renvois', 'autonome', 'mainpage');

// --- Forcer une deuxième fois : tout est renvoyé, rien n'est en double ---
voirPage('forcer-deuxieme-envoi', 'autonome', 'refreshringgroup');
verifie(renvoisVoipms('banc-autonome') === ['5145550111', '5145550112'], "forcer deux fois : les renvois ne sont pas en double");

// --- Forcer avec un changement de garde : l'ancien renvoi est effacé ---
ecrireAgenda('autonome', [quart('Lise', '514-555-0113')]);
voirPage('forcer-changement-de-garde', 'autonome', 'refreshringgroup');
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550113']] && renvoisVoipms('banc-autonome') === ['5145550113'], "forcer : Lise remplace les autres, leurs renvois sont effacés");

// --- Forcer sans personne de garde ---
ecrireAgenda('autonome', [quart('Jean', '514-555-0111', 6 * 3600, 8 * 3600)]);
voirPage('forcer-personne-de-garde', 'autonome', 'refreshringgroup');

// --- Forcer avec un agenda illisible ---
unlink(ETAT_BANC.'/agenda-autonome.ics');
array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*.ical'));
voirPage('forcer-agenda-illisible', 'autonome', 'refreshringgroup');

// --- Forcer avec un numéro refusé dans l'agenda ---
ecrireAgenda('autonome', [quart('Jean', '514-555-0111'), quart('Paul', '514-555-012')]);
voirPage('forcer-numero-refuse', 'autonome', 'refreshringgroup');

// --- Forcer avec un nom de groupe que voip.ms refuse (trop long) : le faux service le refuse comme le vrai ---
changerNomDuGroupe(AUTONOME, 'Un nom de groupe beaucoup trop long pour voip.ms');
voirPage('forcer-nom-trop-long', 'autonome', 'refreshringgroup');
changerNomDuGroupe(AUTONOME, 'Groupe autonome');

// --- Identifiants refusés par voip.ms : la page principale, Forcer et le diagnostic le disent ---
changerMotDePasseVoipms(AUTONOME, 'mauvais-mot-de-passe');
voirPage('principale-identifiants-refuses', 'autonome', 'mainpage');
voirPage('forcer-identifiants-refuses', 'autonome', 'refreshringgroup');
voirPage('diagnostic-identifiants-refuses', 'admin', 'userdiagnostic', AUTONOME);
changerMotDePasseVoipms(AUTONOME, 'banc-banc-autonome');

// --- Diagnostic : tout va bien ---
ecrireAgenda('autonome', [quart('Jean', '514-555-0111')]);
voirPage('diagnostic-bon', 'admin', 'userdiagnostic', AUTONOME);

// --- Diagnostic : identifiants vides, agenda non configuré ---
$bd = connexionBD();
$bd->query("UPDATE userinfo SET voipmsusername = '', voipmsapipassword = '', calendarurl = '' WHERE iduser = ".AUTONOME);
voirPage('diagnostic-identifiants-vides', 'admin', 'userdiagnostic', AUTONOME);
repartirAZero();
sonnerieSeulementPour([AUTONOME]);

// --- voip.ms en panne : chaque page le dit sans erreur fatale ---
ecrireAgenda('autonome', [quart('Jean', '514-555-0111')]);
simulerPanne('voip.ms', array_fill(0, 12, 500));
voirPage('principale-voipms-en-panne', 'autonome', 'mainpage');
simulerPanne('voip.ms', array_fill(0, 12, 500));
voirPage('forcer-voipms-en-panne', 'autonome', 'refreshringgroup');
simulerPanne('voip.ms', array_fill(0, 12, 500));
voirPage('diagnostic-voipms-en-panne', 'admin', 'userdiagnostic', AUTONOME);
@unlink(ETAT_BANC.'/panne-voip.ms');

finDuScenario();
