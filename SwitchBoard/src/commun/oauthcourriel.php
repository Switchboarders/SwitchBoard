<?php
// Chargé par l'amorce (lesconstantes.php), et non par l'autoload ni par les appelants : Docker/generate_mail_config.sh
// et mailoauthtoken.php (appelé par msmtp à chaque envoi) ne chargent que l'amorce, et leurs erreurs sont masquées. Si
// ces fonctions manquaient, le courriel OAuth se couperait sans bruit.

// --- OAuth2 pour le courriel (Microsoft 365 / Google) -----------------------------------
// OAuth2 for mail (Microsoft 365 / Google)
//
// Remplace un mot de passe SMTP statique par un jeton de rafraîchissement obtenu une seule
// fois (autorisation interactive depuis la console admin, voir adminpanelspages/mailoauthpage.php)
// puis échangé contre un jeton d'accès à chaque envoi (voir mailoauthtoken.php, appelé par
// msmtp via passwordeval). Chiffré au repos comme tout autre secret de l'application
// (Chiffrement), stocké dans config/ (déjà protégé du web).
//
// Replaces a static SMTP password with a refresh token obtained once (interactive
// authorization from the admin console, see adminpanelspages/mailoauthpage.php) then
// exchanged for an access token on every send (see mailoauthtoken.php, called by msmtp via
// passwordeval). Encrypted at rest like every other secret in the app (Chiffrement),
// stored in config/ (already protected from the web).

// Emplacement du fichier géré par l'assistant OAuth intégré (?page=mailoauth) : permet à
// n'importe quel utilisateur (Docker ou installation manuelle) de configurer le fournisseur,
// le Client ID/Secret et l'adresse courriel DEPUIS la console admin, sans jamais éditer .env
// ni config.php à la main. Le secret client (Google) y est chiffré comme tout autre secret.
//
// Location of the file managed by the built-in OAuth wizard (?page=mailoauth): lets any user
// (Docker or manual install) configure the provider, Client ID/Secret, and mailbox address
// FROM the admin console, without ever hand-editing .env or config.php. The client secret
// (Google) is encrypted there like every other secret.
if (!function_exists('cheminReglagesOAuthCourriel')):
	function cheminReglagesOAuthCourriel() {
		return CONFIG_DIRECTORY.'mail-oauth-settings.json';
	}
endif;

// Vrai si SB_SMTP_OAUTH_PROVIDER est imposé par l'environnement (Docker .env) : dans ce cas,
// l'assistant web (formulaire d'édition) est désactivé et affiche plutôt un message expliquant
// où changer le réglage - même principe que modeDebugForceParEnv() pour $modedebug.
//
// True if SB_SMTP_OAUTH_PROVIDER is forced by the environment (Docker .env): in that case the
// web wizard's edit form is disabled and shows a message explaining where to change the
// setting instead - same principle as modeDebugForceParEnv() for $modedebug.
if (!function_exists('reglagesOAuthForceesParEnv')):
	function reglagesOAuthForceesParEnv() {
		$valeur = getenv('SB_SMTP_OAUTH_PROVIDER');
		return is_string($valeur) && $valeur !== '';
	}
endif;

