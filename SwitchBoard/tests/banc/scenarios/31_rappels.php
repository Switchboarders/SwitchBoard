#!/usr/bin/env php
<?php
// Les rappels par SMS et par chat : les vrais crons, avec leurs vraies lignes. Un rappel part vers un
// répondant marqué dans l'agenda : * la veille, ! dans l'heure qui précède.
require_once '/banc/commun/banc.php';

const JEAN = '5145550111';
const MARIE = '5145550112';
const DID_AUTONOME = '5145550101';

function smsEnvoyes(): array {
	$sms = array_filter(journalFaux('voip.ms'), fn($appel) => ($appel['params']['method'] ?? '') === 'sendSMS');
	return array_values(array_map(fn($appel) => $appel['params'], $sms));
}
function rappel(string $motifCron): array {
	viderJournalFaux();
	return lancerCron($motifCron);
}
function quart(string $nom, string $telephone, int $debut, int $fin): array {
	return ['nom' => $nom, 'description' => $telephone, 'debut' => $debut, 'fin' => $fin];
}

$midiDemain = strtotime('tomorrow noon');
$veille = 'sendnotificationsmss.php "daybefore"';
$heureAvant = 'sendnotificationsmss.php "hourbefore"';

// --- SMS la veille ---
repartirAZero();
ecrireAgenda('autonome', [
	quart('*Jean', '514-555-0111', $midiDemain, $midiDemain + 3600),
	quart('Marie', '514-555-0112', $midiDemain, $midiDemain + 3600),
]);
rappel($veille);
$sms = smsEnvoyes();
verifie(count($sms) === 1 && $sms[0]['dst'] === JEAN, "SMS la veille : seul le répondant marqué d'une astérisque est texté");
verifie(($sms[0]['did'] ?? '') === DID_AUTONOME, "SMS la veille : le texto part du DID du comité");
$attendu = 'Jean vous êtes cédulé demain le '.date('d', $midiDemain).' : '.date('H:i', $midiDemain).' à '.date('H:i', $midiDemain + 3600);
verifie(($sms[0]['message'] ?? '') === $attendu, "SMS la veille : le bon texte (".($sms[0]['message'] ?? '').")");

// Deux quarts de la même personne : un seul texto, qui donne les deux plages
ecrireAgenda('autonome', [
	quart('*Jean', '514-555-0111', $midiDemain, $midiDemain + 3600),
	quart('*Jean', '514-555-0111', $midiDemain + 7200, $midiDemain + 10800),
]);
rappel($veille);
$sms = smsEnvoyes();
verifie(count($sms) === 1 && str_contains($sms[0]['message'], date('H:i', $midiDemain)) && str_contains($sms[0]['message'], date('H:i', $midiDemain + 7200)), "SMS la veille : deux quarts donnent un seul texto avec les deux plages");

// Personne demain, et personne marquée
ecrireAgenda('autonome', [quart('Jean', '514-555-0111', $midiDemain, $midiDemain + 3600)]);
rappel($veille);
verifie(smsEnvoyes() === [], "SMS la veille : aucun texto sans astérisque");
ecrireAgenda('autonome', [quart('*Jean', '514-555-0111', $midiDemain + 86400 * 2, $midiDemain + 86400 * 2 + 3600)]);
rappel($veille);
verifie(smsEnvoyes() === [], "SMS la veille : aucun texto pour un quart d'après-demain");

// Service inactif : le comité membre n'a pas ce service
ecrireAgenda('membre1', [quart('*Marie', '514-555-0112', $midiDemain, $midiDemain + 3600)]);
rappel($veille);
verifie(array_filter(smsEnvoyes(), fn($p) => $p['dst'] === MARIE) === [], "SMS la veille : rien pour un comité sans ce service");

