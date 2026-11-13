#!/usr/bin/env php
<?php
// Les règles d'alerte de CONVENTIONS.md section 6 : ce qui va au journal, ce qui part en courriel, et le signal de vie.
// La panne de voip.ms pour tous les comités est dans 30_sonnerie.php.
require_once '/banc/commun/banc.php';

const AUTONOME = 2;
const MEMBRE1 = 4;
const CHAT = 6;
const ADMIN = 'admin@banc.test';
const COMITE_AUTONOME = 'autonome@banc.test';
const COMITE_CHAT = 'chat@banc.test';
const MARIE = '5145550112';

function passage(): array {
	viderJournalFaux();
	return lancerCron('refreshallringgroups.php');
}
function quartEnCours(string $nom, string $description): array {
	return ['nom' => $nom, 'description' => $description, 'debut' => time() - 3600, 'fin' => time() + 3600];
}
// Deux comités de garde, sans aucun problème
function preparer(): void {
	repartirAZero();
	sonnerieSeulementPour([AUTONOME, MEMBRE1]);
	ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111')]);
	ecrireAgenda('membre1', [quartEnCours('Marie', '514-555-0112')]);
}
function changerInfo(int $iduser, string $colonne, string $valeur): void {
	$bd = connexionBD();
	$requete = $bd->prepare("UPDATE userinfo SET $colonne = ? WHERE iduser = ?");
	$requete->bind_param('si', $valeur, $iduser);
	$requete->execute();
}
// voip.ms refuse ce compte : le mot de passe n'est pas celui du nom d'utilisateur
function refuserMotDePasse(int $iduser): void {
	changerInfo($iduser, 'voipmsusername', 'banc-refuse');
}
function reparerMotDePasse(int $iduser): void {
	changerInfo($iduser, 'voipmsusername', 'banc-autonome');
}
function pannesCourriel(int $fois): void {
	file_put_contents(ETAT_BANC.'/panne-msmtp-fois', "$fois\n");
}
function journalDe(string $motif): string {
	$fichiers = glob(DOSSIER_JOURNAUX.$motif);
	return $fichiers === [] ? '' : (string)file_get_contents($fichiers[0]);
}
function signalBon(): bool {
	return pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'];
}
function corpsDuPing(string $check): string {
	$pings = array_filter(journalFaux('hc-ping.com'), fn($ping) => str_starts_with($ping['chemin'], "/banc-$check"));
	return (string)(array_values($pings)[0]['corps'] ?? '');
}
function combienDeLignes(string $texte, string $motif): int {
	return substr_count($texte, $motif);
}

// --- Les vraies attentes, sans le raccourci du banc ---
exec("php -d auto_prepend_file= -r ".escapeshellarg('require "'.SRC_DIRECTORY.'commun/lesconstantes.php"; echo DELAI_SECOND_ESSAI_COURRIEL, " ", DELAI_SECOND_ESSAI_CANARI;'), $sortie);
verifie(($sortie[0] ?? '') === '5 30', "en production : second essai après 5 s pour un courriel, 30 s pour le canari (".($sortie[0] ?? '').")");

// --- Un seul comité en échec à cause de voip.ms : rien de 1 à 5 passages, un courriel au 6e, rien au 7e ---
preparer();
refuserMotDePasse(AUTONOME);
for ($passage = 1; $passage <= 5; $passage++) {
	passage();
	verifie(courriels() === [], "un comité refusé par voip.ms, passage $passage : aucun courriel");
	verifie(signalBon(), "un comité refusé par voip.ms, passage $passage : le signal de vie reste bon");
}
verifie(str_contains(corpsDuPing('ringgroup'), 'en échec=1'), "un comité refusé par voip.ms : le corps du ping donne le nombre de comités en échec (".corpsDuPing('ringgroup').")");
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [MARIE]], "un comité refusé par voip.ms : l'autre comité est à jour");
$journal = journalDe('*id2ringgroupupdatelog.txt');
verifie(str_contains($journal, 'invalid_credentials') && str_contains($journal, 'Groupe de sonnerie non mis à jour'), "un comité refusé par voip.ms : la cause est au journal du comité");
verifie(!str_contains($journal, 'api_password') && !str_contains($journal, 'rest.php'), "un comité refusé par voip.ms : ni l'URL ni le mot de passe au journal");
passage();
verifie(count(courriels()) === 1 && count(courrielsA(ADMIN)) === 1, "un comité refusé par voip.ms, passage 6 : un courriel, à l'admin (".count(courriels()).")");
verifie(str_contains(courriels()[0] ?? '', 'autonome') && str_contains(courriels()[0] ?? '', 'Groupe de sonnerie en échec'), "un comité refusé par voip.ms, passage 6 : le courriel nomme le comité et le problème");
verifie(signalBon(), "un comité refusé par voip.ms, passage 6 : le signal de vie reste bon");
oublierCourriels();
passage();
verifie(courriels() === [], "un comité refusé par voip.ms, passage 7 : aucun courriel, moins de 24 heures après");
reculerDernierEnvoi(AUTONOME, ['voipms']);
passage();
verifie(count(courrielsA(ADMIN)) === 1, "un comité refusé par voip.ms, 24 heures plus tard : un autre courriel");

