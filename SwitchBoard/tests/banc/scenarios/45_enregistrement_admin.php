#!/usr/bin/env php
<?php
// La suite de #77 : les formulaires de la console qui agissent (ajout d'un utilisateur, mot de passe et niveau d'accès,
// signal de vie, courriel de test) répondent eux aussi par une redirection vers la même page en GET. F5 ou le bouton
// Précédent ne renvoient plus le formulaire : en particulier, ils n'envoient plus un autre ping de test ni un autre
// courriel de test. Ce que l'envoi a dit s'affiche une seule fois. Un envoi sans jeton CSRF est refusé.
require_once '/banc/commun/banc.php';

const AUTONOME = 2;

repartirAZero();
oublierCourriels();
connecterAuPortail('admin');

// Envoie le formulaire de la page (jeton lu dans la page) ; retourne la réponse
function envoyer(string $adresse, array $champs): array {
	return requetePortail($adresse, $champs + ['csrf_token' => jetonDuFormulaire($adresse)]);
}
// Les courriels de test envoyés (le courriel de test n'a pas d'en-tête To: : msmtp reçoit l'adresse en argument)
function courrielsDeTest(): array {
	return array_values(array_filter(courriels(), fn($courriel) => str_contains($courriel, 'courriel de test')));
}

// --- Ajout d'un utilisateur ---
$adresse = PORTAIL.'?page=modifyuser';
$champs = ['createormodifyusername' => 'nouveau', 'createormodifypassword' => 'Mot-de-passe-1', 'privilegelevel' => '10'];
$sansJeton = requetePortail($adresse, $champs);
verifie($sansJeton['code'] === 403, "ajout d'un utilisateur, sans jeton : refusé (code {$sansJeton['code']})");
verifie(ligneDeLaBd("SELECT iduser FROM user WHERE username = 'nouveau'") === [], "ajout d'un utilisateur, sans jeton : rien n'est enregistré");
$reponse = envoyer($adresse, $champs);
verifie(redirigeVers($reponse, 'managementportal.php?page=modifyuser'), "ajout d'un utilisateur : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(ligneDeLaBd("SELECT iduser FROM user WHERE username = 'nouveau'") !== [], "ajout d'un utilisateur : l'utilisateur est créé");
verifie(lectures($adresse, 'Utilisateur nouveau entré avec succès') === [1, 0], "ajout d'un utilisateur : le succès est dit une fois, pas au rechargement");
envoyer($adresse, ['createormodifyusername' => 'a<b>x', 'createormodifypassword' => 'Mot-de-passe-1', 'privilegelevel' => '10']);
$page = requetePortail($adresse)['page'];
verifie(str_contains($page, 'a&lt;b&gt;x entré avec succès') && !str_contains($page, 'a<b>x'), "ajout d'un utilisateur : le nom est échappé à l'écran");

// --- Mot de passe et niveau d'accès d'un utilisateur ---
$adresse = PORTAIL.'?page=userloginandprivileges&iduser='.AUTONOME;
$champs = ['createormodifypassword' => 'Autre-mot-de-passe-1', 'privilegelevel' => '7'];
$sansJeton = requetePortail($adresse, $champs);
verifie($sansJeton['code'] === 403, "mot de passe et niveau : sans jeton, refusé (code {$sansJeton['code']})");
verifie((int)ligneDeLaBd('SELECT privilegelevel FROM user WHERE iduser = '.AUTONOME)['privilegelevel'] === 10, "mot de passe et niveau : sans jeton, rien n'est enregistré");
$reponse = envoyer($adresse, $champs);
verifie(redirigeVers($reponse, 'managementportal.php?page=userloginandprivileges&iduser='.AUTONOME), "mot de passe et niveau : redirection vers la même page, même utilisateur (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
$utilisateur = ligneDeLaBd('SELECT privilegelevel, password FROM user WHERE iduser = '.AUTONOME);
verifie((int)$utilisateur['privilegelevel'] === 7 && password_verify('Autre-mot-de-passe-1', $utilisateur['password']), "mot de passe et niveau : le niveau et le mot de passe sont enregistrés");
verifie(requetePortail($adresse)['code'] === 200, "mot de passe et niveau : la page qui suit s'affiche");
// Un niveau seul (mot de passe vide) laisse le mot de passe
$hachage = $utilisateur['password'];
envoyer($adresse, ['createormodifypassword' => '', 'privilegelevel' => '10']);
$utilisateur = ligneDeLaBd('SELECT privilegelevel, password FROM user WHERE iduser = '.AUTONOME);
verifie((int)$utilisateur['privilegelevel'] === 10 && $utilisateur['password'] === $hachage, "mot de passe et niveau : un niveau seul laisse le mot de passe");

// --- Signal de vie (dead-man's-switch) ---
// Les checks du banc sont réglés par l'environnement : « Retirer » et « Enregistrer » le disent, « Test » envoie vraiment
$adresse = PORTAIL.'?page=deadmanswitch';
$sansJeton = requetePortail($adresse, ['action' => 'test_ping_deadmanswitch', 'check' => 'ringgroup']);
verifie($sansJeton['code'] === 403, "signal de vie, sans jeton : refusé (code {$sansJeton['code']})");
viderJournalFaux();
$reponse = envoyer($adresse, ['action' => 'test_ping_deadmanswitch', 'check' => 'ringgroup']);
verifie(redirigeVers($reponse, 'managementportal.php?page=deadmanswitch'), "signal de vie, ping de test : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail', '/banc-ringgroup'], "signal de vie, ping de test : un ping d'échec puis un ping de succès (".implode(',', pingsSignalDeVie('ringgroup')).")");
verifie(lectures($adresse, 'Ping de test envoyé pour') === [1, 0], "signal de vie, ping de test : le résultat est dit une fois, pas au rechargement");
verifie(count(pingsSignalDeVie('ringgroup')) === 2, "signal de vie, ping de test : recharger la page n'envoie pas un autre ping");
$reponse = envoyer($adresse, ['action' => 'enregistrer_deadmanswitch', 'check' => 'ringgroup', 'success_url' => 'https://hc-ping.com/xxxxxxxx', 'fail_url' => '']);
verifie(redirigeVers($reponse, 'managementportal.php?page=deadmanswitch'), "signal de vie, enregistrement : redirection vers la même page");
verifie(lectures($adresse, 'est géré par une variable d') === [1, 0], "signal de vie, enregistrement : le refus (réglé par l'environnement) est dit");

// --- Courriel de test ---
$adresse = PORTAIL.'?page=mailtest';
$sansJeton = requetePortail($adresse, ['action' => 'tester', 'adresse' => 'destinataire@banc.test']);
verifie($sansJeton['code'] === 403, "courriel de test, sans jeton : refusé (code {$sansJeton['code']})");
verifie(courrielsDeTest() === [], "courriel de test, sans jeton : rien n'est envoyé");
$reponse = envoyer($adresse, ['action' => 'tester', 'adresse' => 'destinataire@banc.test']);
verifie(redirigeVers($reponse, 'managementportal.php?page=mailtest'), "courriel de test : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(count(courrielsDeTest()) === 1, "courriel de test : un courriel est envoyé");
verifie(lectures($adresse, 'Courriel de test envoyé avec succès') === [1, 0], "courriel de test : le résultat est dit une fois, pas au rechargement");
verifie(count(courrielsDeTest()) === 1, "courriel de test : recharger la page n'envoie pas un autre courriel");
envoyer($adresse, ['action' => 'tester', 'adresse' => 'pas une adresse']);
verifie(lectures($adresse, 'Adresse courriel non valide') === [1, 0], "courriel de test : l'erreur de saisie est dite une fois");
verifie(count(courriels()) === 1, "courriel de test : une adresse invalide n'envoie rien");

finDuScenario();
