#!/usr/bin/env php
<?php
// L'assistant « Courriel OAuth » (#77) : chaque action répond par une redirection vers la page en GET. F5 ou le bouton
// Précédent ne refont ni la demande de code chez Microsoft, ni la vérification, ni l'échange du code de Google. Ce que
// l'action a dit s'affiche une seule fois. Sans jeton CSRF : refusé, et le fournisseur ne reçoit rien. Le retour de Google
// (une adresse qui porte le code d'autorisation) redirige aussi : le code ne reste ni dans l'adresse ni dans l'historique.
require_once '/banc/commun/banc.php';
require_once COMMUN_DIRECTORY.'oauthcourriel.php';

const ADRESSE = PORTAIL.'?page=mailoauth';
const RETOUR = 'managementportal.php?page=mailoauth';

function appels(string $hote): int {
	return count(journalFaux($hote));
}
// Un POST de la page, avec le jeton de la session (lu avant : une page lue après le POST montrerait, et effacerait, ce que l'action a dit) ; $jeton = null : sans jeton
function action(string $action, array $champs = [], ?string $jeton = '', string $adresse = ADRESSE): array {
	$jeton = $jeton === '' ? jetonCsrfDeLaSession() : $jeton;
	return requetePortail($adresse, ['action' => $action] + $champs + ($jeton === null ? [] : ['csrf_token' => $jeton]));
}
function nettoyer(): void {
	foreach ([cheminReglagesOAuthCourriel(), cheminJetonOAuthCourriel(), ETAT_BANC.'/ms-autorise'] as $fichier) {
		@unlink($fichier);
	}
}

repartirAZero();
nettoyer();
connecterAuPortail('admin');

// --- Rien n'est configuré : le formulaire des réglages ---
$page = requetePortail(ADRESSE)['page'];
verifie(str_contains($page, 'value="enregistrer_reglages"'), "départ : la page offre le formulaire des réglages");

