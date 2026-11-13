#!/usr/bin/env php
<?php
// Toutes les vraies lignes de Docker/cronjobs, exécutées telles quelles, sur les comités inventés.
require_once '/banc/commun/banc.php';

repartirAZero();
$quart = fn(string $nom, string $telephone) => ['nom' => $nom, 'description' => $telephone, 'debut' => time() - 3600, 'fin' => time() + 3600];
ecrireAgenda('autonome', [$quart('Jean', '514-555-0111')]);
ecrireAgenda('membre1', [$quart('Marie', '514-555-0112')]);
ecrireAgenda('membre2', [$quart('Lise', '514-555-0113')]);
ecrireAgenda('chat-sbchat', [$quart('@lita', '')]);

$lignes = lignesCron();
verifie(count($lignes) >= 10, "les lignes de cron sont lues (".count($lignes).")");
foreach ($lignes as $ligne) {
	$nom = preg_match('/(\w+\.php)( "\w+")?/', $ligne, $trouve) ? $trouve[0] : strtok($ligne, ' ');
	file_put_contents('/var/log/cron.log', '');
	exec($ligne.' 2>&1', $sortieDirecte, $code);
	$sortie = (string)file_get_contents('/var/log/cron.log');
	verifie($code === 0, "$nom : sort sans erreur (code $code)");
	verifie(!preg_match('/Fatal error|Uncaught |PHP Parse error/', $sortie), "$nom : aucune erreur fatale");
	verifie(!str_contains($sortie, 'Attention, on roule sur le mauvais serveur'), "$nom : tourne sur ce serveur");
}

// --- Les crons surveillés ont envoyé leur signal de vie, et il est bon ---
foreach (['ringgroup', 'sbchat', 'mail', 'infra'] as $check) {
	verifie(pingsSignalDeVie($check) === ["/banc-$check"], "signal de vie de $check (".implode(',', pingsSignalDeVie($check)).")");
}

// --- Chevauchement : un cron qui tourne encore fait abandonner le suivant ---
$bloqueur = proc_open(['su', '-s', '/bin/sh', 'www-data', '-c',
	"php -r 'require \"".APPLI."/src/utils/verrouexclusif.php\"; \$verrou = new VerrouExclusif(\"refreshallringgroups\"); \$verrou->acquerirSansAttendre(); sleep(8);'"], [], $tuyaux);
sleep(2);
viderJournalFaux();
$cron = lancerCron('refreshallringgroups.php');
verifie(str_contains($cron['sortie'], 'chevauchement détecté'), "chevauchement : le cron abandonne");
verifie(methodesVoipms('banc-autonome') === [], "chevauchement : aucun appel à voip.ms");
verifie(pingsSignalDeVie('ringgroup') === ['/banc-ringgroup/fail'], "chevauchement : le signal de vie avertit (".implode(',', pingsSignalDeVie('ringgroup')).")");
proc_close($bloqueur);

finDuScenario();