// --- Un comité qui échoue de façon intermittente : le compteur repart ---
preparer();
refuserMotDePasse(AUTONOME);
for ($passage = 1; $passage <= 5; $passage++) {
	passage();
}
reparerMotDePasse(AUTONOME);
passage();
verifie(alerteComite(AUTONOME, 'voipms')['passagesdesuite'] === 0, "échec intermittent : un passage réussi remet le compteur à zéro");
refuserMotDePasse(AUTONOME);
for ($passage = 1; $passage <= 5; $passage++) {
	passage();
}
verifie(alerteComite(AUTONOME, 'voipms')['passagesdesuite'] === 5 && courriels() === [], "échec intermittent : le compteur repart de zéro, aucun courriel");

// Un agenda illisible ne dit rien de voip.ms : le compteur ne bouge pas
array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*id2.ical'));
changerInfo(AUTONOME, 'calendarurl', '');
passage();
verifie(alerteComite(AUTONOME, 'voipms')['passagesdesuite'] === 5, "agenda illisible : le compteur de voip.ms ne bouge pas");

// --- Personne de garde : voip.ms refuse le groupe vide, et ce n'est pas un échec ---
preparer();
$quartPlusTard = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => time() + 6 * 3600, 'fin' => time() + 8 * 3600];
ecrireAgenda('autonome', [$quartPlusTard('Jean', '514-555-0111')]);
ecrireAgenda('membre1', [$quartPlusTard('Marie', '514-555-0112')]);
for ($passage = 1; $passage <= 7; $passage++) {
	passage();
	verifie(signalBon(), "personne de garde, passage $passage : le signal de vie reste bon");
}
verifie(courriels() === [] && (alerteComite(AUTONOME, 'voipms')['passagesdesuite'] ?? 0) === 0, "personne de garde : aucun courriel, aucun échec compté");
verifie((alerteComite(0, 'tousenechec')['passagesdesuite'] ?? 0) === 0, "personne de garde : le compteur de tous les comités ne bouge pas");

// --- Un résultat partiel n'est pas un échec : le groupe est accepté, un renvoi manque ---
preparer();
ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111'), quartEnCours('Marie', '514-555-0112')]);
// Lit les renvois (reçu), crée celui de Jean (panne), crée celui de Marie (reçu)
simulerPanne('voip.ms', [200, 500]);
passage();
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => [MARIE]], "résultat partiel : le groupe est accepté avec un seul répondant");
verifie((alerteComite(AUTONOME, 'voipms')['passagesdesuite'] ?? 0) === 0 && str_contains(corpsDuPing('ringgroup'), 'en échec=0'), "résultat partiel : ce n'est pas un échec");

// --- « Forcer » depuis le portail ne touche ni aux compteurs ni au signal de vie ---
preparer();
refuserMotDePasse(AUTONOME);
viderJournalFaux();
connecterAuPortail('autonome');
$page = ouvrirPage('refreshringgroup')['page'];
verifie(str_contains($page, 'Le résultat de l') && str_contains($page, 'voip.ms refuse le nom d') && str_contains($page, 'mot de passe API'), "Forcer : la page affiche le résultat, avec la vraie cause (identifiants refusés)");
verifie(alerteComite(AUTONOME, 'voipms') === null && alerteComite(0, 'tousenechec') === null, "Forcer : aucun compteur ne bouge");
verifie(pingsSignalDeVie('ringgroup') === [] && courriels() === [], "Forcer : ni signal de vie, ni courriel");
verifie(str_contains(journalDe('*id2ringgroupupdatelog.txt'), 'invalid_credentials'), "Forcer : l'erreur est quand même au journal du comité");