// Lit les réglages SB_SMTP_OAUTH_* (+ l'adresse courriel du compte) : variable d'environnement
// en priorité, puis le fichier géré par l'assistant web (?page=mailoauth,
// enregistrerReglagesOAuthCourriel() ci-dessous), puis config/config.php (même convention que
// githubtoken/cronwebkey/sbchatcabundle - voir lireCleCronWeb() dans authhelpers.php). Trois
// façons d'arriver au même résultat pour que Docker (.env), l'assistant intégré, et une
// installation manuelle (config.php édité à la main) fonctionnent tous les trois.
//
// Reads the SB_SMTP_OAUTH_* settings (+ the account's mailbox address): environment variable
// first, then the file managed by the web wizard (?page=mailoauth,
// enregistrerReglagesOAuthCourriel() below), then config/config.php (same convention as
// githubtoken/cronwebkey/sbchatcabundle - see lireCleCronWeb() in authhelpers.php). Three ways
// to reach the same result so Docker (.env), the built-in wizard, and a manual install
// (hand-edited config.php) all work.
if (!function_exists('reglagesOAuthCourriel')):
	function reglagesOAuthCourriel() {
		$fournisseur = getenv('SB_SMTP_OAUTH_PROVIDER');
		$clientId = getenv('SB_SMTP_OAUTH_CLIENT_ID');
		$clientSecret = getenv('SB_SMTP_OAUTH_CLIENT_SECRET');
		$tenant = getenv('SB_SMTP_OAUTH_TENANT');
		$mailboxUser = getenv('SB_SMTP_USER');
		if (!is_string($fournisseur) || $fournisseur === '') {
			$fichierReglages = cheminReglagesOAuthCourriel();
			if (is_readable($fichierReglages)) {
				$donnees = json_decode((string)file_get_contents($fichierReglages), true);
				if (is_array($donnees)) {
					$fournisseur = $donnees['fournisseur'] ?? '';
					$clientId = $donnees['client_id'] ?? '';
					$tenant = $donnees['tenant'] ?? '';
					$mailboxUser = $donnees['mailbox_user'] ?? '';
					$secretStocke = $donnees['client_secret'] ?? '';
					if ($secretStocke !== '') {
						$crypto = new Chiffrement();
						$clientSecret = $crypto->isConfigured() ? ($crypto->decrypt($secretStocke) ?? '') : $secretStocke;
					} else {
						$clientSecret = '';
					}
				}
			}
		}
		if (!is_string($fournisseur) || $fournisseur === '') {
			$fichierConfig = CONFIG_DIRECTORY.'config.php';
			if (is_readable($fichierConfig)) {
				include $fichierConfig;
				$fournisseur = $smtpoauthprovider ?? '';
				$clientId = $smtpoauthclientid ?? '';
				$clientSecret = $smtpoauthclientsecret ?? '';
				$tenant = $smtpoauthtenant ?? '';
			}
		}
		return [
			'fournisseur' => is_string($fournisseur) ? $fournisseur : '',
			'client_id' => is_string($clientId) ? $clientId : '',
			'client_secret' => is_string($clientSecret) ? $clientSecret : '',
			'tenant' => (is_string($tenant) && $tenant !== '') ? $tenant : 'common',
			'mailbox_user' => is_string($mailboxUser) ? $mailboxUser : '',
		];
	}
endif;

// Écrit les réglages choisis dans l'assistant web (?page=mailoauth) - voir
// reglagesOAuthCourriel() ci-dessus pour la lecture. Sans effet si reglagesOAuthForceesParEnv()
// est vrai (l'appelant doit vérifier avant d'afficher le formulaire).
//
// Writes the settings chosen in the web wizard (?page=mailoauth) - see
// reglagesOAuthCourriel() above for reading them back. Has no effect if
// reglagesOAuthForceesParEnv() is true (the caller should check before showing the form).
if (!function_exists('enregistrerReglagesOAuthCourriel')):
	function enregistrerReglagesOAuthCourriel($fournisseur, $mailboxUser, $clientId, $clientSecret, $tenant) {
		if (reglagesOAuthForceesParEnv()) {
			return false;
		}
		$crypto = new Chiffrement();
		$secretAStocker = ($clientSecret !== '' && $crypto->isConfigured()) ? $crypto->encrypt($clientSecret) : $clientSecret;
		$donnees = [
			'fournisseur' => $fournisseur,
			'mailbox_user' => $mailboxUser,
			'client_id' => $clientId,
			'client_secret' => $secretAStocker,
			'tenant' => $tenant,
		];
		$chemin = cheminReglagesOAuthCourriel();
		if (@file_put_contents($chemin, json_encode($donnees)) === false) {
			return false;
		}
		@chmod($chemin, 0600);
		return true;
	}
endif;

// Retire la configuration OAuth faite depuis l'assistant web (fournisseur/Client ID/Secret/
// adresse), SANS toucher au jeton de rafraîchissement déjà obtenu (voir
// supprimerJetonOAuthCourriel() plus bas pour ça) - permet de "recommencer à zéro" le choix du
// fournisseur sans perdre une autorisation déjà accordée si l'utilisateur revient au même
// fournisseur ensuite. Sans effet si reglagesOAuthForceesParEnv() est vrai.
//
// Removes the OAuth configuration made from the web wizard (provider/Client ID/Secret/
// address), WITHOUT touching an already-obtained refresh token (see
// supprimerJetonOAuthCourriel() below for that) - lets someone "start over" on the provider
// choice without losing an already-granted authorization if they come back to the same
// provider later. Has no effect if reglagesOAuthForceesParEnv() is true.
if (!function_exists('supprimerReglagesOAuthCourriel')):
	function supprimerReglagesOAuthCourriel() {
		if (reglagesOAuthForceesParEnv()) {
			return false;
		}
		$chemin = cheminReglagesOAuthCourriel();
		if (!file_exists($chemin)) {
			return true;
		}
		return @unlink($chemin);
	}
