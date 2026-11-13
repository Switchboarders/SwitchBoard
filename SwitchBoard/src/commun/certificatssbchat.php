<?php
// Chargé par l'amorce (lesconstantes.php), comme oauthcourriel.php : ces fonctions sont disponibles partout.

// Certificat TLS du serveur SwitchBoard Chat / MAS.
//
// La vérification TLS est ACTIVE par défaut (les jetons SBChat/MAS transitent sur cette
// connexion). Deux échappatoires, pour un serveur SBChat à certificat auto-signé :
//
//  1. $sbchatcabundle (ou SB_SBCHAT_CA_BUNDLE) : chemin vers le certificat/AC à approuver.
//     SOLUTION RECOMMANDÉE : la vérification reste active, on ajoute simplement votre
//     certificat auto-signé aux certificats de confiance.
//  2. $allowinsecuretls (ou SB_ALLOW_INSECURE_TLS=1) : désactive complètement la
//     vérification. Simple mais expose la connexion à une interception (MITM) : à réserver
//     à un réseau de confiance / au développement.
// Chemin où la console admin (page « Certificat SwitchBoard Chat / MAS », voir
// adminpanelspages/sbchatcertificatepage.php) enregistre le certificat approuvé en un clic -
// évite d'avoir à éditer config.php ou à transférer un fichier par FTP à chaque renouvellement.
// Path where the admin console (the "SwitchBoard Chat / MAS certificate" page) saves the
// certificate approved in one click - avoids having to edit config.php or transfer a file by
// FTP on every renewal.
if (!function_exists('cheminCertificatSbchatGere')):
	function cheminCertificatSbchatGere() {
		// Dans config/ : déjà protégé du web (voir protegerDossierDuWeb() dans Fichiers.php).
		return CONFIG_DIRECTORY.'sbchat-ca-bundle.pem';
	}
endif;

if (!function_exists('sbchatCaBundle')):
	function sbchatCaBundle() {
		$chemin = getenv('SB_SBCHAT_CA_BUNDLE');
		if (is_string($chemin) && $chemin !== '') {
			return $chemin;
		}
		// Certificat approuvé depuis la console admin : prioritaire sur config.php, qui
		// devient un simple filet de secours pour qui préfère encore le configurer à la main.
		// Certificate approved from the admin console: takes priority over config.php, which
		// becomes a plain fallback for whoever still prefers configuring it by hand.
		$cheminGere = cheminCertificatSbchatGere();
		if (is_readable($cheminGere)) {
			return $cheminGere;
		}
		$fichierConfig = CONFIG_DIRECTORY.'config.php';
		if (is_readable($fichierConfig)) {
			include $fichierConfig;
			if (isset($sbchatcabundle) && is_string($sbchatcabundle) && $sbchatcabundle !== '') {
				return $sbchatcabundle;
			}
		}
		return '';
	}
endif;

// cURL renvoie la même erreur (#77) que le fichier de certificat soit introuvable,
// illisible, vide ou malformé - ce qui rend le diagnostic pénible. On distingue donc
// nous-mêmes les cas, pour afficher un message qui dit quoi corriger.
//
// @return string Message décrivant le problème, ou chaîne vide si le certificat est utilisable.
if (!function_exists('problemeCaBundle')):
	function problemeCaBundle($chemin) {
		if (!file_exists($chemin)) {
			return sprintf(_("Certificat SBChat introuvable : %s. Sur Docker, ce chemin doit être celui vu DEPUIS LE CONTENEUR php (le dossier Docker/certs/ y est monté sous /etc/ssl/sbchat/) et non un chemin de la machine hôte ; en installation manuelle, c'est un chemin normal sur votre serveur (voir README.md, section « Self-signed SBChat certificate »)."), $chemin);
		}
		if (!is_readable($chemin)) {
			return sprintf(_("Certificat SBChat illisible : %s. PHP s'exécute sous l'utilisateur du serveur web (ex. : www-data) : vérifiez les droits du fichier (ex. : chmod 644 %s)."), $chemin, $chemin);
		}
		$contenu = @file_get_contents($chemin);
		if ($contenu === false || trim($contenu) === '') {
			return sprintf(_("Certificat SBChat vide : %s. La commande openssl qui l'a généré a probablement échoué ; regénérez-le et vérifiez son contenu."), $chemin);
		}
		if (strpos($contenu, 'BEGIN CERTIFICATE') === false) {
			return sprintf(_("Certificat SBChat invalide : %s ne contient pas de certificat au format PEM (ligne '-----BEGIN CERTIFICATE-----' absente). Regénérez-le."), $chemin);
		}
		return '';
	}
