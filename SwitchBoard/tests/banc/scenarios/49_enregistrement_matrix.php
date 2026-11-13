#!/usr/bin/env php
<?php
// Le formulaire des utilisateurs Matrix (#77), contre un faux MAS : créer un utilisateur, en modifier un, refaire son invitation.
// Chaque envoi répond par une redirection vers la page en GET : F5 ne recrée rien et ne change rien. Ce que MAS a répondu et
// l'invitation s'affichent une seule fois. Le MOT DE PASSE n'est jamais gardé par le serveur : ni dans la session, ni dans la
// page qui suit la redirection (le navigateur le remet dans l'invitation : sessionStorage). Sans jeton CSRF : refusé, et MAS
// ne reçoit rien.
require_once '/banc/commun/banc.php';

const ADRESSE = PORTAIL.'?page=manageuserssbchat';
const RETOUR = 'managementportal.php?page=manageuserssbchat';
const MDP_CREATION = 'Secret-Banc-1';
const MDP_ERREUR = 'Autre-Secret-2';
const MDP_MODIFICATION = 'Nouveau-Secret-3';

function etatMas(): array {
	return json_decode((string)@file_get_contents(ETAT_BANC.'/mas.json'), true) ?: ['utilisateurs' => [], 'courriels' => []];
}
function utilisateurMas(string $nom): ?array {
	foreach (etatMas()['utilisateurs'] as $u) {
		if ($u['username'] === $nom) {
			return $u;
		}
	}
	return null;
}
function appelsMas(): int {
	return count(journalFaux('mas.banc.test'));
}
// Un envoi du formulaire, avec le jeton de la session (lu avant : une page lue après le POST effacerait ce que l'envoi a dit) ; $jeton = null : sans jeton
function envoyer(array $champs, ?string $jeton = ''): array {
	$jeton = $jeton === '' ? jetonCsrfDeLaSession() : $jeton;
	return requetePortail(ADRESSE, $champs + ($jeton === null ? [] : ['csrf_token' => $jeton]));
}
// Vrai si ce texte se trouve dans un fichier de session du serveur : le mot de passe ne doit jamais y être
function dansLesSessions(string $texte): bool {
	foreach (array_unique(array_filter([ini_get('session.save_path'), '/tmp', '/var/lib/php/sessions', '/var/tmp'])) as $dossier) {
		$sortie = [];
		exec('grep -rlF '.escapeshellarg($texte).' '.escapeshellarg($dossier).' 2>/dev/null', $sortie);
		if ($sortie !== []) {
			return true;
		}
	}
	return false;
}

repartirAZero();
@unlink(ETAT_BANC.'/mas.json');
// Le compte admin parle à ce faux MAS (adresse, identifiant et secret du client, chiffrés comme à l'enregistrement)
$crypto = new Chiffrement();
$bd = connexionBD();
$requete = $bd->prepare("INSERT INTO sbchat (iduser, sbchatapitoken, sbchatcalendarurl, sbchatserveraddress, masurl, masclientid, massecret) VALUES (1, ?, '', 'https://sbchat.banc.test', 'https://mas.banc.test', ?, ?)");
$jetonChat = $crypto->encrypt('jeton-admin-banc');
$clientId = $crypto->encrypt('client-banc');
$secret = $crypto->encrypt('secret-banc');
$requete->bind_param('sss', $jetonChat, $clientId, $secret);
$requete->execute();
connecterAuPortail('admin');

verifie(str_contains(requetePortail(ADRESSE)['page'], 'name="localmasusername"'), "départ : la page offre le formulaire");