// --- Exception pour un comité : un courriel à l'admin par 24 heures, dans la langue de l'admin ---
preparer();
changerInfo(AUTONOME, 'calendarurl', 'https://agendas.banc.test/dateinvalide');
changerInfo(1, 'language', 'en');
$cron = passage();
verifie(str_contains($cron['sortie'], 'iduser='.AUTONOME), "exception pour un comité : l'erreur reste dans la sortie du cron");
verifie(signalBon(), "exception pour un comité : le signal de vie reste bon");
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [MARIE]], "exception pour un comité : le comité suivant est traité");
verifie(count(courriels()) === 1 && count(courrielsA(ADMIN)) === 1, "exception pour un comité : un courriel à l'admin (".count(courriels()).")");
$courriel = courriels()[0] ?? '';
verifie(str_contains($courriel, 'refreshallringgroups') && str_contains($courriel, 'autonome'), "exception pour un comité : le courriel dit le cron et le comité");
verifie(str_contains($courriel, 'Error in a cron'), "exception pour un comité : le courriel est dans la langue de l'admin, pas celle du comité");
oublierCourriels();
passage();
verifie(courriels() === [], "exception pour un comité : rien au passage suivant");
reculerDernierEnvoi(AUTONOME, ['exception-refreshallringgroups']);
passage();
verifie(count(courrielsA(ADMIN)) === 1, "exception pour un comité : un autre courriel 24 heures plus tard");

// Ce que le message d'une exception peut citer ne sort jamais dans le courriel
oublierCourriels();
$bilan = new BilanPassage('essai');
$bilan->noterException(AUTONOME, 'échec https://voip.ms/api/v1/rest.php?api_username=x&api_password=secret123 et api_password=secret456 fin');
$bd = new DatabaseManager();
ob_start();
$bilan->envoyerCourriels(new MemoireAlertes($bd), new Courriel(ADMIN));
ob_end_clean();
verifie(count(courriels()) === 1 && !str_contains(courriels()[0], 'secret') && !str_contains(courriels()[0], 'voip.ms/api'), "exception : ni URL ni secret dans le courriel");

// --- Exception pour tous : échec tout de suite, aucun courriel par comité ---
preparer();
changerInfo(AUTONOME, 'calendarurl', 'https://agendas.banc.test/dateinvalide');
changerInfo(MEMBRE1, 'calendarurl', 'https://agendas.banc.test/dateinvalide');
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "exception pour tous : le signal de vie avertit tout de suite");
verifie(courriels() === [], "exception pour tous : aucun courriel par comité");

// Sans signal de vie configuré, le courriel est le seul avertissement
viderJournalFaux();
exec("su -s /bin/sh www-data -c 'SB_DMS_RINGGROUP_SUCCESS_URL= php -q ".SRC_DIRECTORY."refreshallringgroups.php' >/dev/null 2>&1");
verifie(pingsSignalDeVie('ringgroup') === [] && count(courrielsA(ADMIN)) === 2, "exception pour tous, sans signal de vie configuré : les courriels à l'admin partent (".count(courrielsA(ADMIN)).")");

// --- Le cron des répondants de SBChat : même règle, avec son propre signal de vie ---
preparer();
$bd = connexionBD();
$bd->query("UPDATE userservice SET sbchatupdate = 1 WHERE iduser = ".AUTONOME);
$jeton = (new Chiffrement())->encrypt('jeton-banc-autonome');
$requete = $bd->prepare('INSERT INTO sbchat (iduser, sbchatapitoken, sbchatcalendarurl, sbchatdidnumber, sbchatserveraddress) VALUES (?, ?, "https://agendas.banc.test/dateinvalide", "5145550101", "https://sbchat.banc.test")');
$iduserAutonome = AUTONOME;
$requete->bind_param('is', $iduserAutonome, $jeton);
$requete->execute();
ecrireAgenda('chat-sbchat', [quartEnCours('@lita', '')]);
viderJournalFaux();
lancerCron('refreshsbchat.php');
verifie(pingsSignalDeVie('sbchat') === ['/banc-sbchat'], "SBChat, exception pour un comité : le signal de vie reste bon");
verifie(count(courrielsA(ADMIN)) === 1 && str_contains(courrielsA(ADMIN)[0], 'refreshsbchat'), "SBChat, exception pour un comité : un courriel à l'admin");
oublierCourriels();
lancerCron('refreshsbchat.php');
verifie(courriels() === [], "SBChat, exception pour un comité : rien au passage suivant");
$bd->query('UPDATE sbchat SET sbchatcalendarurl = "https://agendas.banc.test/dateinvalide"');
reculerDernierEnvoi(AUTONOME, ['exception-refreshsbchat']);
viderJournalFaux();
lancerCron('refreshsbchat.php');
verifie(pingsSignalDeVie('sbchat') === ['/banc-sbchat/fail'], "SBChat, exception pour tous : le signal de vie avertit");
verifie(courriels() === [], "SBChat, exception pour tous : aucun courriel par comité");

