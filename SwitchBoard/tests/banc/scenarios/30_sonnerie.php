#!/usr/bin/env php
<?php
// Le groupe de sonnerie : le vrai cron, avec sa vraie ligne, sur une vraie BD, contre le faux voip.ms.
require_once '/banc/commun/banc.php';

const JEAN = '5145550111';
const MARIE = '5145550112';
const LISE = '5145550113';
const AUTONOME = 2;
const MEMBRE1 = 4;
const MEMBRE2 = 5;
const GROUPE_AUTONOME = 'Groupe autonome';

$maintenant = time();
$enCours = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => $maintenant - 3600, 'fin' => $maintenant + 3600];
$quartDeJean = $enCours('Jean', '514-555-0111');
$quartDeMarie = $enCours('Marie', '514-555-0112');

// Un passage du cron, en n'oubliant rien de ce que les faux services ont appris avant lui
function passage(): array {
	viderJournalFaux();
	return lancerCron('refreshallringgroups.php');
}
function preparer(array $comites): void {
	repartirAZero();
	sonnerieSeulementPour($comites);
}
function changerAdresseAgenda(int $iduser, string $adresse): void {
	$bd = connexionBD();
	$requete = $bd->prepare('UPDATE userinfo SET calendarurl = ? WHERE iduser = ?');
	$requete->bind_param('si', $adresse, $iduser);
	$requete->execute();
}
function membresTexte(string $compte, string $groupe): string {
	$etat = json_decode((string)file_get_contents(ETAT_BANC."/voipms-$compte.json"), true);
	foreach ($etat['groupes'] as $unGroupe) {
		if ($unGroupe['name'] === $groupe) {
			return preg_replace('/fwd:\d+/', 'fwd', $unGroupe['texte_membres']);
		}
	}
	return '';
}

// --- Changement de garde ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$quartDeJean]);
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "premier passage : Jean est dans le groupe");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "premier passage : le signal de vie part");

ecrireAgenda('autonome', [$quartDeMarie]);
passage();
$methodes = methodesVoipms('banc-autonome');
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [MARIE]], "changement de garde : Marie remplace Jean");
verifie(renvoisVoipms('banc-autonome') === [MARIE], "changement de garde : le renvoi de Jean est effacé");
verifie(array_search('setRingGroup', $methodes) < array_search('delForwarding', $methodes), "changement de garde : le groupe change avant l'effacement du renvoi");
verifie(courriels() === [], "changement de garde : aucun courriel");

// --- Aucun changement ---
passage();
verifie(methodesVoipms('banc-autonome') === [], "aucun changement : aucun appel à voip.ms");
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [MARIE]], "aucun changement : le groupe reste");

// --- Personne de garde : agenda lisible, aucun quart en cours ---
ecrireAgenda('autonome', [['nom' => 'Jean', 'description' => '514-555-0111', 'debut' => $maintenant + 6 * 3600, 'fin' => $maintenant + 8 * 3600]]);
passage();
verifie(in_array('setRingGroup', methodesVoipms('banc-autonome'), true), "personne de garde : le groupe vide est envoyé");
verifie(renvoisVoipms('banc-autonome') === [], "personne de garde : les renvois sont effacés, comme avant le correctif");
passage();
verifie(methodesVoipms('banc-autonome') === [], "personne de garde : le passage suivant n'appelle plus voip.ms");

// --- Agenda illisible : téléchargement raté et cache vide, juste après une mise à jour ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$quartDeJean]);
passage();
array_map('unlink', glob(DOSSIER_CACHE_AGENDAS.'*.ical'));
unlink(ETAT_BANC.'/agenda-autonome.ics');
$cron = passage();
verifie(methodesVoipms('banc-autonome') === [], "agenda illisible sans cache : aucun appel à voip.ms");
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]] && renvoisVoipms('banc-autonome') === [JEAN], "agenda illisible sans cache : le répondant et son renvoi restent");
verifie($cron['code'] === 0 && pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "agenda illisible sans cache : le signal de vie reste bon");
verifie(count(courriels()) === 2, "agenda illisible sans cache : le téléchargement raté reste signalé au comité et à l'admin (".count(courriels()).")");

// --- Agenda illisible avec cache : la dernière copie sert ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$quartDeJean]);
passage();
unlink(ETAT_BANC.'/agenda-autonome.ics');
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "téléchargement raté avec cache : le groupe reste");
verifie(methodesVoipms('banc-autonome') === [], "téléchargement raté avec cache : le quart en cache ne change rien");

