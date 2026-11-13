<?php
// Script CLI appelé par msmtp via "passwordeval" (voir Docker/generate_mail_config.sh) à
// CHAQUE envoi de courriel : msmtp traite la sortie standard de ce script comme le mot de
// passe SMTP. Échange le jeton de rafraîchissement stocké (config/mail-oauth-refresh-token.enc,
// voir commun/oauthcourriel.php) contre un jeton d'accès frais (valide ~1h) auprès du
// fournisseur configuré, et n'écrit QUE ce jeton sur la sortie standard - tout diagnostic va
// sur la sortie d'erreur, jamais sur la sortie standard, pour ne jamais polluer le "mot de
// passe" que msmtp lira.
// ex: /usr/bin/php -q /var/www/html/switchboard/src/mailoauthtoken.php
//
// CLI script msmtp calls via "passwordeval" (see Docker/generate_mail_config.sh) on EVERY
// mail send: msmtp treats this script's standard output as the SMTP password. Exchanges the
// stored refresh token (config/mail-oauth-refresh-token.enc, see commun/oauthcourriel.php)
// for a fresh (~1h valid) access token from the configured provider, and writes ONLY that
// token to stdout - any diagnostic goes to stderr, never stdout, so it never pollutes the
// "password" msmtp will read.
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
requireCliOrCronKey();

$reglages = reglagesOAuthCourriel();
$fournisseur = $reglages['fournisseur'];
$clientId = $reglages['client_id'];
$clientSecret = $reglages['client_secret'];
$tenant = $reglages['tenant'];

$refreshToken = lireJetonOAuthCourriel();
if ($fournisseur === '' || $clientId === '' || $refreshToken === '') {
	fwrite(STDERR, "mailoauthtoken: courriel OAuth non configuré ou non autorisé (voir la console admin, Courriel OAuth).\n");
	exit(1);
}

if ($fournisseur === 'microsoft365') {
	$resultat = rafraichirJetonOAuthMicrosoft365($clientId, $tenant, $refreshToken);
} elseif ($fournisseur === 'google') {
	$resultat = rafraichirJetonOAuthGoogle($clientId, $clientSecret, $refreshToken);
} else {
	fwrite(STDERR, "mailoauthtoken: fournisseur SB_SMTP_OAUTH_PROVIDER inconnu : $fournisseur\n");
	exit(1);
}

if (empty($resultat['succes'])) {
	fwrite(STDERR, "mailoauthtoken: échec du rafraîchissement - ".($resultat['erreur'] ?? 'erreur inconnue')."\n");
	exit(1);
}

// Le jeton de rafraîchissement peut avoir été remplacé par le fournisseur (rotation) :
// on l'enregistre systématiquement, même si sa valeur n'a pas changé (coût négligeable,
// évite un bug si un fournisseur venait à changer sa politique de rotation sans prévenir).
enregistrerJetonOAuthCourriel($resultat['refresh_token']);

echo $resultat['access_token']."\n";