// --- SMS dans l'heure qui précède ---
$heureProchaine = (int)(date('i') === '00' ? strtotime(date('Y-m-d H:00:00')) : strtotime(date('Y-m-d H:00:00')) + 3600);
ecrireAgenda('autonome', [
	quart('!Jean', '514-555-0111', $heureProchaine, $heureProchaine + 3600),
	quart('Marie', '514-555-0112', $heureProchaine, $heureProchaine + 3600),
]);
rappel($heureAvant);
$sms = smsEnvoyes();
verifie(count($sms) === 1 && $sms[0]['dst'] === JEAN, "SMS dans l'heure : seul le répondant marqué d'un point d'exclamation est texté");
verifie(str_contains($sms[0]['message'] ?? '', 'dans la prochaine heure de '.date('H:i', $heureProchaine)), "SMS dans l'heure : le bon texte (".($sms[0]['message'] ?? '').")");

// Quart de toute la journée : aucun texto à l'heure
$minuit = strtotime('tomorrow');
ecrireAgenda('autonome', [quart('!Jean', '514-555-0111', $heureProchaine, $heureProchaine + 86400)]);
rappel($heureAvant);
verifie(smsEnvoyes() === [], "SMS dans l'heure : aucun texto pour un quart de 24 h");

// --- Pas de double envoi quand voip.ms répond mal ---
ecrireAgenda('autonome', [quart('*Jean', '514-555-0111', $midiDemain, $midiDemain + 3600)]);
// Les 3 lectures de l'agenda n'appellent pas voip.ms : le premier appel du cron est le sendSMS
simulerPanne('voip.ms', [502]);
rappel($veille);
verifie(count(smsEnvoyes()) === 1, "SMS et panne 502 : voip.ms a peut-être reçu la demande, elle n'est pas reprise (".count(smsEnvoyes())." demande)");
simulerPanne('voip.ms', [522]);
rappel($veille);
verifie(count(smsEnvoyes()) === 2, "SMS et panne 522 : la demande n'est pas arrivée à voip.ms, elle est reprise (".count(smsEnvoyes())." demandes)");

// --- Agenda illisible : aucun rappel ---
array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*.ical'));
unlink(ETAT_BANC.'/agenda-autonome.ics');
$cron = rappel($veille);
verifie(smsEnvoyes() === [], "agenda illisible : aucun SMS");
verifie($cron['code'] === 0, "agenda illisible : le cron finit normalement");

// --- Rappels par chat ---
$commandesChat = fn() => array_map(fn($appel) => json_decode($appel['corps'], true), journalFaux('sbchat.banc.test'));
repartirAZero();
ecrireAgenda('chat-sbchat', [quart('@Lita', '', $midiDemain, $midiDemain + 3600)]);
rappel('sendnotificationsbchat.php "daybefore"');
$commandes = $commandesChat();
verifie(count($commandes) === 1 && $commandes[0]['command'] === 'send_message' && $commandes[0]['respondent'] === ['@lita'], "chat la veille : le message part vers le répondant, en minuscules");
verifie(str_contains($commandes[0]['message'] ?? '', date('H:i', $midiDemain)), "chat la veille : le message donne l'heure (".($commandes[0]['message'] ?? '').")");

ecrireAgenda('chat-sbchat', [quart('pas un nom matrix', '', $midiDemain, $midiDemain + 3600)]);
rappel('sendnotificationsbchat.php "daybefore"');
verifie($commandesChat() === [], "chat la veille : aucun message pour un nom invalide");

$quartChat = quart('@lita', '', time() - 3600, time() + 3600);
ecrireAgenda('chat-sbchat', [$quartChat]);
rappel('refreshsbchat.php');
$commandes = $commandesChat();
verifie(($commandes[0]['command'] ?? '') === 'change_respondents' && ($commandes[0]['respondent'] ?? []) === ['@lita'], "chat : les répondants de garde changent");

array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*.ical'));
unlink(ETAT_BANC.'/agenda-chat-sbchat.ics');
rappel('refreshsbchat.php');
verifie($commandesChat() === [], "chat, agenda illisible : les répondants restent");

finDuScenario();
