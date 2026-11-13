#!/usr/bin/env php
<?php
// Les formulaires qui enregistrent (informations du compte, SwitchBoard Chat, services d'un comité) répondent par une
// redirection vers la même page en GET (Post/Redirect/Get, #77) : le bouton Précédent ne redemande plus de renvoyer le
// formulaire et F5 n'enregistre pas de nouveau. Ce que l'enregistrement a dit (erreurs de saisie) s'affiche une seule fois,
// sur la page qui suit. Un envoi sans jeton CSRF est refusé et n'enregistre rien.
require_once '/banc/commun/banc.php';

const AUTONOME = 2;
const CHAT = 6;

repartirAZero();

// --- Informations du compte : un envoi accepté redirige, et dit ses erreurs une fois ---
connecterAuPortail('autonome');
$adresse = PORTAIL.'?page=userinfo';
$champs = ['voipmsusername' => 'compte@banc.test', 'voipmsapipassword' => '', 'calendarurl' => 'https://agendas.banc.test/c/autonome.ics',
	'calendartype' => 'secreturl', 'calendarusername' => '', 'calendarpassword' => '', 'validationfeedbackemail' => 'autonome@banc.test',
	'ringgroupname' => 'Nouveau nom', 'didnumber' => '123', 'ringtime' => '30', 'pressone' => '0', 'language' => 'fr'];
$sansJeton = requetePortail($adresse, $champs);
verifie($sansJeton['code'] === 403, "informations du compte, sans jeton : refusé (code {$sansJeton['code']})");
verifie((ligneDeLaBd('SELECT ringgroupname FROM userinfo WHERE iduser = '.AUTONOME)['ringgroupname'] ?? '') !== 'Nouveau nom', "informations du compte, sans jeton : rien n'est enregistré");

$reponse = requetePortail($adresse, $champs + ['csrf_token' => jetonDuFormulaire($adresse)]);
verifie($reponse['code'] === 303 && enTete($reponse, 'Location') === 'managementportal.php?page=userinfo', "informations du compte : la réponse est une redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(trim(corps($reponse)) === '', "informations du compte : la réponse n'affiche aucune page");
$enregistre = ligneDeLaBd('SELECT ringgroupname, ringtime, pressone, didnumber, voipmsusername FROM userinfo WHERE iduser = '.AUTONOME);
verifie($enregistre['ringgroupname'] === 'Nouveau nom' && (int)$enregistre['ringtime'] === 30 && (string)$enregistre['pressone'] === '0' && $enregistre['voipmsusername'] === 'compte@banc.test', "informations du compte : les valeurs sont enregistrées");
verifie($enregistre['didnumber'] === '', "informations du compte : un DID invalide est vidé, comme avant");
$page = requetePortail($adresse);
verifie($page['code'] === 200 && substr_count($page['page'], 'DID non valide') === 1, "informations du compte : la page qui suit dit l'erreur de saisie, une fois");
verifie(str_contains($page['page'], 'value="Nouveau nom"'), "informations du compte : la page qui suit montre les valeurs enregistrées");
$page = requetePortail($adresse);
verifie($page['code'] === 200 && !str_contains($page['page'], 'DID non valide'), "informations du compte : un rechargement ne répète pas l'erreur");

// --- Informations d'un comité, par un admin : la redirection garde l'iduser ---
connecterAuPortail('admin');
$adresseAdmin = PORTAIL.'?page=userinfo&iduser='.AUTONOME;
$reponse = requetePortail($adresseAdmin, array_merge($champs, ['ringgroupname' => 'Par admin', 'didnumber' => '514-555-0101', 'csrf_token' => jetonDuFormulaire($adresseAdmin)]));
verifie($reponse['code'] === 303 && enTete($reponse, 'Location') === 'managementportal.php?page=userinfo&iduser='.AUTONOME, "admin, informations d'un comité : redirigé vers la même page, avec l'iduser (".enTete($reponse, 'Location').")");
$enregistre = ligneDeLaBd('SELECT ringgroupname, didnumber FROM userinfo WHERE iduser = '.AUTONOME);
verifie($enregistre['ringgroupname'] === 'Par admin' && $enregistre['didnumber'] === '5145550101', "admin, informations d'un comité : enregistrées pour ce comité");

// --- SwitchBoard Chat : une erreur de saisie, puis un envoi accepté ---
connecterAuPortail('chat');
$adresse = PORTAIL.'?page=sbchatinfo';
$champsChat = ['sbchatserveraddress' => 'https://sbchat2.banc.test/', 'sbchatcalendarurl' => 'pas une adresse', 'masurl' => '',
	'sbchatapitoken' => '', 'masclientid' => '', 'massecret' => ''];
$sansJeton = requetePortail($adresse, $champsChat);
verifie($sansJeton['code'] === 403, "SwitchBoard Chat, sans jeton : refusé (code {$sansJeton['code']})");
$reponse = requetePortail($adresse, $champsChat + ['csrf_token' => jetonDuFormulaire($adresse)]);
verifie($reponse['code'] === 303 && enTete($reponse, 'Location') === 'managementportal.php?page=sbchatinfo', "SwitchBoard Chat : redirigé vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
$enregistre = ligneDeLaBd('SELECT sbchatserveraddress, sbchatcalendarurl FROM sbchat WHERE iduser = '.CHAT);
verifie($enregistre['sbchatserveraddress'] === 'https://sbchat2.banc.test' && $enregistre['sbchatcalendarurl'] === '', "SwitchBoard Chat : l'adresse du serveur est enregistrée sans son / final, l'agenda invalide est vidé");
$page = requetePortail($adresse);
verifie(substr_count($page['page'], "URL de l'Agenda") === 1 || substr_count($page['page'], "URL de l&#039;Agenda") === 1 || substr_count($page['page'], 'Adresse URL non valide pour URL de l') === 1, "SwitchBoard Chat : la page qui suit dit l'erreur de saisie, une fois");
verifie(!str_contains(requetePortail($adresse)['page'], 'Adresse URL non valide pour URL de l'), "SwitchBoard Chat : un rechargement ne répète pas l'erreur");

// --- Services d'un comité (admin) ---
connecterAuPortail('admin');
$adresse = PORTAIL.'?page=userservice&iduser='.AUTONOME;
$champsServices = ['haschanged' => '1', 'ringgroupupdate' => '1', 'sendstats' => '1'];
$sansJeton = requetePortail($adresse, $champsServices);
verifie($sansJeton['code'] === 403, "services, sans jeton : refusé (code {$sansJeton['code']})");
$reponse = requetePortail($adresse, $champsServices + ['csrf_token' => jetonDuFormulaire($adresse)]);
verifie($reponse['code'] === 303 && enTete($reponse, 'Location') === 'managementportal.php?page=userservice&iduser='.AUTONOME, "services : redirigé vers la même page, avec l'iduser (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
$services = ligneDeLaBd('SELECT ringgroupupdate, smsdaybefore, smshourbefore, sendstats, sbchatupdate, mainaccount FROM userservice WHERE iduser = '.AUTONOME);
verifie($services === ['ringgroupupdate' => '1', 'smsdaybefore' => '0', 'smshourbefore' => '0', 'sendstats' => '1', 'sbchatupdate' => '0', 'mainaccount' => '0'], "services : seuls les services cochés sont actifs (".json_encode($services).")");
$page = requetePortail($adresse);
verifie($page['code'] === 200 && str_contains($page['page'], 'checked'), "services : la page qui suit s'affiche avec les cases cochées");

finDuScenario();