endif;

if (!function_exists('cheminJetonOAuthCourriel')):
	function cheminJetonOAuthCourriel() {
		return CONFIG_DIRECTORY.'mail-oauth-refresh-token.enc';
	}
endif;

if (!function_exists('enregistrerJetonOAuthCourriel')):
	function enregistrerJetonOAuthCourriel($refreshToken) {
		$crypto = new Chiffrement();
		$valeur = $crypto->isConfigured() ? $crypto->encrypt($refreshToken) : $refreshToken;
		$chemin = cheminJetonOAuthCourriel();
		if (@file_put_contents($chemin, $valeur) === false) {
			return false;
		}
		@chmod($chemin, 0600);
		return true;
	}
endif;

if (!function_exists('lireJetonOAuthCourriel')):
	function lireJetonOAuthCourriel() {
		$chemin = cheminJetonOAuthCourriel();
		if (!is_readable($chemin)) {
			return '';
		}
		$valeur = file_get_contents($chemin);
		if ($valeur === false || $valeur === '') {
			return '';
		}
		$crypto = new Chiffrement();
		return $crypto->isConfigured() ? ($crypto->decrypt($valeur) ?? '') : $valeur;
	}
endif;

if (!function_exists('supprimerJetonOAuthCourriel')):
	function supprimerJetonOAuthCourriel() {
		$chemin = cheminJetonOAuthCourriel();
		if (!file_exists($chemin)) {
			return true;
		}
		return @unlink($chemin);
	}
endif;

// Requête POST générique (form-urlencoded) vers un point de terminaison OAuth, utilisée par
// toutes les fonctions ci-dessous. Retourne le corps JSON décodé et le code HTTP ; un échec
// réseau produit un tableau vide avec code 0, jamais une exception - laisse chaque appelant
// décider comment réagir (l'erreur générique n'est pas toujours une vraie erreur, ex. :
// "authorization_pending" pendant l'attente côté Microsoft).
if (!function_exists('requeteOAuthPost')):
	function requeteOAuthPost($url, array $champs) {
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($champs));
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
		curl_setopt($ch, CURLOPT_TIMEOUT, 15);
		$reponse = curl_exec($ch);
		$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		$corps = $reponse !== false ? json_decode($reponse, true) : null;
		return ['code' => $code, 'corps' => is_array($corps) ? $corps : []];
	}
endif;

// --- Microsoft 365 : flux "device code" (aucun secret client requis) --------------------

if (!function_exists('demarrerAutorisationOAuthMicrosoft365')):
	function demarrerAutorisationOAuthMicrosoft365($clientId, $tenant) {
		$tenant = $tenant !== '' ? $tenant : 'common';
		$resultat = requeteOAuthPost(
			"https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/devicecode",
			[
				'client_id' => $clientId,
				'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
			]
		);
		if ($resultat['code'] !== 200 || empty($resultat['corps']['device_code'])) {
			return ['succes' => false, 'erreur' => $resultat['corps']['error_description'] ?? _("Impossible de démarrer l'autorisation Microsoft 365.")];
		}
		return [
			'succes' => true,
			'device_code' => $resultat['corps']['device_code'],
			'user_code' => $resultat['corps']['user_code'],
			'verification_uri' => $resultat['corps']['verification_uri'] ?? $resultat['corps']['verification_uri_complete'] ?? '',
			'interval' => $resultat['corps']['interval'] ?? 5,
			'expires_in' => $resultat['corps']['expires_in'] ?? 900,
		];
	}
endif;

