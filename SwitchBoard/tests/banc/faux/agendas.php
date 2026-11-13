<?php
// Faux serveur d'agendas : /c/<comité>.ics sert l'agenda déposé par le scénario, et les autres chemins
// (pages d'erreur, CalDAV, agenda sans événement) viennent de tests/stubagendaserver.php.
if (preg_match('#^/c/([a-z0-9_-]+)\.ics$#', $chemin, $trouve)) {
	$fichier = ETAT."/agenda-{$trouve[1]}.ics";
	if (!is_readable($fichier)) {
		http_response_code(404);
		return;
	}
	header('Content-Type: text/calendar');
	readfile($fichier);
	return;
}
require '/var/www/html/SwitchBoard/SwitchBoard/tests/stubagendaserver.php';
