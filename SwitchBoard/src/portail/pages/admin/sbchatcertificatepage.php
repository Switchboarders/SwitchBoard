<?php

// Remplace le processus manuel (extraire le certificat avec openssl s_client, le transférer
// sur le serveur, éditer config.php) par un bouton dans la console admin : récupère
// directement le certificat présenté par le serveur SBChat/MAS de l'administrateur connecté,
// ou permet d'en coller un manuellement, puis l'enregistre via enregistrerCertificatSbchatGere()
// (commun/certificatssbchat.php), lu en priorité par sbchatCaBundle().
//
// L'approbation se fait en DEUX étapes (aperçu puis confirmation) : un premier incident réel a
// montré qu'une adresse de serveur mal configurée (port manquant, retombant silencieusement sur
// 443) peut faire récupérer le certificat d'un tout autre service - approuver ce certificat
// automatiquement, sans le montrer d'abord, aurait fait confiance à la mauvaise machine sans que
// l'administrateur ne s'en rende compte. L'aperçu affiche donc l'hôte:port réellement contacté et
// avertit explicitement si le certificat obtenu ne couvre pas ce nom d'hôte.
//
// Replaces the manual process (extract the certificate with openssl s_client, transfer it to
// the server, edit config.php) with a button in the admin console: fetches the certificate the
// current admin's SBChat/MAS server currently presents, or lets one be pasted manually, then
// saves it via enregistrerCertificatSbchatGere() (commun/certificatssbchat.php), read with priority
// by sbchatCaBundle().
//
// Approval happens in TWO steps (preview then confirm): a real incident showed that a
// misconfigured server address (missing port, silently falling back to 443) can fetch a
// completely different service's certificate - auto-approving it without showing it first would
// have trusted the wrong machine without the admin noticing. The preview therefore shows the
// actual host:port that was contacted and explicitly warns if the certificate obtained doesn't
// cover that hostname.

$dbmanager = new DatabaseManager();
$currentuserid = $dbmanager->getcurrentuserid();
$sbchatinfo = $dbmanager->getSbchatinfobyId($currentuserid);
$sbchatserveraddress = $sbchatinfo['sbchatserveraddress'] ?? '';

// Si SB_SBCHAT_CA_BUNDLE est définie (typiquement un déploiement Docker déjà câblé), elle est
// TOUJOURS prioritaire sur ce que cette page enregistre (voir sbchatCaBundle() dans
// commun/certificatssbchat.php) : approuver un certificat ici n'aurait alors aucun effet visible,
// ce qui serait déroutant. On avertit donc au lieu d'offrir des boutons sans effet, sur le même
// principe que modeDebugForceParEnv() pour le mode debug.
$envCaBundle = getenv('SB_SBCHAT_CA_BUNDLE');
$envCaBundleActif = is_string($envCaBundle) && $envCaBundle !== '';

// Vérifie si le certificat couvre bien le nom d'hôte contacté (SAN, ou CN à défaut pour les
// vieux certificats sans extension SAN). Purement informatif : n'empêche jamais l'approbation,
// seulement un avertissement avant de confirmer.
function certificatCorrespondHote($details, $hote) {
	if ($hote === '') {
		return null;
	}
	$hote = strtolower($hote);
	if ($details['san'] !== '') {
		foreach (explode(',', $details['san']) as $entree) {
			$entree = strtolower(trim($entree));
			if ($entree === 'dns:'.$hote) {
				return true;
			}
		}
		return false;
	}
	return strtolower($details['sujet']) === $hote;
}

// Ce que l'enregistrement précédent a dit (voir terminerEnregistrement()), une seule fois
$valeurs = valeursDEnregistrement();
$messageErreur = (string)($valeurs['erreur'] ?? '');
$messageSucces = (string)($valeurs['succes'] ?? '');
$certificatRecu = null;
$apercu = null; // ['pem' => ..., 'details' => ..., 'hote' => ..., 'port' => ..., 'correspond' => bool|null]