// --- Créer un utilisateur, avec un mot de passe et un courriel ---
$creation = ['localmasusername' => 'jean.dupont', 'masuserpassword' => MDP_CREATION, 'masuseremail' => 'jean@banc.test', 'masaction' => 'create'];
viderJournalFaux();
$sansJeton = envoyer($creation, null);
verifie($sansJeton['code'] === 403 && appelsMas() === 0 && utilisateurMas('jean.dupont') === null, "création sans jeton : refusée, MAS ne reçoit rien (code {$sansJeton['code']})");
$reponse = envoyer($creation);
verifie(redirigeVers($reponse, RETOUR), "création : redirection vers la page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
// Après le POST et avant la page qui suit : la session a gardé ce que l'envoi a dit, et pas le mot de passe
verifie(dansLesSessions('messages_enregistrement'), "création : (témoin) la session garde ce que l'envoi a dit pour la page qui suit");
verifie(!dansLesSessions(MDP_CREATION), "création : le mot de passe n'est dans aucun fichier de session");
$page = suivreRedirection($reponse)['page'];
verifie(str_contains($page, 'Invitation Element X pour @jean.dupont:mas.banc.test'), "création : la page qui suit montre l'invitation d'Element X");
verifie(str_contains($page, 'data-avec-mot-de-passe="') && str_contains($page, '@@MOT_DE_PASSE@@'), "création : l'invitation est prête à recevoir le mot de passe, que le navigateur remet à la marque");
verifie(!str_contains($page, MDP_CREATION), "création : le mot de passe n'est nulle part dans la page qui suit");
verifie(str_contains($page, "sb_mas_mot_de_passe"), "création : la page garde et efface le mot de passe du navigateur (sessionStorage)");
$jean = utilisateurMas('jean.dupont');
verifie($jean !== null && $jean['motdepasse'] === MDP_CREATION && $jean['admin'] === false, "création : MAS a reçu l'utilisateur et son mot de passe");
verifie(array_column(etatMas()['courriels'], 'email') === ['jean@banc.test'], "création : MAS a reçu le courriel");
$appels = appelsMas();
verifie(lectures(ADRESSE, 'Invitation Element X pour') === [0, 0], "création : le rechargement ne remontre pas l'invitation");
verifie(appelsMas() === $appels && count(etatMas()['utilisateurs']) === 1, "création : le rechargement n'appelle pas MAS et ne recrée rien (appels ".appelsMas().")");
verifie(!dansLesSessions(MDP_CREATION), "création : après la page qui suit, le mot de passe n'est toujours dans aucune session");

// --- Un nom invalide : l'erreur est dite une fois, le formulaire est remis sans le mot de passe ---
$appels = appelsMas();
$erreur = ['localmasusername' => 'Nom Invalide!', 'masuserpassword' => MDP_ERREUR, 'masuseremail' => 'x@banc.test', 'masaction' => 'modify'];
$reponse = envoyer($erreur);
verifie(redirigeVers($reponse, RETOUR) && !dansLesSessions(MDP_ERREUR), "nom invalide : redirection vers la page, le mot de passe n'est dans aucune session");
$page = suivreRedirection($reponse)['page'];
verifie(str_contains($page, "Nom d'utilisateur invalide") && str_contains($page, 'Aucune information enregistrée'), "nom invalide : la page qui suit dit l'erreur");
verifie(str_contains($page, 'value="nom invalide!"') && str_contains($page, 'value="x@banc.test"'), "nom invalide : le nom et le courriel sont remis dans le formulaire");
verifie(!str_contains($page, MDP_ERREUR) && preg_match('/name="masuserpassword" value=""/', $page) === 1, "nom invalide : le mot de passe n'est pas remis dans le formulaire");
verifie(preg_match('/value="modify" checked/', $page) === 1, "nom invalide : l'action choisie (modifier) reste cochée");
verifie(lectures(ADRESSE, "Nom d'utilisateur invalide") === [0, 0] && appelsMas() === $appels, "nom invalide : dit une fois seulement, et MAS n'est pas appelé");

// --- Créer un nom qui existe déjà : refusé, rien ne change ---
$reponse = envoyer(['localmasusername' => 'jean.dupont', 'masuserpassword' => MDP_ERREUR, 'masuseremail' => 'autre@banc.test', 'masaction' => 'create']);
$page = suivreRedirection($reponse)['page'];
verifie(str_contains($page, 'existe déjà'), "création d'un nom existant : la page qui suit le dit");
verifie(utilisateurMas('jean.dupont')['motdepasse'] === MDP_CREATION && array_column(etatMas()['courriels'], 'email') === ['jean@banc.test'], "création d'un nom existant : le mot de passe et le courriel de la personne ne changent pas");
verifie(lectures(ADRESSE, 'existe déjà') === [0, 0], "création d'un nom existant : dit une fois seulement");

// --- Modifier un utilisateur : nouveau mot de passe et droits d'administrateur ---
$modification = ['localmasusername' => 'jean.dupont', 'masuserpassword' => MDP_MODIFICATION, 'masuseremail' => 'jean@banc.test', 'masaction' => 'modify', 'masuseradmin' => '1'];
$reponse = envoyer($modification);
verifie(redirigeVers($reponse, RETOUR) && !dansLesSessions(MDP_MODIFICATION), "modification : redirection vers la page, le mot de passe n'est dans aucune session");
$page = suivreRedirection($reponse)['page'];
verifie(str_contains($page, 'Nouvel mot de passe enregistré') && str_contains($page, 'Invitation Element X pour @jean.dupont') && !str_contains($page, MDP_MODIFICATION), "modification : la page qui suit dit le résultat et montre l'invitation, sans le mot de passe");
$jean = utilisateurMas('jean.dupont');
verifie($jean['motdepasse'] === MDP_MODIFICATION && $jean['admin'] === true, "modification : MAS a reçu le nouveau mot de passe et les droits d'administrateur");
verifie(lectures(ADRESSE, 'Nouvel mot de passe enregistré') === [0, 0], "modification : dit une fois seulement");

// --- Refaire l'invitation sans changer le mot de passe : le message n'a pas de place pour un mot de passe ---
$reponse = envoyer(['localmasusername' => 'jean.dupont', 'masuserpassword' => '', 'masuseremail' => '', 'masaction' => 'modify']);
$page = suivreRedirection($reponse)['page'];
verifie(str_contains($page, 'Invitation Element X pour @jean.dupont') && !str_contains($page, 'data-avec-mot-de-passe="'), "refaire l'invitation : l'invitation s'affiche, sans mot de passe à remettre");
verifie(utilisateurMas('jean.dupont')['motdepasse'] === MDP_MODIFICATION, "refaire l'invitation : le mot de passe de la personne ne change pas");

// --- Créer un administrateur Matrix, sans courriel ---
$reponse = envoyer(['localmasusername' => 'admin.banc', 'masuserpassword' => 'Admin-Secret-4', 'masuseremail' => '', 'masaction' => 'create', 'masuseradmin' => '1']);
$page = suivreRedirection($reponse)['page'];
$admin = utilisateurMas('admin.banc');
verifie($admin !== null && $admin['admin'] === true && $admin['motdepasse'] === 'Admin-Secret-4', "administrateur Matrix : MAS a reçu l'utilisateur, son mot de passe et ses droits");
verifie(str_contains($page, 'Nouvel utilisateur et mot de passe enregistrés') && !str_contains($page, 'Admin-Secret-4') && !dansLesSessions('Admin-Secret-4'), "administrateur Matrix : le résultat est dit, et le mot de passe n'est ni dans la page ni dans une session");

@unlink(ETAT_BANC.'/mas.json');
oublierSession();
finDuScenario();