// --- Les crons de rappels n'ont pas de signal de vie : leur courriel limité part même si tous échouent ---
$veilleSms = 'sendnotificationsmss.php "daybefore"';
$veilleChat = 'sendnotificationsbchat.php "daybefore"';
preparer();
changerInfo(AUTONOME, 'calendarurl', 'https://agendas.banc.test/dateinvalide');
lancerCron($veilleSms);
verifie(count(courriels()) === 1 && count(courrielsA(ADMIN)) === 1 && str_contains(courrielsA(ADMIN)[0], 'sendnotificationsmss'), "rappels SMS, exception pour tous : un courriel à l'admin");
oublierCourriels();
lancerCron($veilleSms);
verifie(courriels() === [], "rappels SMS, exception : rien au passage suivant");
connexionBD()->query('UPDATE sbchat SET sbchatcalendarurl = "https://agendas.banc.test/dateinvalide"');
lancerCron($veilleChat);
verifie(count(courrielsA(ADMIN)) === 1 && str_contains(courrielsA(ADMIN)[0], 'sendnotificationsbchat'), "rappels SBChat, exception pour tous : un courriel à l'admin, propre à ce cron");
oublierCourriels();
lancerCron($veilleChat);
verifie(courriels() === [], "rappels SBChat, exception : rien au passage suivant");

// --- Un rappel SMS raté : au journal du rappel, sans aucun courriel ---
preparer();
$midiDemain = strtotime('tomorrow noon');
ecrireAgenda('autonome', [['nom' => '*Jean', 'description' => '514-555-0111', 'debut' => $midiDemain, 'fin' => $midiDemain + 3600]]);
simulerPanne('voip.ms', array_fill(0, 5, 500));
lancerCron($veilleSms);
verifie(courriels() === [], "rappel SMS raté : aucun courriel");
$journal = journalDe('*id2remindersmsdaybeforelog.txt');
verifie(str_contains($journal, 'voipmsmanager-sendSMS') && str_contains($journal, 'Erreur HTTP : 500'), "rappel SMS raté : l'erreur est au journal du rappel");
@unlink(ETAT_BANC.'/panne-voip.ms');

// --- Agenda non téléchargé : le comité et l'admin, au plus une fois par 24 heures, et le journal à chaque passage ---
preparer();
changerInfo(AUTONOME, 'calendarurl', 'https://agendas.banc.test/c/absent.ics');
passage();
verifie(count(courrielsA(COMITE_AUTONOME)) === 1 && count(courrielsA(ADMIN)) === 1, "agenda non téléchargé : un courriel au comité, un à l'admin");
oublierCourriels();
$cron = passage();
verifie(courriels() === [], "agenda non téléchargé, passage suivant : aucun courriel");
verifie(str_contains($cron['sortie'], "Échec du téléchargement de l'agenda"), "agenda non téléchargé, passage suivant : la trace reste à l'écran");
verifie(combienDeLignes(journalDe('*id2ringgroupupdatelog.txt'), "Échec du téléchargement de l'agenda") === 2, "agenda non téléchargé : la trace reste au journal à chaque passage");
reculerDernierEnvoi(AUTONOME, ['agenda', 'agendaadmin']);
passage();
verifie(count(courrielsA(COMITE_AUTONOME)) === 1 && count(courrielsA(ADMIN)) === 1, "agenda non téléchargé, 24 heures plus tard : les deux courriels repartent");

