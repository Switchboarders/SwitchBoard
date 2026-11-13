<?php
// Relais SMTP interne : reçoit du courriel en SMTP simple (sans authentification) d'un autre
// service du même réseau Docker (ex. MAS, pour la récupération de mot de passe) et le remet via
// msmtp, donc avec la MÊME configuration que SwitchBoard (/etc/msmtprc, mot de passe ou OAuth2
// Microsoft 365 / Google). Aucun réglage de courriel supplémentaire à saisir.
// Lancé par mail-relay-entrypoint.sh (monté depuis Docker/). Écoute sur le réseau Docker seulement : le port
// n'est jamais publié sur l'hôte. Ne journalise jamais le contenu ni les adresses des messages.
//
// Internal SMTP relay: accepts plain unauthenticated SMTP from another service on the same
// Docker network (e.g. MAS, for password recovery) and hands it to msmtp, i.e. with the SAME
// configuration as SwitchBoard (/etc/msmtprc, password or Microsoft 365 / Google OAuth2). No
// extra mail settings to enter. Never logs message bodies or addresses.
if (PHP_SAPI !== 'cli') { exit(1); }

const MAX_OCTETS = 5 * 1024 * 1024;
const MAX_DESTINATAIRES = 20;
const DELAI_SECONDES = 30;

function journal(string $m): void { fwrite(STDERR, date('c')." smtprelay: $m\n"); }

function repondre($c, string $ligne): void { fwrite($c, $ligne."\r\n"); }

// Remet le message à msmtp ; retourne null si succès, sinon un message d'erreur sans adresse.
function remettre(array $destinataires, string $message): ?string {
	if (!is_file('/etc/msmtprc')) { return 'courriel non configuré (voir SB_SMTP_* dans .env)'; }
	// Pas de -f : msmtp utilise l'adresse "from" du compte, la seule que Microsoft 365 accepte.
	$cmd = array_merge(['/usr/bin/msmtp', '-C', '/etc/msmtprc', '--'], $destinataires);
	$p = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
	if (!is_resource($p)) { return 'msmtp introuvable'; }
	fwrite($pipes[0], $message);
	fclose($pipes[0]);
	$sortie = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
	$code = proc_close($p);
	if ($code === 0) { return null; }
	// Les messages de msmtp peuvent citer des adresses : on ne garde que la première ligne
	// débarrassée de tout ce qui ressemble à une adresse.
	$ligne = trim(strtok($sortie, "\n") ?: 'échec');
	return preg_replace('/\S+@\S+/', '<adresse>', $ligne);
}

function session($c): void {
	stream_set_timeout($c, DELAI_SECONDES);
	$fin = time() + DELAI_SECONDES * 4;
	repondre($c, '220 sb-mail-relay ready');
	$dest = [];
	$aExpediteur = false;
	while (time() < $fin && ($ligne = fgets($c, 1024)) !== false) {
		$ligne = rtrim($ligne, "\r\n");
		$verbe = strtoupper(strtok($ligne, ' '));
		switch ($verbe) {
			case 'EHLO': case 'HELO':
				repondre($c, '250 sb-mail-relay'); break;
			case 'MAIL':
				$aExpediteur = true; $dest = []; repondre($c, '250 OK'); break;
			case 'RCPT':
				if (!$aExpediteur) { repondre($c, '503 MAIL first'); break; }
				if (count($dest) >= MAX_DESTINATAIRES) { repondre($c, '452 too many recipients'); break; }
				if (!preg_match('/^RCPT TO:\s*<?([^<>\s]+@[^<>\s]+)>?/i', $ligne, $m) || $m[1][0] === '-') {
					repondre($c, '501 bad recipient'); break;
				}
				$dest[] = $m[1]; repondre($c, '250 OK'); break;
			case 'DATA':
				if (!$dest) { repondre($c, '503 RCPT first'); break; }
				repondre($c, '354 End data with <CR><LF>.<CR><LF>');
				$message = ''; $trop = false;
				while (($l = fgets($c, 8192)) !== false) {
					if ($l === ".\r\n" || $l === ".\n") { break; }
					if ($l !== '' && $l[0] === '.') { $l = substr($l, 1); }
					if (strlen($message) + strlen($l) > MAX_OCTETS) { $trop = true; continue; }
					$message .= $l;
				}
				if ($trop) { repondre($c, '552 message too large'); }
				elseif (($err = remettre($dest, $message)) === null) {
					journal('remis à '.count($dest).' destinataire(s)');
					repondre($c, '250 OK queued');
				} else {
					journal("échec de remise : $err");
					repondre($c, '451 delivery failed, try again later');
				}
				$dest = []; $aExpediteur = false; break;
			case 'RSET': $dest = []; $aExpediteur = false; repondre($c, '250 OK'); break;
			case 'NOOP': repondre($c, '250 OK'); break;
			case 'QUIT': repondre($c, '221 Bye'); return;
			default: repondre($c, '502 command not implemented');
		}
	}
}

$port = (int)(getenv('SB_MAIL_RELAY_PORT') ?: 2525);
$serveur = stream_socket_server("tcp://0.0.0.0:$port", $errno, $errstr);
if (!$serveur) { journal("écoute impossible : $errstr"); exit(1); }
journal("à l'écoute sur le port $port");
while (true) {
	$c = @stream_socket_accept($serveur, 3600);
	if (!$c) { continue; }
	try { session($c); } catch (Throwable $e) { journal('erreur : '.get_class($e)); }
	fclose($c);
}
