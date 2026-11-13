<?php

// Informations liées à la base de données
// Database login info
$dbservername = "localhost";
$dbusername = "";
$dbpassword = "";
$dbname = "" ;

// Jeton d'accès GitHub utilisé UNIQUEMENT par la mise à jour automatique.
// GitHub access token, used ONLY by the automatic update mechanism.
//
// Laisser vide si le dépôt SwitchBoard est public : la mise à jour fonctionne alors
// sans jeton. Un jeton n'est requis que pour un dépôt privé.
// Leave empty if the SwitchBoard repository is public; a token is only needed for a
// private repository.
//
// Ne jamais écrire ce jeton dans le code source ni le publier dans un dépôt.
// Never put this token in the source code, and never commit it to a repository.
// Peut aussi être fourni par la variable d'environnement SB_GITHUB_TOKEN (ou GITHUB_TOKEN,
// fournie ainsi par la stack Docker de ce projet - voir Docker/.env_sample).
$githubtoken = "";

// Clé de chiffrement des secrets stockés en BD (mot de passe API voip.ms, jeton SBChat,
// identifiants MAS). Générer avec : php -r "echo base64_encode(random_bytes(32));"
// Peut aussi être fournie par la variable d'environnement APP_ENCRYPTION_KEY.
//
// IMPORTANT : sans cette clé, ces secrets restent stockés en clair dans la base de
// données (comportement historique). Une fois la clé configurée, NE JAMAIS LA PERDRE :
// sans elle, les secrets déjà chiffrés deviennent illisibles et les utilisateurs
// devront ressaisir leurs identifiants voip.ms/MAS. Conservez-la avec vos sauvegardes.
$appencryptionkey = "";

// Clé partagée permettant de déclencher les scripts de tâches (src/*.php, ex :
// sendnotificationsmss.php) via une requête web plutôt qu'en ligne de commande.
// Utile seulement pour un hébergement (ex : cPanel) où le cron ne peut appeler
// que des URL. Laisser vide si les tâches sont exécutées en CLI (recommandé).
// Peut aussi être fournie par la variable d'environnement CRON_WEB_KEY.
$cronwebkey = "";

// Affiche les erreurs PHP à l'écran (chemins serveur, requêtes SQL, etc.) plutôt que
// seulement les journaliser. À activer UNIQUEMENT en développement, jamais en
// production. Peut aussi être fourni par la variable d'environnement SB_DEBUG=1.
$modedebug = false;

// Certificat TLS du serveur SwitchBoard Chat / MAS.
// Nécessaire seulement si votre serveur SBChat utilise un certificat AUTO-SIGNÉ (sinon
// laisser les deux valeurs telles quelles : la vérification TLS fonctionne d'elle-même).
//
// RECOMMANDÉ : indiquer ici le chemin du certificat auto-signé à approuver. La
// vérification TLS reste active, votre certificat est simplement reconnu comme valide.
// Peut aussi être fourni par la variable d'environnement SB_SBCHAT_CA_BUNDLE.
$sbchatcabundle = "";

// DERNIER RECOURS : désactive complètement la vérification TLS vers le serveur SBChat/MAS.
// Les jetons SBChat/MAS transitent sur cette connexion : sans vérification, elle est
// exposée à une interception (MITM). À réserver à un réseau de confiance ou au
// développement. Préférez $sbchatcabundle ci-dessus.
// Peut aussi être fourni par la variable d'environnement SB_ALLOW_INSECURE_TLS=1.
$allowinsecuretls = false;

// Courriel OAuth2 (Microsoft 365 / Google) : seulement utile en installation manuelle si
// votre propre relai msmtp (configuré par vous, hors de SwitchBoard - voir README.md) est
// réglé pour appeler "php .../src/mailoauthtoken.php" comme passwordeval. La configuration
// Docker (recommandée) n'a pas besoin de ces réglages : ils viennent de .env à la place.
// Voir MAIL-OAUTH-SETUP.md pour la procédure complète (inscription de l'application chez le
// fournisseur, puis autorisation depuis la console admin SwitchBoard, ?page=mailoauth).
// Peut aussi être fourni par la variable d'environnement SB_SMTP_OAUTH_PROVIDER.
$smtpoauthprovider = "";
// Peut aussi être fourni par la variable d'environnement SB_SMTP_OAUTH_CLIENT_ID.
$smtpoauthclientid = "";
// Google seulement (Microsoft 365 n'a besoin d'aucun secret).
// Peut aussi être fourni par la variable d'environnement SB_SMTP_OAUTH_CLIENT_SECRET.
$smtpoauthclientsecret = "";
// Microsoft 365 seulement. Laisser vide pour la valeur par défaut ("common").
// Peut aussi être fourni par la variable d'environnement SB_SMTP_OAUTH_TENANT.
$smtpoauthtenant = "";


?>