// --- Numéro invalide : le comité seulement, tous les numéros dans un seul courriel ---
preparer();
ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111'), quartEnCours('Paul', '514-555'), quartEnCours('Lise', '123')]);
$cron = passage();
verifie(count(courriels()) === 1 && count(courrielsA(COMITE_AUTONOME)) === 1, "numéro invalide : un seul courriel, au comité (".count(courriels()).")");
$courriel = courrielsA(COMITE_AUTONOME)[0] ?? '';
verifie(str_contains($courriel, 'Paul') && str_contains($courriel, 'Lise'), "numéro invalide : tous les numéros du passage sont dans le courriel");
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111']], "numéro invalide : le bon répondant est dans le groupe");
oublierCourriels();
$cron = passage();
verifie(courriels() === [] && str_contains($cron['sortie'], 'Paul'), "numéro invalide, passage suivant : aucun courriel, la trace reste à l'écran");
verifie(combienDeLignes(journalDe('*id2ringgroupupdatelog.txt'), 'Paul') === 2, "numéro invalide : la trace reste au journal à chaque passage");
reculerDernierEnvoi(AUTONOME, ['numero']);
passage();
verifie(count(courrielsA(COMITE_AUTONOME)) === 1, "numéro invalide, 24 heures plus tard : un autre courriel");

// --- Un courriel ne répète plus le précédent : agenda et numéro invalide dans le même passage ---
preparer();
ecrireAgenda('autonome', [quartEnCours('Paul', '514-555')]);
passage();
reculerDernierEnvoi(AUTONOME, ['numero']);
oublierCourriels();
exec('rm -f '.ETAT_BANC.'/agenda-autonome.ics');
passage();
$pourLeComite = courrielsA(COMITE_AUTONOME);
verifie(count($pourLeComite) === 2, "agenda et numéro dans le même passage : deux courriels au comité (".count($pourLeComite).")");
verifie(!str_contains($pourLeComite[1] ?? 'Échec', 'lécharger') && !str_contains($pourLeComite[1] ?? 'Échec', 'chargement de l'), "un courriel ne répète plus le précédent");
verifie(str_contains($pourLeComite[1] ?? '', 'Paul'), "le second courriel contient seulement son propre message");

$courrielUnique = new Courriel(COMITE_AUTONOME);
oublierCourriels();
$courrielUnique->addlinetomessagebody('PREMIER MESSAGE');
$courrielUnique->sendemail('premier');
$courrielUnique->addlinetomessagebody('SECOND MESSAGE');
$courrielUnique->sendemailtoadmin('second', AUTONOME);
verifie(str_contains(courrielsA(ADMIN)[0] ?? '', 'SECOND MESSAGE') && !str_contains(courrielsA(ADMIN)[0] ?? '', 'PREMIER MESSAGE'), "Courriel : le corps est vidé après chaque envoi");

// --- Nom SBChat invalide : le comité, limité, et le message part maintenant ---
repartirAZero();
$midiDemain = strtotime('tomorrow noon');
$nomInvalide = ['nom' => 'pas un nom matrix', 'description' => '', 'debut' => $midiDemain, 'fin' => $midiDemain + 3600];
ecrireAgenda('chat-sbchat', [$nomInvalide]);
$cron = lancerCron($veilleChat);
verifie(count(courrielsA(COMITE_CHAT)) === 1 && str_contains(courrielsA(COMITE_CHAT)[0], 'pas un nom matrix'), "nom SBChat invalide, rappels : le message part maintenant");
verifie(str_contains($cron['sortie'], 'pas un nom matrix'), "nom SBChat invalide, rappels : à l'écran");
verifie(str_contains(journalDe('*id6remindersbchatdaybeforelog.txt'), 'pas un nom matrix'), "nom SBChat invalide, rappels : au journal");
oublierCourriels();
ecrireAgenda('chat-sbchat', [quartEnCours('pas un nom matrix', '')]);
$cron = lancerCron('refreshsbchat.php');
verifie(courriels() === [], "nom SBChat invalide, répondants : même limite que les rappels, aucun courriel de plus");
verifie(str_contains(journalDe('*id6sbchatupdatelog.txt'), 'pas un nom matrix') && str_contains($cron['sortie'], 'pas un nom matrix'), "nom SBChat invalide, répondants : au journal et à l'écran");
reculerDernierEnvoi(CHAT, ['nomsbchat']);
lancerCron('refreshsbchat.php');
verifie(count(courrielsA(COMITE_CHAT)) === 1, "nom SBChat invalide, 24 heures plus tard : un autre courriel");

// --- mail() rate une fois : le second essai réussit ---
preparer();
ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111'), quartEnCours('Paul', '514-555')]);
pannesCourriel(1);
passage();
verifie(count(courrielsA(COMITE_AUTONOME)) === 1, "mail() rate une fois : le second essai réussit");
verifie(alerteComite(AUTONOME, 'numero')['dernierenvoi'] !== null, "mail() rate une fois : la date est notée");
verifie(journalDe('courriellog.txt') === '', "mail() rate une fois : rien au journal courriel");

