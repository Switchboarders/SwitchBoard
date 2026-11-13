<?php
// Test unitaire (pas de framework dans ce dépôt - script CLI simple, comme le reste du code)
// pour SignalDeVie, contre le serveur factice stubheartbeatserver.php. Aucun secret
// réel, aucun réseau au-delà de 127.0.0.1.
//
// Usage : php -S 127.0.0.1:8999 stubheartbeatserver.php & puis php deadmanswitchmanager_test.php

$logFile = __DIR__.'/stub-requests.log';
@unlink($logFile);

require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once __DIR__.'/verifie.php';

function contenuLog($logFile) {
	return file_exists($logFile) ? file_get_contents($logFile) : '';
}

// --- Cas 1 : succès simple ------------------------------------------------------------
putenv('SB_DMS_TESTSUCCESS_SUCCESS_URL=http://127.0.0.1:8999/testsuccess/success');
putenv('SB_DMS_TESTSUCCESS_FAIL_URL=');
$dms1 = new SignalDeVie('testsuccess');
verifie($dms1->estConfigure(), "Cas 1: le check est bien détecté comme configuré");
$dms1->pingSucces('corps de succès');
usleep(200000);
$log1 = contenuLog($logFile);
verifie(strpos($log1, '/testsuccess/success') !== false, "Cas 1: le ping de succès a atteint la bonne URL");
verifie(strpos($log1, 'corps de succès') !== false, "Cas 1: le corps du ping a été transmis");

// --- Cas 2 : échec explicite avec URL dédiée ------------------------------------------
@unlink($logFile);
putenv('SB_DMS_TESTFAIL_SUCCESS_URL=http://127.0.0.1:8999/testfail/success');
putenv('SB_DMS_TESTFAIL_FAIL_URL=http://127.0.0.1:8999/testfail/echec-dedie');
$dms2 = new SignalDeVie('testfail');
$dms2->pingEchec('raison du problème');
usleep(200000);
$log2 = contenuLog($logFile);
verifie(strpos($log2, '/testfail/echec-dedie') !== false, "Cas 2: le ping d'échec a utilisé l'URL d'échec DÉDIÉE, pas le suffixe /fail");
verifie(strpos($log2, 'raison du problème') !== false, "Cas 2: la raison de l'échec a été transmise");

// --- Cas 3 : non configuré -> aucun appel HTTP du tout --------------------------------
@unlink($logFile);
putenv('SB_DMS_UNITTESTUNCONFIGURED_SUCCESS_URL=');
putenv('SB_DMS_UNITTESTUNCONFIGURED_FAIL_URL=');
$dms3 = new SignalDeVie('unittestunconfigured');
verifie(!$dms3->estConfigure(), "Cas 3: le check est bien détecté comme NON configuré");
$dms3->pingSucces('ne devrait jamais partir');
$dms3->pingEchec('ne devrait jamais partir non plus');
usleep(200000);
$log3 = contenuLog($logFile);
verifie($log3 === '', "Cas 3: aucune requête HTTP n'a été envoyée quand le check n'est pas configuré");

// --- Cas 4 : repli sur success_url + /fail quand aucune URL d'échec dédiée ------------
@unlink($logFile);
putenv('SB_DMS_TESTFALLBACK_SUCCESS_URL=http://127.0.0.1:8999/testfallback/success');
putenv('SB_DMS_TESTFALLBACK_FAIL_URL=');
$dms4 = new SignalDeVie('testfallback');
$dms4->pingEchec('repli attendu');
usleep(200000);
$log4 = contenuLog($logFile);
verifie(strpos($log4, '/testfallback/success/fail') !== false, "Cas 4: repli correct sur success_url + /fail quand aucune URL d'échec dédiée n'est fournie");

// --- Cas 5 : armerDetectionEchec() + finirAvecSucces() désarme le repli --------------
@unlink($logFile);
putenv('SB_DMS_TESTARM_SUCCESS_URL=http://127.0.0.1:8999/testarm/success');
putenv('SB_DMS_TESTARM_FAIL_URL=');
$code5 = <<<'PHP'
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
$dms = new SignalDeVie('testarm');
$dms->armerDetectionEchec();
$dms->finirAvecSucces('tout va bien');
PHP;
file_put_contents(__DIR__.'/tmp_case5.php', "<?php\n".$code5);
exec('php '.escapeshellarg(__DIR__.'/tmp_case5.php').' 2>&1');
usleep(200000);
@unlink(__DIR__.'/tmp_case5.php');
$log5 = contenuLog($logFile);
verifie(strpos($log5, '/testarm/success') !== false && strpos($log5, '/fail') === false, "Cas 5: finirAvecSucces() désarme bien le repli générique (aucun ping d'échec envoyé)");

@unlink($logFile);
echo "\n".($echecs === 0 ? "TOUS LES TESTS ONT RÉUSSI" : "$echecs test(s) ONT ÉCHOUÉ")."\n";
exit($echecs === 0 ? 0 : 1);
