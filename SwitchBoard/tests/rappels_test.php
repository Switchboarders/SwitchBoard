<?php
// Les rappels (tâche 7.1) : qui reçoit un SMS ou un message SwitchBoard Chat, et lequel (RappelsSms, RappelsChat), la plage
// dont parle un rappel (FenetreDeRappel) et le nom d'un utilisateur Matrix (NomMatrix). Les classes décident seulement : aucun
// réseau, aucune BD. Les agendas sont de vrais fichiers ICS, lus par la vraie classe Agenda. Les heures d'hiver (UTC-5) évitent
// tout changement d'heure.
//
// Usage : php rappels_test.php

require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once __DIR__.'/verifie.php';

$quebec = new DateTimeZone(FUSEAU_HORAIRE);
// Une heure du Québec ('2030-01-15 08:00') : l'agenda la reçoit en UTC
function ics(array $quarts): string {
	global $quebec;
	$utc = fn(string $heure) => (new DateTime($heure, $quebec))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
	$texte = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//Essai//FR\r\n";
	foreach ($quarts as $i => [$titre, $description, $debut, $fin]) {
		$texte .= "BEGIN:VEVENT\r\nUID:quart$i@essai.test\r\nDTSTAMP:20300101T000000Z\r\nSUMMARY:$titre\r\nDESCRIPTION:$description\r\n"
			."DTSTART:{$utc($debut)}\r\nDTEND:{$utc($fin)}\r\nEND:VEVENT\r\n";
	}
	return $texte."END:VCALENDAR\r\n";
}
function agenda(array $quarts): Agenda {
	$fichier = tempnam(sys_get_temp_dir(), 'rappels');
	file_put_contents($fichier, ics($quarts));
	$agenda = new Agenda($fichier, null, null, false);
	unlink($fichier);
	return $agenda;
}
function heure(string $heure): DateTime {
	global $quebec;
	return new DateTime($heure, $quebec);
}

// --- FenetreDeRappel ---
[$debut, $fin] = FenetreDeRappel::pour("daybefore", heure('2030-01-14 17:23:11'));
verifie($debut->format('Y-m-d H:i:s') === '2030-01-15 00:00:00' && $fin->format('Y-m-d H:i:s') === '2030-01-15 23:59:59', "la veille : la journée de demain, de minuit à 23:59:59");
[$debut, $fin] = FenetreDeRappel::pour("hourbefore", heure('2030-01-14 17:23:11'));
verifie($debut->format('H:i:s') === '18:00:00' && $fin->format('H:i:s') === '18:59:59', "une heure avant : la prochaine heure pleine (17:23 donne 18:00 à 18:59:59)");
[$debut] = FenetreDeRappel::pour("hourbefore", heure('2030-01-14 17:00:00'));
verifie($debut->format('H:i:s') === '17:00:00', "une heure avant : pile à l'heure, la plage commence maintenant");
[$debut, $fin] = FenetreDeRappel::pour("hourbefore", heure('2030-01-14 23:30:00'));
verifie($debut->format('Y-m-d H:i') === '2030-01-15 00:00' && $fin->format('Y-m-d H:i:s') === '2030-01-15 00:59:59', "une heure avant, à 23:30 : la plage est celle de minuit, le lendemain");
$maintenant = heure('2030-01-14 17:23:11');
FenetreDeRappel::pour("hourbefore", $maintenant);
FenetreDeRappel::pour("daybefore", $maintenant);
verifie($maintenant->format('Y-m-d H:i:s') === '2030-01-14 17:23:11', "la date reçue n'est pas modifiée");
try {
	FenetreDeRappel::pour("autre", $maintenant);
	verifie(false, "un mode inconnu lève une exception");
} catch (InvalidArgumentException $erreur) {
	verifie(true, "un mode inconnu lève une exception");
}

// --- NomMatrix ---
verifie(NomMatrix::estValide('@lita') && NomMatrix::estValide('@lita:matrix.example.org') && NomMatrix::estValide('@a.b_c=d-1'), "nom Matrix : @lita, avec serveur, avec point, soulignement, égal, trait d'union");
verifie(!NomMatrix::estValide('lita') && !NomMatrix::estValide('@') && !NomMatrix::estValide('@Lita') && !NomMatrix::estValide('@lita dupont') && !NomMatrix::estValide('@lita:') && !NomMatrix::estValide(''), "nom Matrix : sans @, vide, majuscule, espace, serveur vide : refusés");
verifie(NomMatrix::depuisTitre("  @Lita \t") === '@lita', "nom Matrix lu dans un titre : minuscules, sans espaces autour");

