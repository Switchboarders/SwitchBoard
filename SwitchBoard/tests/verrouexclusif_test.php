<?php
// Test unitaire (pas de framework - script CLI simple) pour VerrouExclusif : confirme que
// LOCK_NB ne bloque JAMAIS, et que le verrou est bien libéré par le noyau sur une sortie
// normale ET sur un kill -9 (le scénario réel : une exécution bloquée qui finit par être tuée
// ne doit jamais empêcher la prochaine exécution planifiée).
require_once dirname(__FILE__,2).'/src/utils/verrouexclusif.php';

require_once __DIR__.'/verifie.php';

$nomVerrou = 'unittest_verrou_'.uniqid();
// Chemin calculé depuis l'emplacement du test : les processus enfants doivent charger la même
// classe, quel que soit l'endroit où le dépôt est cloné.
$cheminVerrouExclusif = dirname(__FILE__,2).'/src/utils/verrouexclusif.php';

// Script enfant qui prend le verrou et dort - simule "l'exécution précédente toujours en cours".
$codeEnfant = <<<PHP
require_once '{$cheminVerrouExclusif}';
\$v = new VerrouExclusif('{$nomVerrou}');
\$v->acquerirSansAttendre();
sleep(6);
PHP;
$cheminEnfant = __DIR__.'/tmp_verrou_enfant.php';
file_put_contents($cheminEnfant, "<?php\n".$codeEnfant);

// --- Cas 1 : acquisition immédiate, sans attente, quand le verrou est déjà détenu ------
$descripteurs = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
$processusEnfant = proc_open(['php', $cheminEnfant], $descripteurs, $pipes);
usleep(500000); // laisse l'enfant acquérir le verrou avant qu'on essaie nous-mêmes

$debut = microtime(true);
$v2 = new VerrouExclusif($nomVerrou);
$acquis = $v2->acquerirSansAttendre();
$duree = microtime(true) - $debut;

verifie($acquis === false, "Cas 1: l'acquisition échoue bien pendant que l'enfant détient le verrou");
verifie($duree < 0.5, "Cas 1: LOCK_NB retourne immédiatement, sans attendre (".round($duree*1000)."ms)");

// --- Cas 2 : le verrou se libère normalement à la fin du processus détenteur ----------
$statutEnfant = proc_get_status($processusEnfant);
$pidEnfant = $statutEnfant['pid'];
// 9 et non SIGKILL : cette constante vient de l'extension pcntl, absente de l'image du serveur principal
posix_kill($pidEnfant, 9); // on tue l'enfant AVANT la fin naturelle de son sleep(6)
proc_close($processusEnfant);
usleep(300000);

$v3 = new VerrouExclusif($nomVerrou);
$acquisApresKill = $v3->acquerirSansAttendre();
verifie($acquisApresKill === true, "Cas 2: le verrou est bien libéré par le noyau même après un kill -9 de l'ancien détenteur");
$v3->liberer();

// --- Cas 3 : le verrou se libère aussi sur une sortie normale (pas seulement un kill) --
$processusEnfant2 = proc_open(['php', '-r', "require '{$cheminVerrouExclusif}'; (new VerrouExclusif('{$nomVerrou}'))->acquerirSansAttendre();"], $descripteurs, $pipes2);
proc_close($processusEnfant2); // attend la fin naturelle du processus enfant (sortie immédiate)
usleep(300000);

$v4 = new VerrouExclusif($nomVerrou);
$acquisApresSortieNormale = $v4->acquerirSansAttendre();
verifie($acquisApresSortieNormale === true, "Cas 3: le verrou est bien libéré après une sortie normale du processus détenteur");
$v4->liberer();

@unlink($cheminEnfant);
$cheminVerrouFichier = dirname(__FILE__,2).'/config/locks/'.$nomVerrou.'.lock';
@unlink($cheminVerrouFichier);

echo "\n".($echecs === 0 ? "TOUS LES TESTS ONT RÉUSSI" : "$echecs test(s) ONT ÉCHOUÉ")."\n";
exit($echecs === 0 ? 0 : 1);