// --- Réglages : une erreur de saisie ---
$sansJeton = action('enregistrer_reglages', ['fournisseur' => 'microsoft365', 'mailbox_user' => 'banc@banc.test', 'client_id' => 'cid'], null);
verifie($sansJeton['code'] === 403 && reglagesOAuthCourriel()['fournisseur'] === '', "réglages sans jeton : refusés, rien n'est enregistré (code {$sansJeton['code']})");
$reponse = action('enregistrer_reglages', ['fournisseur' => 'autre', 'mailbox_user' => 'banc@banc.test', 'client_id' => 'cid']);
verifie(redirigeVers($reponse, RETOUR), "réglages invalides : redirection vers la page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(lectures(ADRESSE, 'Choisissez un fournisseur') === [1, 0], "réglages invalides : l'erreur est dite une fois, pas au rechargement");
verifie(reglagesOAuthCourriel()['fournisseur'] === '', "réglages invalides : rien n'est enregistré");

// --- Microsoft 365 : réglages, code d'appareil, vérification ---
$reponse = action('enregistrer_reglages', ['fournisseur' => 'microsoft365', 'mailbox_user' => 'banc@banc.test', 'client_id' => 'cid-banc', 'tenant' => '']);
verifie(redirigeVers($reponse, RETOUR), "réglages : redirection vers la page (code {$reponse['code']})");
verifie(lectures(ADRESSE, 'Réglages enregistrés') === [1, 0], "réglages : le résultat est dit une fois, pas au rechargement");
$reglages = reglagesOAuthCourriel();
verifie($reglages['fournisseur'] === 'microsoft365' && $reglages['client_id'] === 'cid-banc' && $reglages['mailbox_user'] === 'banc@banc.test', "réglages : Microsoft 365 est enregistré");

viderJournalFaux();
$sansJeton = action('demarrer', [], null);
verifie($sansJeton['code'] === 403 && appels('login.microsoftonline.com') === 0, "démarrer sans jeton : refusé, Microsoft ne reçoit rien (code {$sansJeton['code']})");
$reponse = action('demarrer');
verifie(redirigeVers($reponse, RETOUR), "démarrer : redirection vers la page (code {$reponse['code']})");
$page = requetePortail(ADRESSE)['page'];
verifie(str_contains($page, 'BANC-1234') && str_contains($page, 'microsoft.com/devicelogin'), "démarrer : la page montre le code et l'adresse à ouvrir chez Microsoft");
verifie(str_contains(requetePortail(ADRESSE)['page'], 'BANC-1234'), "démarrer : le code reste affiché au rechargement (il est gardé en session)");
verifie(appels('login.microsoftonline.com') === 1, "démarrer : un seul appel à Microsoft, le rechargement n'en refait pas (".appels('login.microsoftonline.com').")");

viderJournalFaux();
$reponse = action('verifier');
verifie(redirigeVers($reponse, RETOUR), "vérifier : redirection vers la page (code {$reponse['code']})");
verifie(lectures(ADRESSE, 'Pas encore autorisé') === [1, 0], "vérifier avant l'autorisation : « pas encore autorisé » est dit une fois");
verifie(appels('login.microsoftonline.com') === 1 && lireJetonOAuthCourriel() === '', "vérifier avant l'autorisation : une demande de jeton, aucun jeton enregistré");

touch(ETAT_BANC.'/ms-autorise');
viderJournalFaux();
$reponse = action('verifier');
verifie(redirigeVers($reponse, RETOUR), "vérifier après l'autorisation : redirection vers la page (code {$reponse['code']})");
verifie(lectures(ADRESSE, 'Microsoft 365 autorisé avec succès') === [1, 0], "vérifier après l'autorisation : le succès est dit une fois");
verifie(lireJetonOAuthCourriel() === 'rt-banc-ms', "vérifier après l'autorisation : le jeton de rafraîchissement est enregistré (chiffré)");
verifie(appels('login.microsoftonline.com') === 1, "vérifier : un seul appel à Microsoft, le rechargement n'en refait pas (".appels('login.microsoftonline.com').")");
verifie(!str_contains(requetePortail(ADRESSE)['page'], 'BANC-1234'), "vérifier : le code d'appareil n'est plus affiché une fois l'autorisation faite");

$reponse = action('supprimer');
verifie(redirigeVers($reponse, RETOUR) && lireJetonOAuthCourriel() === '', "retirer l'autorisation : le jeton est retiré, redirection vers la page");
verifie(lectures(ADRESSE, 'Autorisation retirée') === [1, 0], "retirer l'autorisation : le résultat est dit une fois");

// --- Google : les réglages, puis le retour de Google dans l'adresse ---
$reponse = action('supprimer_reglages');
verifie(redirigeVers($reponse, RETOUR) && reglagesOAuthCourriel()['fournisseur'] === '', "retirer les réglages : redirection vers la page, les réglages sont retirés");
verifie(lectures(ADRESSE, 'Réglages retirés') === [1, 0], "retirer les réglages : le résultat est dit une fois");
// Une erreur sur le formulaire ouvert pour modifier le garde ouvert
action('enregistrer_reglages', ['fournisseur' => 'google', 'mailbox_user' => 'banc@banc.test', 'client_id' => 'gid-banc', 'client_secret' => 'gsecret-banc']);
$reponse = action('enregistrer_reglages', ['fournisseur' => 'autre'], '', ADRESSE.'&modifier=1');
verifie(redirigeVers($reponse, RETOUR.'&modifier=1'), "réglages invalides en modification : la page de retour garde le formulaire ouvert (".enTete($reponse, 'Location').")");
requetePortail(dirname(PORTAIL).'/'.RETOUR);
verifie(reglagesOAuthCourriel()['fournisseur'] === 'google' && reglagesOAuthCourriel()['client_id'] === 'gid-banc', "Google : les réglages sont enregistrés");

// Le retour de Google : l'état vient de l'adresse d'autorisation de la dernière page ouverte
$etatDeLaPage = function (): string {
	preg_match('/state=([0-9a-f]{32})/', html_entity_decode(requetePortail(ADRESSE)['page']), $trouve);
	return $trouve[1] ?? '';
};
viderJournalFaux();
$etat = $etatDeLaPage();
verifie(strlen($etat) === 32, "Google : la page offre l'adresse d'autorisation avec un état");
$retour = ADRESSE.'&code=bon-code&state='.$etat;
$reponse = requetePortail($retour);
verifie(redirigeVers($reponse, RETOUR), "retour de Google : redirection vers la page, sans le code dans l'adresse (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(lectures(ADRESSE, 'Google autorisé avec succès') === [1, 0], "retour de Google : le succès est dit une fois");
verifie(lireJetonOAuthCourriel() === 'rt-banc-google', "retour de Google : le jeton de rafraîchissement est enregistré");
verifie(appels('oauth2.googleapis.com') === 1, "retour de Google : un seul échange du code, le rechargement n'en refait pas (".appels('oauth2.googleapis.com').")");
// Rejouer la même adresse (historique, Précédent) : l'état est déjà consommé, rien n'est échangé
$rejeu = requetePortail($retour);
verifie(redirigeVers($rejeu, RETOUR) && appels('oauth2.googleapis.com') === 1, "retour de Google rejoué : refusé sans échanger le code (appels ".appels('oauth2.googleapis.com').")");
verifie(lectures(ADRESSE, 'État OAuth invalide') === [1, 0], "retour de Google rejoué : l'état invalide est dit une fois");
// Un code refusé par Google, puis un refus de la personne
$etat = $etatDeLaPage();
$reponse = requetePortail(ADRESSE.'&code=mauvais&state='.$etat);
verifie(redirigeVers($reponse, RETOUR), "code refusé : redirection vers la page");
verifie(lectures(ADRESSE, 'Code refusé (banc)') === [1, 0], "code refusé : la raison donnée par Google est dite une fois");
$reponse = requetePortail(ADRESSE.'&error=access_denied');
verifie(redirigeVers($reponse, RETOUR) && lectures(ADRESSE, 'refusée par Google : access_denied') === [1, 0], "refus de la personne : redirection vers la page, le refus est dit une fois");

nettoyer();
oublierSession();
finDuScenario();