endif;

if (!function_exists('tlsNonVerifieAutorise')):
	function tlsNonVerifieAutorise() {
		$valeur = getenv('SB_ALLOW_INSECURE_TLS');
		if (is_string($valeur) && $valeur !== '') {
			return $valeur === '1' || strtolower($valeur) === 'true';
		}
		$fichierConfig = CONFIG_DIRECTORY.'config.php';
		if (is_readable($fichierConfig)) {
			include $fichierConfig;
			if (isset($allowinsecuretls)) {
				return (bool)$allowinsecuretls;
			}
		}
		return false;
	}
endif;

// --- Approbation du certificat SBChat/MAS depuis la console admin ----------------------
// Approving the SBChat/MAS certificate from the admin console
//
// Support de la page adminpanelspages/sbchatcertificatepage.php : récupérer directement le
// certificat présenté par le serveur (équivalent programmatique de
// `openssl s_client -connect hote:port`), ou en coller un manuellement, puis l'enregistrer à
// cheminCertificatSbchatGere() - sans passer par config.php ni un transfert de fichier.

// Valide un contenu PEM (chaîne, pas un chemin de fichier) avant de l'enregistrer comme
// certificat approuvé. Mêmes types de vérifications que problemeCaBundle(), mais sur du
// contenu déjà en mémoire.
// @return string Message d'erreur, ou chaîne vide si le PEM est utilisable.
if (!function_exists('problemeCertificatPem')):
	function problemeCertificatPem($pem) {
		if (!is_string($pem) || trim($pem) === '') {
			return _("Aucun contenu de certificat fourni.");
		}
		if (strpos($pem, 'BEGIN CERTIFICATE') === false) {
			return _("Ce texte ne contient pas de certificat au format PEM (ligne '-----BEGIN CERTIFICATE-----' absente).");
		}
		if (@openssl_x509_parse($pem) === false) {
			return _("Ce certificat est illisible par OpenSSL (format invalide ou corrompu).");
		}
		return '';
	}
endif;

// Récupère, SANS LE VALIDER (c'est le but : voir le certificat actuellement présenté, même
// auto-signé ou expiré, pour laisser l'administrateur décider de lui faire confiance), le
// certificat qu'un serveur TLS présente actuellement.
// Fetches, WITHOUT VALIDATING IT (that's the point: see the certificate currently presented,
// even self-signed or expired, so the admin can decide whether to trust it), the certificate a
// TLS server currently presents.
// @return array ['pem' => string, 'erreur' => string] - l'un des deux est toujours vide.
if (!function_exists('recupererCertificatServeur')):
	function recupererCertificatServeur($hote, $port) {
		if (!is_string($hote) || $hote === '') {
			return ['pem' => '', 'erreur' => _("Adresse de serveur invalide.")];
		}
		$port = (int)$port;
		if ($port <= 0) {
			$port = 443;
		}
		$contexte = stream_context_create(['ssl' => [
			'capture_peer_cert' => true,
			'verify_peer' => false,
			'verify_peer_name' => false,
			'allow_self_signed' => true,
			'peer_name' => $hote,
		]]);
		$client = @stream_socket_client(
			"ssl://{$hote}:{$port}", $errno, $errstr, 10,
			STREAM_CLIENT_CONNECT, $contexte
		);
		if (!$client) {
			return ['pem' => '', 'erreur' => "$errstr ($errno)"];
		}
		$parametres = stream_context_get_params($client);
		fclose($client);
		if (empty($parametres['options']['ssl']['peer_certificate'])) {
			return ['pem' => '', 'erreur' => _("Le serveur n'a présenté aucun certificat.")];
		}
		$pem = '';
		openssl_x509_export($parametres['options']['ssl']['peer_certificate'], $pem);
		if ($pem === '') {
			return ['pem' => '', 'erreur' => _("Impossible d'exporter le certificat reçu.")];
		}
		return ['pem' => $pem, 'erreur' => ''];
	}
