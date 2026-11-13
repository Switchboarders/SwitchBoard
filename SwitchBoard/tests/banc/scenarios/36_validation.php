#!/usr/bin/env php
<?php
// La page de validation de l'agenda, par le portail et par le lien sans connexion (token.php) : le vrai téléchargement
// de l'agenda, la vraie règle du numéro (NumeroTelephone) et l'échappement de tout ce qui vient de l'agenda
// (décisions G et H des mainteneurs, 2026-11-09 ; le détail de la règle est dans tests/numeroagenda_test.php).
require_once '/banc/commun/banc.php';

const AUTONOME = 2;
const JOURNAL_NGINX = '/var/log/nginx/error.log';
const JETON = 'jeton-banc-validation-0123456789';
const LIEN_SANS_CONNEXION = 'http://127.0.0.1/SwitchBoard/SwitchBoard/src/token.php?token='.JETON;

function quartEnCours(string $nom, string $description): array {
	return ['nom' => $nom, 'description' => $description, 'debut' => time() - 3600, 'fin' => time() + 3600];
}
// Les avertissements de PHP écrits depuis le dernier appel
function avertissementsPhp(): array {
	$lignes = @file(JOURNAL_NGINX, FILE_IGNORE_NEW_LINES) ?: [];
	file_put_contents(JOURNAL_NGINX, '');
	return array_values(array_filter($lignes, fn(string $ligne) => preg_match('/PHP (Warning|Fatal error|Parse error|Deprecated|Notice)/', $ligne) === 1));
}
// La page de validation, par le portail ou par le lien sans connexion
function pages(): array {
	connecterAuPortail('autonome');
	return [
		'portail' => requetePortail(PORTAIL.'?page=validatecalendar')['page'],
		'lien sans connexion' => requetePortail(LIEN_SANS_CONNEXION)['page'],
	];
}

repartirAZero();
$bd = connexionBD();
$requete = $bd->prepare('INSERT INTO usertokenaccess (iduser, token, expiration) VALUES (?, ?, NOW() + INTERVAL 1 DAY)');
$iduser = AUTONOME;
$jeton = JETON;
$requete->bind_param('is', $iduser, $jeton);
$requete->execute();
avertissementsPhp();

// --- Un agenda correct ---
ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111'), quartEnCours('Marie', 'Tél. : 1 (514) 555-0112 nbsec->30')]);
foreach (pages() as $sorte => $page) {
	verifie(str_contains($page, 'Les données sont entrées correctement'), "validation, $sorte : un agenda correct est accepté");
	verifie(str_contains($page, 'Marie : sonnerie 30 s (agenda)'), "validation, $sorte : la page montre ce que les repères donneront");
}
verifie(avertissementsPhp() === [], "validation, agenda correct : aucun avertissement de PHP");

// --- Numéros refusés et HTML dans l'agenda ---
ecrireAgenda('autonome', [
	quartEnCours('Jean', '514-555-0111'),
	quartEnCours('Paul', '<b>514-555-012</b> faire1->n'),
	quartEnCours('<script>alert(1)</script>', 'à confirmer'),
	quartEnCours('<i>Lise</i>', '25145550113'),
]);
foreach (pages() as $sorte => $page) {
	verifie(!str_contains($page, 'Les données sont entrées correctement'), "validation, $sorte : un agenda avec des numéros refusés n'est pas déclaré correct");
	verifie(str_contains($page, 'Numéro invalide pour « Paul » : « 514-555-012 »'), "validation, $sorte : un numéro incomplet est « invalide », avec ce qui a été lu");
	verifie(str_contains($page, 'Numéro absent pour « &lt;script&gt;alert(1)&lt;/script&gt; »'), "validation, $sorte : sans numéro, « absent », avec le titre échappé");
	verifie(str_contains($page, 'Numéro invalide pour « &lt;i&gt;Lise&lt;/i&gt; » : « 25145550113 »'), "validation, $sorte : 11 chiffres sans 1 devant : invalide");
	verifie(!str_contains($page, '<script>alert') && !str_contains($page, '<i>Lise') && !str_contains($page, '<b>514'), "validation, $sorte : rien de l'agenda n'arrive tel quel dans la page");
	verifie(!str_contains($page, 'faire1->n'), "validation, $sorte : le message ne cite pas la description entière");
}
verifie(avertissementsPhp() === [], "validation, numéros refusés : aucun avertissement de PHP");

finDuScenario();
