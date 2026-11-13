<?php
// Autorisation OAuth2 pour le courriel (Microsoft 365 / Google), voir commun/oauthcourriel.php
// pour les fonctions d'échange avec chaque fournisseur et mailoauthtoken.php pour la partie
// qui s'exécute à chaque envoi. Cette page est un assistant complet : elle permet de choisir
// le fournisseur, guide l'inscription de l'application chez ce fournisseur, enregistre le
// Client ID/Secret depuis un formulaire (aucun fichier à éditer à la main), puis obtient et
// stocke le jeton de rafraîchissement une première fois - le rafraîchissement du jeton d'accès
// ensuite est entièrement automatique (voir Docker/generate_mail_config.sh et
// mailoauthtoken.php).
//
// OAuth2 authorization for mail (Microsoft 365 / Google), see commun/oauthcourriel.php for the
// exchange functions for each provider and mailoauthtoken.php for the part that runs on every
// send. This page is a complete wizard: it lets you choose the provider, walks you through
// registering the app with that provider, saves the Client ID/Secret from a form (no file to
// hand-edit), then obtains and stores the refresh token once - refreshing the access token
// afterwards is fully automatic (see Docker/generate_mail_config.sh and mailoauthtoken.php).

$reglages = reglagesOAuthCourriel();
$fournisseur = $reglages['fournisseur'];
$clientId = $reglages['client_id'];
$clientSecret = $reglages['client_secret'];
$tenant = $reglages['tenant'];
$mailboxUser = $reglages['mailbox_user'];
$geeParEnv = reglagesOAuthForceesParEnv();
// Même construction que getusertokenlink() (tokenaccess.php) : URL absolue vers CETTE page,
// utilisée à la fois comme redirect_uri envoyé à Google et comme URL sur laquelle Google
// redirige - les deux doivent être identiques pour que Google accepte l'échange du code.
$urlCettePage = "https://".$_SERVER['HTTP_HOST'].$_SERVER['PHP_SELF']."?page=mailoauth";

// /etc/msmtprc est régénéré par generate_mail_config.sh SEULEMENT au démarrage du conteneur php
// (Docker) - lu ici directement, dans ce même conteneur, pour savoir si le mode OAuth y est déjà
// actif. S'il ne l'est pas encore (réglages/autorisation faits depuis cet assistant après le
// dernier démarrage - y compris le cas où le fichier n'existe pas du tout, courriel jamais
// configuré avant), l'envoi de courriel utilisera encore l'ancien mode tant que le conteneur
// n'aura pas redémarré une fois - contrairement à l'autorisation elle-même, qui prend effet
// immédiatement. Non pertinent si $fournisseur est vide (rien à activer) ; pour une installation
// manuelle, mailoauthtoken.php relit déjà la config à chaque envoi, mais on ne peut pas savoir
// d'ici si le msmtp externe a été configuré pour OAuth - le message ne s'affichera de toute façon
// que dans le cas Docker (où /etc/msmtprc, dans CE conteneur, fait foi).
// La simple présence de "auth xoauth2" ne suffit pas : si une PREMIÈRE boîte a déjà été
// autorisée et le conteneur redémarré une fois, /etc/msmtprc contient déjà "auth xoauth2" en
// permanence - changer ensuite de boîte (nouvelle adresse autorisée depuis cet assistant) ne
// serait alors jamais détecté comme nécessitant un redémarrage, alors que /etc/msmtprc
// continuerait d'utiliser l'ANCIENNE adresse comme "user" tout en tentant de s'authentifier
// avec le jeton de la NOUVELLE boîte - Microsoft/Google refusent ça avec une erreur 535
// d'authentification (vérifié en conditions réelles). On compare donc aussi la boîte active.
//
// Just checking for "auth xoauth2" isn't enough: once a FIRST mailbox has been authorized and
// the container restarted once, /etc/msmtprc keeps "auth xoauth2" permanently - switching to a
// different mailbox afterwards (newly authorized from this wizard) would then never be
// detected as needing a restart, while /etc/msmtprc would keep using the OLD address as "user"
// while trying to authenticate with the NEW mailbox's token - Microsoft/Google reject that with
// a 535 authentication error (confirmed against a real deployment). So the active mailbox is
// compared too.
$msmtprc = @file_get_contents('/etc/msmtprc');
$utilisateurActifMsmtprc = null;
if ($msmtprc !== false && preg_match('/^user\s+(.+)$/m', $msmtprc, $matchUtilisateur)) {
	$utilisateurActifMsmtprc = trim($matchUtilisateur[1]);
}
$redemarrageRequis = ($fournisseur !== '') && (
	$msmtprc === false
	|| strpos($msmtprc, 'auth xoauth2') === false
	|| $utilisateurActifMsmtprc !== $mailboxUser
);
$noteRedemarrage = _(" Pour que l'envoi de courriel utilise réellement ce mode OAuth, redémarrez le conteneur php une fois : « docker compose restart php » (depuis le dossier Docker/) - sans ce redémarrage, l'autorisation est bien enregistrée mais l'envoi continue d'utiliser l'ancien réglage jusque-là.");

