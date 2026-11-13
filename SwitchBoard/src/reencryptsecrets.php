<?php
// Script CLI facultatif : chiffre immédiatement tous les secrets encore en clair
// (mot de passe API voip.ms, jeton SBChat, identifiants MAS) plutôt que d'attendre
// que chaque utilisateur les ressaisisse. À exécuter une seule fois après avoir
// configuré APP_ENCRYPTION_KEY (ou $appencryptionkey dans config/config.php) sur un
// déploiement existant.
// ex: /usr/bin/php -q /var/www/html/switchboard/src/reencryptsecrets.php
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
requireCliOrCronKey();

// Sans clé configurée, le chiffrement est inactif : reencryptAllSecrets() ne ferait
// rien et retournerait 0, ce qui ressemblerait à un succès. On refuse explicitement
// (avant même de se connecter à la base) plutôt que de laisser croire que les secrets
// ont été chiffrés.
$cryptomanager = new Chiffrement();
if (!$cryptomanager->isConfigured()) {
	echo "ERREUR : aucune clé de chiffrement configurée - aucun secret n'a été chiffré.\n\n";
	echo "Configurez d'abord la clé, puis relancez ce script :\n";
	echo "  1. Générez une clé : php -r \"echo base64_encode(random_bytes(32));\"\n";
	echo "  2. Renseignez-la, soit dans config/config.php (\$appencryptionkey = \"...\";),\n";
	echo "     soit via la variable d'environnement APP_ENCRYPTION_KEY.\n";
	echo "  3. SAUVEGARDEZ CETTE CLÉ avec vos sauvegardes de base de données : sans elle,\n";
	echo "     les secrets chiffrés deviennent irrécupérables.\n";
	exit(1);
}

$databasemanager = new DatabaseManager();
$nb = $databasemanager->reencryptAllSecrets();
echo "Terminé : $nb ligne(s) chiffrée(s) ou mise(s) à jour.\n";
if ($nb === 0) {
	echo "(Aucune valeur en clair restante : les secrets étaient déjà chiffrés.)\n";
}
