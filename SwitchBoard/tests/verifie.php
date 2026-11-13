<?php
// La vérification commune à tous les tests et aux scénarios du banc d'essai. Chaque test finit par
// sortir avec un code d'erreur si $echecs n'est pas à 0.
$echecs = 0;
function verifie($condition, $description) {
	global $echecs;
	if ($condition) {
		echo "OK: $description\n";
	} else {
		echo "ECHEC: $description\n";
		$echecs++;
	}
}