// Ce que l'enregistrement qui vient d'avoir lieu a dit (voir terminerEnregistrement()) : lu une seule fois
$suite = valeursDEnregistrement();
$messageErreur = (string)($suite['erreur'] ?? '');
$messageSucces = (string)($suite['succes'] ?? '');
$apercuMs365 = null; // ['user_code' => ..., 'verification_uri' => ...]

// Une action de cette page (un POST, ou le retour de Google dans l'adresse) répond par une redirection vers la page en GET (#77) :
// F5 ou le bouton Précédent ne renvoient ni l'action ni le code d'autorisation de Google.
$action = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' ? (string)($_POST['action'] ?? '') : '';
$retourDeGoogle = $fournisseur === 'google' && ((!empty($_GET['code']) && !empty($_GET['state'])) || !empty($_GET['error']));
$agit = $action !== '' || $retourDeGoogle;
if ($agit) {
	commencerEnregistrement();
}

if (!$geeParEnv && !empty($_POST['action']) && $_POST['action'] === 'enregistrer_reglages') {
	requireCsrf();
	$fournisseurSoumis = $_POST['fournisseur'] ?? '';
	$mailboxSoumis = trim((string)($_POST['mailbox_user'] ?? ''));
	$clientIdSoumis = trim((string)($_POST['client_id'] ?? ''));
	$clientSecretSoumis = trim((string)($_POST['client_secret'] ?? ''));
	$tenantSoumis = trim((string)($_POST['tenant'] ?? ''));

	if (!in_array($fournisseurSoumis, ['microsoft365', 'google'], true)) {
		$messageErreur = _("Choisissez un fournisseur (Microsoft 365 ou Google).");
	} elseif ($mailboxSoumis === '' || $clientIdSoumis === '') {
		$messageErreur = _("L'adresse courriel et le Client ID sont requis.");
	} elseif ($fournisseurSoumis === 'google' && $clientSecretSoumis === '') {
		$messageErreur = _("Le Client Secret est requis pour Google.");
	} else {
		if (enregistrerReglagesOAuthCourriel($fournisseurSoumis, $mailboxSoumis, $clientIdSoumis, $clientSecretSoumis, $tenantSoumis)) {
			$fournisseur = $fournisseurSoumis;
			$mailboxUser = $mailboxSoumis;
			$clientId = $clientIdSoumis;
			$clientSecret = $clientSecretSoumis;
			$tenant = $tenantSoumis !== '' ? $tenantSoumis : 'common';
			$messageSucces = _("Réglages enregistrés.");
		} else {
			$messageErreur = _("Impossible d'enregistrer les réglages (vérifiez les droits d'écriture sur le dossier config/).");
		}
	}
}