// --- Agenda reçu sans événement : le cache n'est pas écrasé ---
ecrireAgenda('autonome', []);
$fichierCache = glob(DOSSIER_CACHE_AGENDAS.'*.ical')[0];
$cacheAvant = file_get_contents($fichierCache);
passage();
verifie(file_get_contents($fichierCache) === $cacheAvant, "agenda sans événement : le cache n'est pas écrasé");
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "agenda sans événement : le groupe reste");

// --- Repères de l'agenda ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$enCours('off->Jean', '514-555-0111'), $quartDeMarie]);
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [MARIE]], "off-> : Jean, présent au bureau, n'est pas dans le groupe");

ecrireAgenda('autonome', [$enCours('Marie', '514-555-0112 faire1->n nbsec->30')]);
passage();
verifie(membresTexte('banc-autonome', GROUPE_AUTONOME) === 'fwd,30,0;', "faire1-> et nbsec-> : voip.ms reçoit 30 s, sans faire le 1 (".membresTexte('banc-autonome', GROUPE_AUTONOME).")");

ecrireAgenda('autonome', [$enCours('Marie', '514-555-0112 nbsec->45')]);
passage();
verifie(membresTexte('banc-autonome', GROUPE_AUTONOME) === 'fwd,45,1;', "changer seulement un réglage est renvoyé à voip.ms");
verifie(!in_array('setForwarding', methodesVoipms('banc-autonome'), true), "changer seulement un réglage ne recrée pas le renvoi");

// --- Panne passagère de voip.ms ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$quartDeJean]);
simulerPanne('voip.ms', [522, 522]);
passage();
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "panne 522 passagère : la reprise met quand même le groupe à jour");
verifie(courriels() === [], "panne 522 passagère : aucun courriel");

// --- Compte partagé ---
preparer([MEMBRE1, MEMBRE2]);
ecrireAgenda('membre1', [$quartDeMarie]);
ecrireAgenda('membre2', [$quartDeMarie]);
passage();
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [MARIE], 'Groupe membre 2' => [MARIE]], "compte partagé : même répondant dans les deux groupes");
ecrireAgenda('membre1', [$enCours('Lise', '514-555-0113')]);
passage();
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [LISE], 'Groupe membre 2' => [MARIE]], "compte partagé : seul le groupe qui change est modifié");
verifie(renvoisVoipms('banc-region') === [MARIE, LISE], "compte partagé : le renvoi encore utilisé par l'autre comité n'est pas effacé");
verifie(!str_contains(json_encode(groupesVoipms('banc-region')), 'renvoi efface'), "compte partagé : aucun groupe ne pointe vers un renvoi effacé");

// --- Resynchronisation quotidienne après 3 h : rattrape un changement fait à la main dans voip.ms ---
preparer([AUTONOME]);
ecrireAgenda('autonome', [$quartDeJean]);
passage();
$fichierCompte = ETAT_BANC.'/voipms-banc-autonome.json';
$compte = json_decode(file_get_contents($fichierCompte), true);
$idGroupe = array_key_first($compte['groupes']);
$compte['groupes'][$idGroupe]['members'] = [];
file_put_contents($fichierCompte, json_encode($compte));
exec('chown www-data:www-data '.escapeshellarg($fichierCompte));

passage();
verifie(methodesVoipms('banc-autonome') === [], "sans resynchronisation : un changement à la main passe inaperçu, comme prévu");
connexionBD()->query("UPDATE ringgroupstate SET lastfullsync = NOW() - INTERVAL 2 DAY WHERE iduser = ".AUTONOME);
passage();
verifie(in_array('getForwardings', methodesVoipms('banc-autonome'), true), "resynchronisation quotidienne : les renvois sont relus");
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "resynchronisation quotidienne : le groupe est remis en ordre");
// L'application écrit cette date en heure locale, alors que NOW() de MariaDB est en UTC : on compare ici
$lastfullsync = connexionBD()->query("SELECT lastfullsync FROM ringgroupstate WHERE iduser = ".AUTONOME)->fetch_row()[0];
verifie(strtotime($lastfullsync) > time() - 3600, "resynchronisation quotidienne : la date est notée ($lastfullsync)");

// --- Un seul comité en échec : les suivants passent et rien n'avertit ---
preparer([AUTONOME, MEMBRE1]);
changerAdresseAgenda(AUTONOME, 'https://agendas.banc.test/dateinvalide');
ecrireAgenda('membre1', [$quartDeMarie]);
$cron = passage();
verifie(str_contains($cron['sortie'], 'iduser='.AUTONOME), "un seul comité en échec : l'erreur est dans la sortie du cron");
verifie(groupesVoipms('banc-region') === ['Groupe membre 1' => [MARIE]], "un seul comité en échec : le comité suivant est traité (ancien die())");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "un seul comité en échec : aucun avertissement");
verifie($cron['code'] === 0, "un seul comité en échec : le cron finit normalement");