if (!function_exists('verifierAutorisationOAuthMicrosoft365')):
	function verifierAutorisationOAuthMicrosoft365($clientId, $tenant, $deviceCode) {
		$tenant = $tenant !== '' ? $tenant : 'common';
		$resultat = requeteOAuthPost(
			"https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
			[
				'grant_type' => 'urn:ietf:params:oauth:grant-type:device_code',
				'client_id' => $clientId,
				'device_code' => $deviceCode,
			]
		);
		if ($resultat['code'] === 200 && !empty($resultat['corps']['refresh_token'])) {
			return ['succes' => true, 'refresh_token' => $resultat['corps']['refresh_token']];
		}
		$erreur = $resultat['corps']['error'] ?? '';
		if ($erreur === 'authorization_pending' || $erreur === 'slow_down') {
			return ['succes' => false, 'en_attente' => true];
		}
		return ['succes' => false, 'en_attente' => false, 'erreur' => $resultat['corps']['error_description'] ?? _("Échec de l'autorisation Microsoft 365.")];
	}
endif;

if (!function_exists('rafraichirJetonOAuthMicrosoft365')):
	function rafraichirJetonOAuthMicrosoft365($clientId, $tenant, $refreshToken) {
		$tenant = $tenant !== '' ? $tenant : 'common';
		$resultat = requeteOAuthPost(
			"https://login.microsoftonline.com/{$tenant}/oauth2/v2.0/token",
			[
				'grant_type' => 'refresh_token',
				'client_id' => $clientId,
				'refresh_token' => $refreshToken,
				'scope' => 'https://outlook.office.com/SMTP.Send offline_access',
			]
		);
		if ($resultat['code'] !== 200 || empty($resultat['corps']['access_token'])) {
			return ['succes' => false, 'erreur' => $resultat['corps']['error_description'] ?? _("Échec du rafraîchissement du jeton Microsoft 365.")];
		}
		return [
			'succes' => true,
			'access_token' => $resultat['corps']['access_token'],
			// Microsoft peut (mais ne doit pas nécessairement) renvoyer un nouveau jeton de
			// rafraîchissement à chaque appel - le conserver s'il est présent, garder l'ancien sinon.
			'refresh_token' => $resultat['corps']['refresh_token'] ?? $refreshToken,
		];
	}
endif;

// --- Google : flux "authorization code" avec redirection (nécessite un secret client) ---

if (!function_exists('construireUrlAutorisationGoogle')):
	function construireUrlAutorisationGoogle($clientId, $redirectUri, $state) {
		$parametres = [
			'client_id' => $clientId,
			'redirect_uri' => $redirectUri,
			'response_type' => 'code',
			'scope' => 'https://mail.google.com/',
			'access_type' => 'offline',
			// Force le consentement à chaque fois : sans ça, Google ne renvoie un
			// refresh_token qu'à la toute première autorisation d'un compte.
			'prompt' => 'consent',
			'state' => $state,
		];
		return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($parametres);
	}
endif;

if (!function_exists('echangerCodeOAuthGoogle')):
	function echangerCodeOAuthGoogle($clientId, $clientSecret, $code, $redirectUri) {
		$resultat = requeteOAuthPost(
			'https://oauth2.googleapis.com/token',
			[
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'code' => $code,
				'redirect_uri' => $redirectUri,
				'grant_type' => 'authorization_code',
			]
		);
		if ($resultat['code'] !== 200 || empty($resultat['corps']['refresh_token'])) {
			return ['succes' => false, 'erreur' => $resultat['corps']['error_description'] ?? _("Échec de l'autorisation Google.")];
		}
		return ['succes' => true, 'refresh_token' => $resultat['corps']['refresh_token']];
	}
endif;

if (!function_exists('rafraichirJetonOAuthGoogle')):
	function rafraichirJetonOAuthGoogle($clientId, $clientSecret, $refreshToken) {
		$resultat = requeteOAuthPost(
			'https://oauth2.googleapis.com/token',
			[
				'client_id' => $clientId,
				'client_secret' => $clientSecret,
				'refresh_token' => $refreshToken,
				'grant_type' => 'refresh_token',
			]
		);
		if ($resultat['code'] !== 200 || empty($resultat['corps']['access_token'])) {
			return ['succes' => false, 'erreur' => $resultat['corps']['error_description'] ?? _("Échec du rafraîchissement du jeton Google.")];
		}
		return [
			'succes' => true,
			'access_token' => $resultat['corps']['access_token'],
			// Google ne renvoie généralement pas de nouveau refresh_token au rafraîchissement,
			// mais s'il en fournit un, on le conserve.
			'refresh_token' => $resultat['corps']['refresh_token'] ?? $refreshToken,
		];
	}
endif;
