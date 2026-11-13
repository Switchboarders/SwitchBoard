<?php
// Test unitaire (pas de framework dans ce dépôt - script CLI simple, comme le reste du code)
// pour la reprise automatique de ClientVoipms::appelerAPI(), contre le serveur factice
// stubvoipmsserver.php. Aucun secret réel, aucun réseau au-delà de 127.0.0.1.
// Prend une minute : chaque reprise attend 3 s, comme en production.
//
// Usage : php -S 127.0.0.1:8998 stubvoipmsserver.php & puis php voipmsreprise_test.php

// Version minimale de la classe dont ClientVoipms a besoin, pour ne charger ni la BD ni l'envoi de courriels.
class InformationsUtilisateur { public string $voipmsusername = ""; public string $voipmsapipassword = ""; public $iduser = 0; public $username = ""; }
// L'amorce déclare l'autoload de src/modules/ (Journal, pour les erreurs au journal)
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';

require_once __DIR__.'/verifie.php';

// Appelle appelerAPI() (privée) sur une adresse donnée, et retourne le résultat et le
// nombre de requêtes envoyées.
function appelerApi(string $url, string $methode) {
	$voipms = new ClientVoipms(new InformationsUtilisateur());
	$appel = new ReflectionMethod(ClientVoipms::class, 'appelerAPI');
	$appel->setAccessible(true);
	$resultat = $appel->invoke($voipms, curl_init($url), $methode, null);
	return [$resultat, $voipms->nbRequetes()];
}

$serveur = 'http://127.0.0.1:8998';

// --- Cas 1 : un 522 passager est repris, et la 2e tentative réussit ----------------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas1/522,200", "getForwardings");
verifie(is_array($resultat) && $resultat['status'] === "success", "Cas 1: après un 522, la reprise réussit");
verifie($nbRequetes === 2, "Cas 1: 2 requêtes envoyées ($nbRequetes)");

// --- Cas 2 : un 500 n'est pas repris (erreur durable, ex.: requête mal formée) ------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas2/500,200", "getForwardings");
verifie(is_string($resultat), "Cas 2: un 500 est rapporté comme une erreur");
verifie($nbRequetes === 1, "Cas 2: 1 seule requête, pas de reprise ($nbRequetes)");

// --- Cas 3 : un 504 qui persiste est repris jusqu'au maximum de 3 tentatives --------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas3/504", "setForwarding");
verifie(is_string($resultat), "Cas 3: après 3 échecs, une erreur est rapportée");
verifie($nbRequetes === 3, "Cas 3: 3 requêtes envoyées ($nbRequetes)");

// --- Cas 4 : sendSMS n'est pas repris sur un 504 (voip.ms a peut-être envoyé le texto) ----
[$resultat, $nbRequetes] = appelerApi("$serveur/cas4/504,200", "sendSMS");
verifie(is_string($resultat), "Cas 4: sendSMS en 504 est rapporté comme une erreur");
verifie($nbRequetes === 1, "Cas 4: 1 seule requête, pas de texto en double ($nbRequetes)");

// --- Cas 5 : sendSMS est repris sur un 522 (la demande n'a pas atteint voip.ms) -----------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas5/522,200", "sendSMS");
verifie(is_array($resultat), "Cas 5: sendSMS en 522 est repris et réussit");
verifie($nbRequetes === 2, "Cas 5: 2 requêtes envoyées ($nbRequetes)");

// --- Cas 6 : sendSMS est repris si la connexion est impossible (rien n'a été envoyé) ------
// Le port 8997 n'a aucun serveur : la connexion est refusée tout de suite.
[$resultat, $nbRequetes] = appelerApi("http://127.0.0.1:8997/cas6", "sendSMS");
verifie(is_string($resultat), "Cas 6: une connexion impossible est rapportée comme une erreur");
verifie($nbRequetes === 3, "Cas 6: 3 tentatives de connexion ($nbRequetes)");

// --- Cas 7 : plus de reprise quand le script tourne depuis plus de 6 minutes ---------------
// On fait croire que le script a démarré il y a 7 minutes.
$debutReel = $_SERVER['REQUEST_TIME_FLOAT'];
$_SERVER['REQUEST_TIME_FLOAT'] = microtime(true) - 7 * 60;
[$resultat, $nbRequetes] = appelerApi("$serveur/cas7/522,200", "getForwardings");
$_SERVER['REQUEST_TIME_FLOAT'] = $debutReel;
verifie(is_string($resultat), "Cas 7: après 6 minutes, un 522 est rapporté comme une erreur");
verifie($nbRequetes === 1, "Cas 7: 1 seule requête, pas de reprise ($nbRequetes)");

// --- Cas 8 : un 403 passager (vu sur le serveur pilote, 2026-11-06) est repris ------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas8/403,200", "setForwarding");
verifie(is_array($resultat) && $resultat['status'] === "success", "Cas 8: après un 403, la reprise de setForwarding réussit");
verifie($nbRequetes === 2, "Cas 8: 2 requêtes envoyées ($nbRequetes)");

// --- Cas 9 : sendSMS n'est pas repris sur un 403 (voip.ms a peut-être envoyé le texto) -----
[$resultat, $nbRequetes] = appelerApi("$serveur/cas9/403,200", "sendSMS");
verifie(is_string($resultat), "Cas 9: sendSMS en 403 est rapporté comme une erreur");
verifie($nbRequetes === 1, "Cas 9: 1 seule requête, pas de texto en double ($nbRequetes)");

// --- Cas 10 : un 403 qui persiste est repris jusqu'au maximum de 3 tentatives --------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas10/403", "setForwarding");
verifie(is_string($resultat), "Cas 10: après 3 refus, une erreur est rapportée");
verifie($nbRequetes === 3, "Cas 10: 3 requêtes envoyées ($nbRequetes)");

// --- Cas 11 : un 525 (Cloudflare n'a pas pu négocier TLS avec voip.ms) est repris, même pour sendSMS ---
[$resultat, $nbRequetes] = appelerApi("$serveur/cas11/525,200", "getForwardings");
verifie(is_array($resultat) && $resultat['status'] === "success", "Cas 11: après un 525, la reprise réussit");
verifie($nbRequetes === 2, "Cas 11: 2 requêtes envoyées ($nbRequetes)");
[$resultat, $nbRequetes] = appelerApi("$serveur/cas12/525,200", "sendSMS");
verifie(is_array($resultat), "Cas 12: sendSMS en 525 est repris : la demande n'est pas arrivée chez voip.ms");
verifie($nbRequetes === 2, "Cas 12: 2 requêtes envoyées ($nbRequetes)");

// --- Cas 13 : un 525 qui persiste est repris jusqu'au maximum de 3 tentatives --------------
[$resultat, $nbRequetes] = appelerApi("$serveur/cas13/525", "getForwardings");
verifie(is_string($resultat), "Cas 13: après 3 échecs, une erreur est rapportée");
verifie($nbRequetes === 3, "Cas 13: 3 requêtes envoyées ($nbRequetes)");

foreach (glob(__DIR__.'/stub-voipms-*.compteur') as $fichier) {
	@unlink($fichier);
}

echo "\n".($echecs === 0 ? "TOUS LES TESTS ONT RÉUSSI" : "$echecs test(s) ONT ÉCHOUÉ")."\n";
exit($echecs === 0 ? 0 : 1);