// Approuver et retirer un certificat enregistrent : la page répond par une redirection (#77), F5 ne les refait pas.
// L'aperçu (récupérer, coller) ne change rien : il s'affiche tel quel.
$enregistre = in_array($_POST['action'] ?? '', ['confirm', 'supprimer'], true);
if ($enregistre) {
	requireCsrf();
	commencerEnregistrement();
}
if (!empty($_POST['action']) && $envCaBundleActif) {
	requireCsrf();
	$messageErreur = _("SB_SBCHAT_CA_BUNDLE est définie et prend priorité : retirez-la d'abord pour gérer le certificat depuis cette page.");
} elseif (!empty($_POST['action'])) {
	requireCsrf();
	if ($_POST['action'] === 'fetch') {
		if (empty($sbchatserveraddress)) {
			$messageErreur = _("Configurez d'abord l'adresse du serveur SwitchBoard Chat dans « Informations SwitchBoard Chat du compte » avant de récupérer son certificat.");
		} else {
			$morceaux = parse_url($sbchatserveraddress);
			$hote = $morceaux['host'] ?? '';
			$port = $morceaux['port'] ?? 443;
			$resultat = recupererCertificatServeur($hote, $port);
			if (!empty($resultat['erreur'])) {
				$messageErreur = sprintf(_("Impossible de récupérer le certificat : %s"), $resultat['erreur']);
			} else {
				$probleme = problemeCertificatPem($resultat['pem']);
				if ($probleme !== '') {
					$messageErreur = $probleme;
				} else {
					$details = analyserCertificatPem($resultat['pem']);
					$apercu = [
						'pem' => $resultat['pem'],
						'details' => $details,
						'hote' => $hote,
						'port' => $port,
						'correspond' => certificatCorrespondHote($details, $hote),
					];
				}
			}
		}
	} elseif ($_POST['action'] === 'paste') {
		$pem = $_POST['certificat_pem'] ?? '';
		$probleme = problemeCertificatPem($pem);
		if ($probleme !== '') {
			$messageErreur = $probleme;
		} else {
			$morceaux = parse_url($sbchatserveraddress);
			$hote = $morceaux['host'] ?? '';
			$details = analyserCertificatPem($pem);
			$apercu = [
				'pem' => $pem,
				'details' => $details,
				'hote' => $hote,
				'port' => $morceaux['port'] ?? null,
				'correspond' => certificatCorrespondHote($details, $hote),
			];
		}
	} elseif ($_POST['action'] === 'confirm') {
		$pem = $_POST['certificat_pem'] ?? '';
		$probleme = problemeCertificatPem($pem);
		if ($probleme !== '') {
			$messageErreur = $probleme;
		} elseif (enregistrerCertificatSbchatGere($pem)) {
			$messageSucces = _("Certificat approuvé avec succès.");
			$certificatRecu = analyserCertificatPem($pem);
		} else {
			$messageErreur = _("Le certificat n'a pas pu être enregistré (vérifiez les droits d'écriture sur le dossier config/).");
		}
	} elseif ($_POST['action'] === 'supprimer') {
		if (supprimerCertificatSbchatGere()) {
			$messageSucces = _("Certificat approuvé retiré. SwitchBoard utilisera désormais la confiance normale du système d'exploitation (ou config.php, si un réglage manuel y est défini).");
		} else {
			$messageErreur = _("Le certificat n'a pas pu être retiré (vérifiez les droits d'écriture sur le dossier config/).");
		}
	}
}
if ($enregistre) {
	terminerEnregistrement(['erreur' => $messageErreur, 'succes' => $messageSucces]);
}

// Certificat RÉELLEMENT utilisé en ce moment (indépendant de ce qui vient d'être soumis
// ci-dessus) : sbchatCaBundle() applique la même priorité que la connexion SBChat/MAS réelle
// (env var, puis cette page, puis config.php), donc ce qui s'affiche ici correspond toujours à
// ce qui est effectivement approuvé, même si ce n'est pas celui géré par cette page.
$cheminEffectif = sbchatCaBundle();
$certificatActuel = null;
if ($cheminEffectif !== '' && is_readable($cheminEffectif)) {
	$pemActuel = file_get_contents($cheminEffectif);
	if ($pemActuel !== false && $pemActuel !== '') {
		$certificatActuel = analyserCertificatPem($pemActuel);
	}
}
// Le certificat géré par cette page peut être retiré (bouton "Supprimer" ci-dessous) qu'il soit
// ou non celui actuellement effectif (ex.: masqué par une variable d'environnement) - on
// détecte donc sa présence indépendamment de $certificatActuel.
$certificatGereExiste = is_readable(cheminCertificatSbchatGere());
// Est-ce que le certificat effectif EST celui géré par cette page (pas config.php ou l'env) ?
$estLeCertificatGere = ($cheminEffectif !== '' && $cheminEffectif === cheminCertificatSbchatGere());

function afficherDetailsCertificat($details) {
	echo "<ul>";
	echo "<li>"._("Sujet : ").htmlspecialchars($details['sujet'])."</li>";
	echo "<li>"._("Émetteur : ").htmlspecialchars($details['emetteur']).($details['auto_signe'] ? " ("._("auto-signé").")" : "")."</li>";
	echo "<li>"._("Noms alternatifs (SAN) : ").htmlspecialchars($details['san'] !== '' ? $details['san'] : _("aucun"))."</li>";
	echo "<li>"._("Valide jusqu'au : ").htmlspecialchars($details['expiration']);
	if ($details['expire_bientot']) {
		echo " <strong style='color:orange;'>"._("(expire bientôt !)")."</strong>";
	}
	echo "</li>";
	echo "<li>"._("Empreinte SHA-256 : ")."<code>".htmlspecialchars($details['empreinte'])."</code></li>";
	echo "</ul>";
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/sbchatcertificatepage.vue.php';