// --- RappelsSms, la veille ---
[$debut, $fin] = FenetreDeRappel::pour("daybefore", heure('2030-01-14 17:00:00'));
$agenda = agenda([
	['Jean*', '514-555-0111', '2030-01-15 08:00', '2030-01-15 12:00'],
	['Jean*', '514-555-0111', '2030-01-15 13:00', '2030-01-15 16:00'],
	['Marie', '514-555-0112', '2030-01-15 08:00', '2030-01-15 16:00'],
	['Paul*', '514-555-012', '2030-01-15 09:00', '2030-01-15 10:00'],
	['*Lise', 'Tél 514-555-0113', '2030-01-15 06:00', '2030-01-15 06:00'],
	['Luc*', '514-555-0114', '2030-01-16 08:00', '2030-01-16 16:00'],
	['Anne*', '514-555-0115 faire1->n', '2030-01-14 08:00', '2030-01-14 16:00'],
]);
$plan = RappelsSms::planifier($agenda, "daybefore", $debut, $fin);
$numeros = array_column($plan['rappels'], 'numero');
sort($numeros);
verifie($numeros === ['5145550111', '5145550113'], "la veille : seuls Jean et Lise, marqués d'une astérisque et de garde demain, sont textés (".implode(',', $numeros).")");
$jean = array_values(array_filter($plan['rappels'], fn($r) => $r['numero'] === '5145550111'))[0];
verifie($jean['plages'] === '08:00 à 12:00,13:00 à 16:00', "la veille : un seul texto par numéro, avec ses deux plages (".$jean['plages'].")");
verifie($jean['message'] === 'Jean vous êtes cédulé demain le 15 : 08:00 à 12:00,13:00 à 16:00', "la veille : le texte du rappel (".$jean['message'].")");
verifie($jean['nom'] === 'Jean', "la veille : le nom n'a plus l'astérisque");
$lise = array_values(array_filter($plan['rappels'], fn($r) => $r['numero'] === '5145550113'))[0];
verifie($lise['plages'] === 'toute la journée' && $lise['nom'] === 'Lise', "la veille : un quart dont le début et la fin sont à la même heure : « toute la journée »");
verifie(count($plan['refus']) === 1 && str_contains($plan['refus'][0], 'Numéro invalide pour « Paul* »') && str_contains($plan['refus'][0], '514-555-012'), "la veille : un numéro refusé dont le quart est demain est dit (".implode(' | ', $plan['refus']).")");
verifie(!in_array('5145550112', $numeros, true), "la veille : Marie, sans astérisque, n'est pas textée");

// --- RappelsSms, une heure avant ---
[$debut, $fin] = FenetreDeRappel::pour("hourbefore", heure('2030-01-15 07:20:00'));
$agenda = agenda([
	['Jean!', '514-555-0111', '2030-01-15 08:00', '2030-01-15 12:00'],
	['Marie', '514-555-0112', '2030-01-15 08:00', '2030-01-15 12:00'],
	['Lise!', '514-555-0113', '2030-01-15 08:00', '2030-01-15 08:00'],
	['Luc!', '514-555-0114', '2030-01-15 09:00', '2030-01-15 12:00'],
	['Anne*', '514-555-0115', '2030-01-15 08:30', '2030-01-15 12:00'],
]);
$plan = RappelsSms::planifier($agenda, "hourbefore", $debut, $fin);
verifie(array_column($plan['rappels'], 'numero') === ['5145550111'], "une heure avant : seul Jean, marqué d'un point d'exclamation et de garde dans la prochaine heure, est texté (".implode(',', array_column($plan['rappels'], 'numero')).")");
verifie($plan['rappels'][0]['message'] === 'Jean vous êtes cédulé dans la prochaine heure de 08:00 à 12:00', "une heure avant : le texte du rappel (".$plan['rappels'][0]['message'].")");
verifie($plan['refus'] === [], "une heure avant : aucun numéro refusé");

// --- RappelsSms : personne ---
$plan = RappelsSms::planifier(agenda([['Marie', '514-555-0112', '2030-01-15 08:00', '2030-01-15 12:00']]), "hourbefore", $debut, $fin);
verifie($plan['rappels'] === [] && $plan['refus'] === [], "aucune personne marquée : aucun rappel");
$plan = RappelsSms::planifier(agenda([]), "daybefore", $debut, $fin);
verifie($plan['rappels'] === [], "agenda sans événement : aucun rappel");

// --- RappelsChat ---
[$debut, $fin] = FenetreDeRappel::pour("daybefore", heure('2030-01-14 17:00:00'));
$agenda = agenda([
	['@Lita', '', '2030-01-15 08:00', '2030-01-15 12:00'],
	['Jean Dupont', '', '2030-01-15 09:00', '2030-01-15 10:00'],
	['@marc:matrix.example.org', '', '2030-01-15 13:00', '2030-01-15 16:30'],
	['@paul', '', '2030-01-16 09:00', '2030-01-16 10:00'],
]);
$plan = RappelsChat::planifier($agenda, "daybefore", $debut, $fin);
verifie($plan['destinataires'] === [['@lita', '08:00 et 12:00'], ['@marc:matrix.example.org', '13:00 et 16:30']], "chat, la veille : les deux noms Matrix valides de demain, avec leurs heures (".json_encode($plan['destinataires']).")");
verifie($plan['nomsInvalides'] === ['Jean Dupont'], "chat : un titre qui n'est pas un nom Matrix est signalé (".json_encode($plan['nomsInvalides']).")");
verifie($plan['prefixe'] === 'Vous êtes cédulé à répondre aux textos demain entre : ', "chat, la veille : le début du message");
verifie(count($plan['lignes']) === 6 && $plan['lignes'][0] === 'Tour de @Lita' && $plan['lignes'][1] === 'Début du shift : 15-01-2030 08:00' && $plan['lignes'][2] === 'Fin du shift : 15-01-2030 12:00', "chat : le journal dit le tour, le début et la fin de chaque répondant (".json_encode($plan['lignes']).")");
[$debut, $fin] = FenetreDeRappel::pour("hourbefore", heure('2030-01-15 07:40:00'));
$plan = RappelsChat::planifier($agenda, "hourbefore", $debut, $fin);
verifie(array_column($plan['destinataires'], 0) === ['@lita'] && $plan['prefixe'] === 'Vous êtes cédulé à répondre aux textos dans la prochaine heure entre : ', "chat, une heure avant : seul @lita, avec le bon début de message");

echo "\n".($echecs === 0 ? "Tous les tests des rappels ont réussi." : "$echecs test(s) ont échoué.")."\n";
exit($echecs === 0 ? 0 : 1);