// --- mail() rate deux fois : une trace au journal, sans le corps, la date n'est pas notée, le passage suivant réessaie ---
preparer();
ecrireAgenda('autonome', [quartEnCours('Jean', '514-555-0111'), quartEnCours('Paul', '514-555')]);
pannesCourriel(2);
$cron = passage();
verifie(courriels() === [], "mail() rate deux fois : aucun courriel");
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550111']] && signalBon() && $cron['code'] === 0, "mail() rate deux fois : le groupe se met à jour, le signal de vie reste bon");
$journal = journalDe('courriellog.txt');
verifie(str_contains($journal, COMITE_AUTONOME) && str_contains($journal, "Rapport d'erreur"), "mail() rate deux fois : une trace au journal courriel");
verifie(!str_contains($journal, 'Paul'), "mail() rate deux fois : le corps du message n'est pas au journal");
verifie((alerteComite(AUTONOME, 'numero')['dernierenvoi'] ?? null) === null, "mail() rate deux fois : la date n'est pas notée");
passage();
verifie(count(courrielsA(COMITE_AUTONOME)) === 1, "mail() rate deux fois : le passage suivant réessaie, et le courriel part");
exec('rm -f '.DOSSIER_JOURNAUX.'courriellog.txt');

// Le second essai a raté : les courriels suivants du même passage n'attendent plus
preparer();
passage();
exec('rm -f '.ETAT_BANC.'/agenda-autonome.ics');
pannesCourriel(3);
passage();
verifie(courriels() === [] && trim((string)file_get_contents(ETAT_BANC.'/panne-msmtp-fois')) === '0', "serveur de courriel en panne : le 2e courriel du passage ne refait pas de second essai");
verifie(combienDeLignes(journalDe('courriellog.txt'), 'envoi impossible') === 2, "serveur de courriel en panne : une trace par courriel perdu");
exec('rm -f '.DOSSIER_JOURNAUX.'courriellog.txt');

// --- Canari : échec puis réussite = check bon ; deux échecs = check en échec ---
pannesCourriel(1);
oublierCourriels();
viderJournalFaux();
$cron = lancerCron('mailcanary.php');
verifie(pingsSignalDeVie('mail') === ['/banc-mail'], "canari, échec puis réussite : le check reste bon");
verifie(count(courriels()) === 1, "canari, échec puis réussite : le courriel part");
verifie(str_contains(corpsDuPing('mail'), 'second essai') && str_contains($cron['sortie'], 'second essai'), "canari, échec puis réussite : le ping et l'écran le disent");
pannesCourriel(2);
oublierCourriels();
viderJournalFaux();
$cron = lancerCron('mailcanary.php');
verifie(pingsSignalDeVie('mail') === ['/banc-mail/fail'], "canari, deux échecs : le check est en échec");
verifie(courriels() === [] && str_contains($cron['sortie'], 'mailcanary: échec'), "canari, deux échecs : aucun courriel, et l'écran le dit");

// --- La table d'alertes tombe : les groupes de sonnerie se mettent quand même à jour, et rien n'est envoyé ---
preparer();
passage();
connexionBD()->query('DROP TABLE alertecomite');
ecrireAgenda('autonome', [quartEnCours('Lise', '514-555-0113'), quartEnCours('Paul', '514-555')]);
changerInfo(MEMBRE1, 'calendarurl', 'https://agendas.banc.test/dateinvalide');
oublierCourriels();
$cron = passage();
verifie($cron['code'] === 0 && str_contains($cron['sortie'], 'Fin du traitement'), "table d'alertes absente : le cron va au bout");
verifie(groupesVoipms('banc-autonome') === ['Groupe autonome' => ['5145550113']], "table d'alertes absente : le groupe de sonnerie se met à jour");
verifie(courriels() === [], "table d'alertes absente : aucun courriel, faute de mémoire");
verifie(signalBon(), "table d'alertes absente : le signal de vie reste bon");
verifie(str_contains($cron['sortie'], 'Mémoire des alertes'), "table d'alertes absente : la cause va dans le journal d'erreurs de PHP");

// Sans mémoire, on ne peut pas savoir si le passage précédent avait échoué : mieux vaut avertir
sonnerieSeulementPour([AUTONOME]);
refuserMotDePasse(AUTONOME);
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "table d'alertes absente, tous en échec voip.ms : le signal de vie avertit");

finDuScenario();
