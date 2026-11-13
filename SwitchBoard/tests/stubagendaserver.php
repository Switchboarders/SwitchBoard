<?php
// Faux serveur d'agendas pour telechargementagenda_test.php et agendaillisible_test.php.
// Lancement : php -S 127.0.0.1:8995 stubagendaserver.php
//
//   /agenda.ics       GET : un agenda ; HEAD : 405, comme Outlook / Office 365 (issue #74)
//   /redirection      redirige vers /agenda.ics
//   /pagehtml         code 200, mais une page HTML au lieu d'un agenda
//   /absent           404
//   /caldav           REPORT avec agenda:secret : réponse CalDAV (207) ; sinon 401
//   /caldavvide       réponse CalDAV (207) sans aucun événement
//   /sansvevent       un agenda valide, sans aucun événement
//   /quart            un quart : ?nom=...&description=...&debut=<timestamp>&fin=<timestamp>
//   /dateinvalide     un agenda que la librairie ICal refuse : elle lève une exception

$agenda = "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:514-555-1234\r\n"
	."DTSTART:20261103T080000Z\r\nDTEND:20261103T200000Z\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";

$chemin = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$methode = $_SERVER['REQUEST_METHOD'];

switch ($chemin) {
	case '/agenda.ics':
		if ($methode !== 'GET') {
			http_response_code(405);
			return;
		}
		header('Content-Type: text/calendar');
		echo $agenda;
		return;

	case '/redirection':
		header('Location: /agenda.ics', true, 302);
		return;

	case '/pagehtml':
		echo "<html><body>Veuillez vous connecter</body></html>";
		return;

	case '/caldav':
		$identifiantsBons = ($_SERVER['PHP_AUTH_USER'] ?? '') === 'agenda'
			&& ($_SERVER['PHP_AUTH_PW'] ?? '') === 'secret';
		if ($methode !== 'REPORT' || !$identifiantsBons) {
			http_response_code(401);
			return;
		}
		http_response_code(207);
		header('Content-Type: application/xml; charset=utf-8');
		echo '<?xml version="1.0" encoding="utf-8"?>'
			.'<D:multistatus xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">'
			.'<D:response><D:href>/caldav/1.ics</D:href><D:propstat><D:prop>'
			.'<C:calendar-data>'.htmlspecialchars($agenda).'</C:calendar-data>'
			.'</D:prop><D:status>HTTP/1.1 200 OK</D:status></D:propstat></D:response>'
			.'</D:multistatus>';
		return;

	case '/caldavvide':
		http_response_code(207);
		header('Content-Type: application/xml; charset=utf-8');
		echo '<?xml version="1.0" encoding="utf-8"?><D:multistatus xmlns:D="DAV:"></D:multistatus>';
		return;

	case '/sansvevent':
		header('Content-Type: text/calendar');
		echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nEND:VCALENDAR\r\n";
		return;

	case '/quart':
		$date = fn($cle) => gmdate('Ymd\THis\Z', (int)$_GET[$cle]);
		header('Content-Type: text/calendar');
		echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:{$_GET['nom']}\r\n"
			."DESCRIPTION:{$_GET['description']}\r\nDTSTART:{$date('debut')}\r\nDTEND:{$date('fin')}\r\n"
			."END:VEVENT\r\nEND:VCALENDAR\r\n";
		return;

	case '/dateinvalide':
		header('Content-Type: text/calendar');
		echo "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nBEGIN:VEVENT\r\nSUMMARY:Jean\r\nDTSTART:pasunedate\r\n"
			."DTEND:pasunedate\r\nRRULE:FREQ=DAILY;COUNT=2\r\nEND:VEVENT\r\nEND:VCALENDAR\r\n";
		return;

	default:
		http_response_code(404);
}
