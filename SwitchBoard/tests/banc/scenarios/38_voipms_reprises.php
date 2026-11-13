#!/usr/bin/env php
<?php
// Les corrections de la tâche 6.2 (archives/REFONTE-2026-11.md), de bout en bout : le vrai cron et le vrai portail
// contre le faux voip.ms, avec les pannes que le vrai voip.ms a données.
// - un 403 passager sur setForwarding est repris (vu sur le serveur pilote, 2026-11-06) ;
// - un 525 (Cloudflare n'a pas pu négocier TLS avec voip.ms) est repris ;
// - si aucun renvoi ne peut être créé, le groupe vide n'est pas envoyé : les répondants en place restent ;
// - un nom de groupe que voip.ms refuse est dit au comité ;
// - des identifiants refusés sont dits tels quels, et non « la messagerie vocale a-t-elle été créée ? ».
require_once '/banc/commun/banc.php';

const JEAN = '5145550111';
const MARIE = '5145550112';
const AUTONOME = 2;
const GROUPE = 'Groupe autonome';

$maintenant = time();
$quart = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => $maintenant - 3600, 'fin' => $maintenant + 3600];

function passage(): array {
	viderJournalFaux();
	return lancerCron('refreshallringgroups.php');
}
function preparer(): void {
	repartirAZero();
	sonnerieSeulementPour([AUTONOME]);
}
// Les requêtes de ce compte à voip.ms, dans l'ordre, avec leur méthode seulement
function requetes(): array {
	return methodesVoipms('banc-autonome');
}
function pageForcer(): string {
	connecterAuPortail('autonome');
	return ouvrirPage('refreshringgroup')['page'];
}
function changerChamp(string $colonne, string $valeur): void {
	$bd = connexionBD();
	$requete = $bd->prepare("UPDATE userinfo SET $colonne = ? WHERE iduser = ".AUTONOME);
	$requete->bind_param('s', $valeur);
	$requete->execute();
}

// --- 403 passager sur setForwarding : repris, le répondant est dans le groupe ---
preparer();
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
viderJournalFaux();
// 1re requête (getForwardings) passe ; 2e (setForwarding) : 403 ; la reprise passe
simulerPanne('voip.ms', [200, 403]);
passage();
$methodes = requetes();
verifie(array_count_values($methodes)['setForwarding'] === 2, "403 sur setForwarding : la requête est reprise (".implode(',', $methodes).")");
verifie(groupesVoipms('banc-autonome') === [GROUPE => [JEAN]], "403 sur setForwarding : Jean est dans le groupe de sonnerie après la reprise");
verifie(renvoisVoipms('banc-autonome') === [JEAN], "403 sur setForwarding : un seul renvoi, pas de doublon");
passage();
verifie(requetes() === [], "403 sur setForwarding : la liste est conservée, le passage suivant n'appelle plus voip.ms");

// --- 525 sur setForwarding puis sur setRingGroup : repris ---
preparer();
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
viderJournalFaux();
simulerPanne('voip.ms', [200, 525]);
passage();
$methodes = requetes();
verifie(array_count_values($methodes)['setForwarding'] === 2, "525 sur setForwarding : la requête est reprise (".implode(',', $methodes).")");
verifie(groupesVoipms('banc-autonome') === [GROUPE => [JEAN]], "525 sur setForwarding : Jean est dans le groupe de sonnerie");
ecrireAgenda('autonome', [$quart('Marie', '514-555-0112')]);
viderJournalFaux();
// getForwardings n'est pas lu (liste conservée) : 1re requête setForwarding, 2e getVoicemails, 3e getRingGroups, 4e setRingGroup
simulerPanne('voip.ms', [200, 200, 200, 525]);
passage();
verifie(array_count_values(requetes())['setRingGroup'] === 2, "525 sur setRingGroup : la requête est reprise (".implode(',', requetes()).")");
verifie(groupesVoipms('banc-autonome') === [GROUPE => [MARIE]], "525 sur setRingGroup : Marie remplace Jean");

// --- Aucun renvoi ne peut être créé : le groupe vide n'est pas envoyé, Jean reste ---
preparer();
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE => [JEAN]], "départ : Jean est dans le groupe");
ecrireAgenda('autonome', [$quart('Marie', '514-555-0112')]);
viderJournalFaux();
// Les 3 tentatives de setForwarding pour Marie sont refusées
simulerPanne('voip.ms', [403, 403, 403]);
passage();
$methodes = requetes();
verifie(!in_array('setRingGroup', $methodes, true), "aucun renvoi créé : aucun groupe vide n'est envoyé (".implode(',', $methodes).")");
verifie(!in_array('delForwarding', $methodes, true), "aucun renvoi créé : aucun renvoi n'est effacé");
verifie(groupesVoipms('banc-autonome') === [GROUPE => [JEAN]] && renvoisVoipms('banc-autonome') === [JEAN], "aucun renvoi créé : Jean reste dans le groupe, avec son renvoi");
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE => [MARIE]], "aucun renvoi créé : le passage suivant met Marie dans le groupe");
verifie(renvoisVoipms('banc-autonome') === [MARIE], "aucun renvoi créé : le passage suivant efface le renvoi de Jean");

// --- Nom de groupe refusé par voip.ms : le comité le lit ---
preparer();
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
changerChamp('ringgroupname', 'Un nom de groupe beaucoup trop long pour voip.ms');
$page = pageForcer();
verifie(str_contains($page, 'refuse le nom du groupe de sonnerie') && str_contains($page, 'trop long'), "nom de groupe trop long : la page « Forcer » le dit");
verifie(!str_contains($page, 'name_toolong'), "nom de groupe trop long : pas de code technique à l'écran");
verifie(groupesVoipms('banc-autonome') === [], "nom de groupe trop long : aucun groupe n'est créé chez voip.ms");

// --- Identifiants refusés : la vraie cause, pas « la messagerie vocale a-t-elle été créée ? » ---
preparer();
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
changerChamp('voipmsapipassword', (new Chiffrement())->encrypt('mauvais-mot-de-passe'));
$page = pageForcer();
verifie(str_contains($page, 'voip.ms refuse le nom d') && str_contains($page, 'mot de passe API'), "identifiants refusés : la page « Forcer » dit que voip.ms refuse les identifiants");
verifie(!str_contains($page, 'a-t-elle été créée'), "identifiants refusés : plus de « la messagerie vocale a-t-elle été créée ? »");

finDuScenario();