endif;

// Extrait les informations d'affichage (sujet, SAN, expiration, empreinte, auto-signé ou non)
// d'un certificat PEM déjà validé par problemeCertificatPem(). À utiliser seulement après
// validation.
//
// auto_signe distingue un certificat auto-signé (issuer == subject) d'un certificat émis par
// une AC publique (ex. Let's Encrypt) : approuver ce dernier ici a un coût réel, pas seulement
// inutile - CURLOPT_CAINFO REMPLACE la confiance système par CE SEUL fichier, donc au
// renouvellement suivant (~60-90 jours pour Let's Encrypt), le NOUVEAU certificat ne
// correspondra plus au PEM figé ici et la connexion échouera de nouveau, même si le nouveau
// certificat est parfaitement valide. La page sbchatcertificatepage.php utilise ce champ pour
// suggérer de retirer un certificat approuvé qui n'est plus auto-signé.
if (!function_exists('analyserCertificatPem')):
	function analyserCertificatPem($pem) {
		$infos = @openssl_x509_parse($pem);
		if ($infos === false) {
			return false;
		}
		$sujet = $infos['subject']['CN'] ?? implode(', ', $infos['subject'] ?? []);
		$emetteur = $infos['issuer']['CN'] ?? implode(', ', $infos['issuer'] ?? []);
		$san = $infos['extensions']['subjectAltName'] ?? '';
		$expirationTimestamp = $infos['validTo_time_t'] ?? 0;
		$empreinteBrute = openssl_x509_fingerprint($pem, 'sha256');
		return [
			'sujet' => $sujet,
			'emetteur' => $emetteur,
			// Comparaison de tableaux associatifs : == ignore l'ordre des clés, ce qui est le
			// comportement voulu ici (seul le contenu importe).
			'auto_signe' => ($infos['issuer'] ?? null) == ($infos['subject'] ?? null),
			'san' => $san,
			'expiration' => $expirationTimestamp ? date('Y-m-d H:i', $expirationTimestamp) : _("inconnue"),
			// 30 jours : simple avertissement visuel, pas un blocage.
			'expire_bientot' => $expirationTimestamp ? ($expirationTimestamp - time() < 30 * 86400) : false,
			'empreinte' => $empreinteBrute ? strtoupper(implode(':', str_split($empreinteBrute, 2))) : _("inconnue"),
		];
	}
endif;

// Enregistre un certificat PEM déjà validé (problemeCertificatPem() === '') comme le nouveau
// certificat approuvé, à l'emplacement lu en priorité par sbchatCaBundle(). Droits restreints
// à la lecture/écriture par le propriétaire : un certificat n'est pas un secret (il ne
// contient jamais la clé privée), mais autant limiter qui peut l'écrire.
if (!function_exists('enregistrerCertificatSbchatGere')):
	function enregistrerCertificatSbchatGere($pem) {
		$chemin = cheminCertificatSbchatGere();
		if (@file_put_contents($chemin, $pem) === false) {
			return false;
		}
		@chmod($chemin, 0644);
		return true;
	}
endif;

// Retire le certificat approuvé depuis la console admin : sbchatCaBundle() retombe alors sur
// config.php ou, le cas le plus courant, sur aucune configuration spéciale du tout - c'est-à-
// dire la confiance normale du système d'exploitation, qui couvre déjà les autorités de
// certification publiques (Let's Encrypt, etc.) et continue de fonctionner tout seul à travers
// leurs renouvellements. Retourne true si le fichier a été retiré ou n'existait déjà plus.
if (!function_exists('supprimerCertificatSbchatGere')):
	function supprimerCertificatSbchatGere() {
		$chemin = cheminCertificatSbchatGere();
		if (!file_exists($chemin)) {
			return true;
		}
		return @unlink($chemin);
	}
endif;