// --- Tous les comités en échec : l'avertissement part ---
preparer([AUTONOME, MEMBRE1]);
changerAdresseAgenda(AUTONOME, 'https://agendas.banc.test/dateinvalide');
changerAdresseAgenda(MEMBRE1, 'https://agendas.banc.test/dateinvalide');
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "tous les agendas en erreur : le signal de vie avertit");

// --- voip.ms en panne pour tous : l'avertissement attend le 2e passage de suite, sans aucun courriel ---
preparer([AUTONOME, MEMBRE1]);
ecrireAgenda('autonome', [$quartDeJean]);
ecrireAgenda('membre1', [$quartDeMarie]);
simulerPanne('voip.ms', array_fill(0, 200, 522));
passage();
verifie(groupesVoipms('banc-autonome') === [] && groupesVoipms('banc-region') === [], "voip.ms en panne : aucun groupe n'est créé");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "voip.ms en panne pour tous, 1er passage : pas encore d'avertissement (reçu : ".implode(',', pingsSignalDeVie('ringgroup')).")");
$corpsDuPing = journalFaux('hc-ping.com')[0]['corps'] ?? '';
verifie(str_contains($corpsDuPing, '1er passage'), "voip.ms en panne pour tous, 1er passage : le corps du ping le dit ($corpsDuPing)");
// Un code 500 n'est pas repris : ce passage reste rapide
simulerPanne('voip.ms', array_fill(0, 200, 500));
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "voip.ms en panne pour tous, 2e passage de suite : le signal de vie avertit (reçu : ".implode(',', pingsSignalDeVie('ringgroup')).")");
verifie(courriels() === [], "voip.ms en panne pour tous : aucun courriel");

@unlink(ETAT_BANC.'/panne-voip.ms');
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "voip.ms de retour : le signal de vie est bon");
verifie(groupesVoipms('banc-autonome') === [GROUPE_AUTONOME => [JEAN]], "voip.ms de retour : le groupe est à jour");
verifie(alerteComite(0, 'tousenechec')['passagesdesuite'] === 0 && alerteComite(AUTONOME, 'voipms')['passagesdesuite'] === 0, "voip.ms de retour : les compteurs repartent à zéro");
simulerPanne('voip.ms', array_fill(0, 200, 500));
ecrireAgenda('autonome', [$quartDeMarie]);
ecrireAgenda('membre1', [$quartDeJean]);
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "nouvelle panne après un passage réussi : le signal de vie attend de nouveau le 2e passage");

// --- La limite du signal de vie : un comité sans changement n'appelle pas voip.ms, et ne peut donc pas voir sa panne ---
preparer([AUTONOME, MEMBRE1]);
ecrireAgenda('autonome', [$quartDeJean]);
ecrireAgenda('membre1', [$quartDeMarie]);
passage();
simulerPanne('voip.ms', array_fill(0, 200, 500));
passage();
verifie(journalFaux('voip.ms') === [], "voip.ms en panne, comités déjà synchronisés sans changement : aucun appel");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "voip.ms en panne, comités sans changement : le signal de vie reste bon (limite acceptée)");
verifie((alerteComite(AUTONOME, 'voipms')['passagesdesuite'] ?? 0) === 0 && (alerteComite(MEMBRE1, 'voipms')['passagesdesuite'] ?? 0) === 0 && (alerteComite(0, 'tousenechec')['passagesdesuite'] ?? 0) === 0, "voip.ms en panne, comités sans changement : les compteurs restent à zéro");
verifie(courriels() === [], "voip.ms en panne, comités sans changement : aucun courriel");

// Après 3 h, la resynchronisation appelle voip.ms pour tous : la panne se voit enfin
connexionBD()->query("UPDATE ringgroupstate SET lastfullsync = NOW() - INTERVAL 2 DAY");
passage();
verifie(in_array('getForwardings', methodesVoipms('banc-autonome'), true) && in_array('getForwardings', methodesVoipms('banc-region'), true), "voip.ms en panne, resynchronisation de 3 h : tous les comités appellent voip.ms");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup'], "voip.ms en panne, resynchronisation de 3 h, 1er passage : le signal de vie attend");
passage();
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "voip.ms en panne, resynchronisation de 3 h, 2e passage : le signal de vie avertit (reçu : ".implode(',', pingsSignalDeVie('ringgroup')).")");
verifie(courriels() === [], "voip.ms en panne, resynchronisation de 3 h : aucun courriel");

finDuScenario();
