#!/usr/bin/env php
<?php
// La suite de #77 pour la page du certificat SwitchBoard Chat / MAS : approuver et retirer un certificat répondent par une
// redirection vers la même page en GET (F5 ne les refait pas, ce qui est dit s'affiche une seule fois, un envoi sans jeton
// est refusé). L'aperçu (coller un certificat) ne change rien : il s'affiche tel quel, sans rien écrire.
require_once '/banc/commun/banc.php';

repartirAZero();
connecterAuPortail('admin');

function envoyer(string $adresse, array $champs): array {
	return requetePortail($adresse, $champs + ['csrf_token' => jetonDuFormulaire($adresse)]);
}

// Un vrai certificat auto-signé, fait ici : il se lit par OpenSSL, rien d'autre ne compte
$cle = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$demande = openssl_csr_new(['commonName' => 'sbchat.banc.test'], $cle);
openssl_x509_export(openssl_csr_sign($demande, null, $cle, 2), $pem);
$fichier = APPLI.'/config/sbchat-ca-bundle.pem';
@unlink($fichier);

$adresse = PORTAIL.'?page=sbchatcertificate';
$retour = 'managementportal.php?page=sbchatcertificate';
verifie(requetePortail($adresse)['code'] === 200, "la page du certificat s'affiche");

// --- Aperçu : rien n'est écrit ---
$apercu = envoyer($adresse, ['action' => 'paste', 'certificat_pem' => $pem]);
verifie($apercu['code'] === 200 && str_contains($apercu['page'], 'sbchat.banc.test'), "aperçu : le certificat collé est montré (code {$apercu['code']})");
verifie(!file_exists($fichier), "aperçu : aucun certificat n'est enregistré");

// --- Approuver ---
$sansJeton = requetePortail($adresse, ['action' => 'confirm', 'certificat_pem' => $pem]);
verifie($sansJeton['code'] === 403 && !file_exists($fichier), "approuver, sans jeton : refusé, rien n'est enregistré (code {$sansJeton['code']})");
$reponse = envoyer($adresse, ['action' => 'confirm', 'certificat_pem' => $pem]);
verifie(redirigeVers($reponse, $retour), "approuver : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(file_exists($fichier) && trim((string)file_get_contents($fichier)) === trim($pem), "approuver : le certificat est enregistré tel quel");
verifie(lectures($adresse, 'Certificat approuvé avec succès.') === [1, 0], "approuver : le résultat est dit une fois, pas au rechargement");
verifie(str_contains(requetePortail($adresse)['page'], 'sbchat.banc.test'), "approuver : la page montre le certificat approuvé après la redirection");

// --- Approuver un texte qui n'est pas un certificat : refusé, l'erreur est dite une fois, le certificat en place reste ---
$avant = (string)file_get_contents($fichier);
$reponse = envoyer($adresse, ['action' => 'confirm', 'certificat_pem' => 'pas un certificat']);
verifie(redirigeVers($reponse, $retour), "approuver un texte invalide : redirection vers la même page (code {$reponse['code']})");
verifie(lectures($adresse, 'au format PEM') === [1, 0], "approuver un texte invalide : l'erreur est dite une fois");
verifie((string)file_get_contents($fichier) === $avant, "approuver un texte invalide : le certificat en place ne change pas");

// --- Retirer ---
$sansJeton = requetePortail($adresse, ['action' => 'supprimer']);
verifie($sansJeton['code'] === 403 && file_exists($fichier), "retirer, sans jeton : refusé, le certificat reste (code {$sansJeton['code']})");
$reponse = envoyer($adresse, ['action' => 'supprimer']);
verifie(redirigeVers($reponse, $retour), "retirer : redirection vers la même page (code {$reponse['code']}, ".enTete($reponse, 'Location').")");
verifie(!file_exists($fichier), "retirer : le certificat est retiré");
verifie(lectures($adresse, 'Certificat approuvé retiré.') === [1, 0], "retirer : le résultat est dit une fois, pas au rechargement");

finDuScenario();