if ($fournisseur === 'microsoft365') {
	if (!empty($_POST['action']) && $_POST['action'] === 'demarrer') {
		requireCsrf();
		$resultat = demarrerAutorisationOAuthMicrosoft365($clientId, $tenant);
		if (empty($resultat['succes'])) {
			$messageErreur = $resultat['erreur'];
		} else {
			// Le code et l'URL sont aussi gardés en session (pas seulement dans $apercuMs365,
			// une variable locale à CETTE requête) pour rester affichés si l'utilisateur recharge
			// la page ou revient plus tard - sans ça, une fois la réponse de "démarrer" passée, il
			// n'y avait plus aucun moyen de revoir le code à entrer chez Microsoft.
			$_SESSION['mailoauth_device_code'] = $resultat['device_code'];
			$_SESSION['mailoauth_device_user_code'] = $resultat['user_code'];
			$_SESSION['mailoauth_device_verification_uri'] = $resultat['verification_uri'];
			$apercuMs365 = ['user_code' => $resultat['user_code'], 'verification_uri' => $resultat['verification_uri']];
		}
	} elseif (!empty($_POST['action']) && $_POST['action'] === 'verifier') {
		requireCsrf();
		$deviceCode = $_SESSION['mailoauth_device_code'] ?? '';
		if ($deviceCode === '') {
			$messageErreur = _("Aucune autorisation en cours - cliquez d'abord sur « Démarrer l'autorisation ».");
		} else {
			$resultat = verifierAutorisationOAuthMicrosoft365($clientId, $tenant, $deviceCode);
			if (!empty($resultat['succes'])) {
				enregistrerJetonOAuthCourriel($resultat['refresh_token']);
				unset($_SESSION['mailoauth_device_code'], $_SESSION['mailoauth_device_user_code'], $_SESSION['mailoauth_device_verification_uri']);
				$messageSucces = _("Microsoft 365 autorisé avec succès.").($redemarrageRequis ? $noteRedemarrage : '');
			} elseif (!empty($resultat['en_attente'])) {
				$messageErreur = _("Pas encore autorisé. Assurez-vous d'avoir terminé l'étape dans votre navigateur, puis cliquez de nouveau sur « J'ai autorisé, continuer ». Si vous vous êtes trompé en entrant le code chez Microsoft, utilisez « Annuler et recommencer » ci-dessous pour obtenir un nouveau code.");
			} else {
				unset($_SESSION['mailoauth_device_code'], $_SESSION['mailoauth_device_user_code'], $_SESSION['mailoauth_device_verification_uri']);
				$messageErreur = $resultat['erreur'];
			}
		}
	} elseif (!empty($_POST['action']) && $_POST['action'] === 'annuler_device_code') {
		requireCsrf();
		unset($_SESSION['mailoauth_device_code'], $_SESSION['mailoauth_device_user_code'], $_SESSION['mailoauth_device_verification_uri']);
	}
} elseif ($fournisseur === 'google') {
	if (!empty($_GET['code']) && !empty($_GET['state'])) {
		if (empty($_SESSION['mailoauth_google_state']) || !hash_equals($_SESSION['mailoauth_google_state'], $_GET['state'])) {
			$messageErreur = _("État OAuth invalide (état manquant ou expiré) - relancez l'autorisation ci-dessous.");
		} else {
			unset($_SESSION['mailoauth_google_state']);
			$resultat = echangerCodeOAuthGoogle($clientId, $clientSecret, $_GET['code'], $urlCettePage);
			if (!empty($resultat['succes'])) {
				enregistrerJetonOAuthCourriel($resultat['refresh_token']);
				$messageSucces = _("Google autorisé avec succès.").($redemarrageRequis ? $noteRedemarrage : '');
			} else {
				$messageErreur = $resultat['erreur'];
			}
		}
	} elseif (!empty($_GET['error'])) {
		$messageErreur = sprintf(_("Autorisation refusée par Google : %s"), htmlspecialchars($_GET['error']));
	}
}

if (!$geeParEnv && !empty($_POST['action']) && $_POST['action'] === 'supprimer_reglages') {
	requireCsrf();
	supprimerReglagesOAuthCourriel();
	supprimerJetonOAuthCourriel();
	$fournisseur = '';
	$messageSucces = _("Réglages retirés - choisissez de nouveau un fournisseur ci-dessous.");
}

if (!empty($_POST['action']) && $_POST['action'] === 'supprimer') {
	requireCsrf();
	if (supprimerJetonOAuthCourriel()) {
		$messageSucces = _("Autorisation retirée.");
	} else {
		$messageErreur = _("L'autorisation n'a pas pu être retirée (vérifiez les droits d'écriture sur le dossier config/).");
	}
}

if ($agit) {
	// Une erreur sur le formulaire des réglages le laisse ouvert, comme avant
	terminerEnregistrement(['erreur' => $messageErreur, 'succes' => $messageSucces], null, $messageErreur !== '' && !empty($_GET['modifier']) ? ['modifier' => '1'] : []);
}

$jetonPresent = lireJetonOAuthCourriel() !== '';
// Formulaire de réglages affiché si : rien n'est configuré encore, ou l'utilisateur a cliqué
// "Modifier les réglages" - jamais si géré par .env (rien à éditer depuis cette page alors).
$afficherFormulaireReglages = !$geeParEnv && ($fournisseur === '' || !empty($_GET['modifier']));

// Ce que la vue montre pour un fournisseur configuré (ni géré par l'environnement, ni en modification des réglages)
// Source unique pour le code/URL affichés : $apercuMs365 juste après "démarrer", ou la
// session si la page est rechargée/revisitée ensuite - sans ça, le code n'était visible
// qu'une seule fois et devenait impossible à retrouver après un rechargement.
$codeEnSession = !empty($_SESSION['mailoauth_device_code']);
$codeEnCours = $apercuMs365 ?: ($codeEnSession ? [
	'user_code' => $_SESSION['mailoauth_device_user_code'] ?? '',
	'verification_uri' => $_SESSION['mailoauth_device_verification_uri'] ?? '',
] : null);
// Google : l'adresse d'autorisation porte un « state » neuf, gardé en session pour le retour de Google
$urlGoogle = '';
if (!$geeParEnv && !$afficherFormulaireReglages && $fournisseur === 'google') {
	$state = bin2hex(random_bytes(16));
	$_SESSION['mailoauth_google_state'] = $state;
	$urlGoogle = construireUrlAutorisationGoogle($clientId, $urlCettePage, $state);
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/mailoauthpage.vue.php';
