# Journal des changements de sécurité / Security changelog

Résumé bilingue de la refonte de sécurité menée en vue de la publication de SwitchBoard
en logiciel libre (juillet 2026). Voir aussi [SECURITY.md](SECURITY.md) pour signaler une
vulnérabilité.

Bilingual summary of the security overhaul carried out ahead of open-sourcing SwitchBoard
(July 2026). See also [SECURITY.md](SECURITY.md) to report a vulnerability.

---

## Nouveaux réglages / New settings

Tous optionnels sauf indication contraire. Chacun peut être fourni soit par variable
d'environnement, soit dans `config/config.php` (voir `config/configempty.php`).

All optional unless noted otherwise. Each can be provided either as an environment
variable or in `config/config.php` (see `config/configempty.php`).

| Variable d'environnement<br>Environment variable | `config.php` | Rôle (FR) | Purpose (EN) |
|---|---|---|---|
| `SB_GITHUB_TOKEN` (ou `GITHUB_TOKEN`) | `$githubtoken` | Jeton GitHub pour la mise à jour auto. **Facultatif** : inutile pour un dépôt public. | GitHub token for auto-update. **Optional**: not needed for a public repo. |
| `APP_ENCRYPTION_KEY` | `$appencryptionkey` | Chiffre les secrets stockés (mot de passe API voip.ms, jetons SBChat/MAS). **Fortement recommandé.** Sans elle, ces secrets restent en clair. **Ne jamais la perdre** (sauvegarder avec la BD). | Encrypts stored secrets (voip.ms API password, SBChat/MAS tokens). **Strongly recommended.** Without it these secrets stay in plaintext. **Never lose it** (back up alongside the DB). |
| `CRON_WEB_KEY` | `$cronwebkey` | Clé partagée pour déclencher les scripts batch (`src/*.php`) via URL plutôt que CLI. Laisser vide en usage normal (cron CLI). | Shared key to trigger batch scripts (`src/*.php`) via URL instead of CLI. Leave empty for normal use (CLI cron). |
| `SB_SBCHAT_CA_BUNDLE` | `$sbchatcabundle` | Chemin (vu du serveur PHP) vers un certificat SBChat auto-signé à faire confiance. La vérification TLS reste active. | Path (as seen by the PHP server) to a self-signed SBChat certificate to trust. TLS verification stays on. |
| `SB_ALLOW_INSECURE_TLS` | `$allowinsecuretls` | Dernier recours : désactive la vérification TLS vers SBChat/MAS. Expose à une interception. | Last resort: disables TLS verification to SBChat/MAS. Exposes the connection to interception. |
| `SB_DEBUG` | `$modedebug` | Affiche les erreurs PHP à l'écran. **Jamais en production.** Peut aussi être basculé depuis la console admin (bouton, prioritaire sur `config.php` mais pas sur `SB_DEBUG`). | Displays PHP errors on screen. **Never in production.** Can also be toggled from the admin console (button; wins over `config.php` but not over `SB_DEBUG`). |

**Docker :** les six sont câblées dans `Docker/.env_sample` et les deux fichiers
`docker-compose.*.yml`. `SB_SBCHAT_CA_BUNDLE` doit pointer vers un chemin **dans le
conteneur** (`Docker/certs/` y est monté sous `/etc/ssl/sbchat/`), pas un chemin de l'hôte.

**Docker:** all six are wired into `Docker/.env_sample` and both `docker-compose.*.yml`
files. `SB_SBCHAT_CA_BUNDLE` must point at a path **inside the container**
(`Docker/certs/` is mounted there at `/etc/ssl/sbchat/`), not a host path.

---

## Résumé des changements (Français)

### Authentification et sessions
Le mot de passe n'est plus jamais stocké en session : seul un identifiant utilisateur et
un indicateur "authentifié" le sont. Le mot de passe n'est vérifié (bcrypt) qu'une seule
fois, à la connexion. Le cookie de session reçoit l'attribut `secure` en HTTPS.
**Conséquence attendue :** toutes les sessions actives sont invalidées à la mise à jour
(reconnexion requise une fois).

### Chiffrement des secrets au repos
Le mot de passe API voip.ms et les jetons SBChat/MAS sont chiffrés (AES-256-GCM, nouvelle
classe `CryptoManager`) dès qu'une clé (`APP_ENCRYPTION_KEY`) est configurée. Les valeurs
déjà en clair restent lisibles (transition progressive à chaque sauvegarde) ou peuvent
être toutes chiffrées d'un coup avec `src/reencryptsecrets.php` (refuse de s'exécuter sans
clé configurée). Les formulaires n'affichent plus ces secrets en clair (indicateur
"configuré/non configuré" à la place). Migrations de BD 34 et 35 pour l'élargissement des
colonnes et l'ajout d'une expiration aux jetons de validation sans authentification.

### Autorisation et CSRF
La console d'administration, la demande de jeton SBChat et la gestion des utilisateurs MAS
exigent désormais un privilège admin (n'importe quel utilisateur connecté pouvait y accéder
auparavant). Un jeton CSRF protège tous les formulaires qui modifient un état.

### Scripts batch et tâches planifiées
Les scripts `src/*.php` déclenchés par cron (notifications SMS/chat, rafraîchissement,
statistiques, backup/restore) exigent désormais le CLI ou la clé partagée `CRON_WEB_KEY`
pour s'exécuter - ils étaient auparavant accessibles à quiconque devinait leur URL,
notamment `sendnotificationsmss.php`, capable d'envoyer des SMS via le compte voip.ms.

### Mise à jour automatique
Épinglée au SHA du commit de la dernière release GitHub plutôt qu'à une branche mouvante
(repli sur la branche si aucune release n'existe encore). Le zip téléchargé est vérifié
(dossier racine attendu, accepte le format court ou complet du SHA) et protégé contre le
zip-slip avant toute extraction. L'installation en place n'est plus détruite avant
validation de la nouvelle version. Le jeton GitHub n'est plus écrit dans le code source.

### Fiabilité de la mise à jour de la base de données
**Bug de fond corrigé :** le compteur `db version` de la table `parameters` était remis à 1
à chaque mise à jour de code où la BD était déjà à jour, faisant rejouer toutes les
migrations depuis le début à la mise à jour suivante (échec systématique, BD bloquée). Le
compteur ne peut plus reculer ; une BD déjà à jour n'est plus ni sauvegardée ni réécrite
pour rien. Le bouton « forcer la BD » (qui provoquait ce même problème délibérément) a été
retiré. Une erreur de migration est maintenant rattrapée et affichée clairement (au lieu
d'une page blanche) et n'avance plus le compteur. La console d'administration affiche les
trois compteurs (code installé, code disponible, version de BD).

### Divulgation d'information
Affichage des erreurs PHP désactivé par défaut (activable via `SB_DEBUG` ou le bouton de la
console admin), messages SQL bruts masqués en production, jeton de validation
sans-authentification généré par générateur cryptographique avec expiration d'un an (au
lieu de `str_shuffle()` sans expiration), plusieurs échappements XSS ajoutés, fin de
l'inclusion PHP d'un fichier de journal (pouvait exécuter du code injecté dans une entrée
de journal).

### Protection des dossiers sensibles
`backups/`, `config/` et `download/` reçoivent désormais un `.htaccess` interdisant tout
accès web, déposé automatiquement au démarrage (les installations qui vivent dans la racine
web - cPanel, `public_html/` - exposaient sinon les sauvegardes complètes de la BD et une
copie de `config.php`, secrets compris).

### Docker
Les identifiants ne sont plus journalisés ni figés dans une couche d'image ; le conteneur
php tourne sous l'utilisateur `www-data` plutôt que root (`gosu`) ; les images de base sont
épinglées à une version (`mariadb:12`, `nginx:stable-alpine`,
`nginxproxy/nginx-proxy` remplaçant l'image dépréciée `jwilder/nginx-proxy`) ; `up.sh`
génère des mots de passe aléatoires au premier lancement plutôt que de laisser les valeurs
d'exemple ; nginx reçoit des en-têtes de sécurité.

### Console d'administration
Nouveau bouton pour activer/désactiver le mode debug sans accès au serveur. Affichage des
trois compteurs de version. Messages d'étape pendant la mise à jour (téléchargement,
sauvegarde de la BD, migration), avec récupération propre en cas d'erreur fatale.

### Régressions détectées et corrigées en cours de déploiement
Deux erreurs de ma part, corrigées le jour même après un signalement du serveur de test :
l'API voip.ms n'accepte que des requêtes GET (pas POST, malgré ce que suggérait une
bibliothèque tierce consultée) ; la vérification TLS vers SBChat, activée par défaut,
manquait d'une échappatoire réellement câblée pour un certificat auto-signé. Les deux sont
corrigées et vérifiées contre l'infrastructure réelle.

---

## Summary of changes (English)

### Authentication and sessions
The password is never stored in the session anymore: only a user id and an "authenticated"
flag are. The password is verified (bcrypt) exactly once, at login. The session cookie now
gets the `secure` attribute over HTTPS. **Expected consequence:** all active sessions are
invalidated on update (one re-login required).

### Encryption of secrets at rest
The voip.ms API password and SBChat/MAS tokens are encrypted (AES-256-GCM, new
`CryptoManager` class) once a key (`APP_ENCRYPTION_KEY`) is configured. Existing plaintext
values keep working (encrypted lazily on next save) or can all be encrypted at once with
`src/reencryptsecrets.php` (refuses to run without a configured key). Forms no longer
display these secrets in plaintext (a "configured/not configured" indicator instead). DB
migrations 34 and 35 widen the columns and add an expiration to the no-login validation
tokens.

### Authorization and CSRF
The admin console, the SBChat token request, and MAS user management now require admin
privilege (any logged-in user could reach them before). A CSRF token protects every
state-changing form.

### Batch scripts and scheduled tasks
The cron-triggered `src/*.php` scripts (SMS/chat notifications, refresh, stats,
backup/restore) now require CLI or the shared `CRON_WEB_KEY` to run - they were previously
reachable by anyone who guessed their URL, notably `sendnotificationsmss.php`, which could
send SMS through the voip.ms account.

### Automatic updates
Pinned to the commit SHA of the latest GitHub release rather than a moving branch (falls
back to the branch if no release exists yet). The downloaded zip is verified (expected root
folder, accepting either the short or full SHA form) and protected against zip-slip before
any extraction. The live install is no longer destroyed before the new version is
validated. The GitHub token is no longer written in source code.

### Database update reliability
**Root-cause bug fixed:** the `db version` counter in the `parameters` table was being
reset to 1 on every code update where the DB was already current, causing the next update
to replay every migration from scratch (guaranteed failure, DB stuck). The counter can no
longer move backwards; an already-current DB is no longer backed up or rewritten for
nothing. The "force DB" button (which deliberately caused this same problem) was removed. A
migration failure is now caught and displayed clearly (instead of a blank page) and no
longer advances the counter. The admin console displays all three counters (installed code,
available code, DB schema version).

### Information disclosure
PHP error display is off by default (toggle via `SB_DEBUG` or the admin console button),
raw SQL messages are hidden in production, the no-login validation token is generated with
a cryptographic RNG and a one-year expiration (instead of `str_shuffle()` with no
expiration), several XSS sinks were escaped, and a log file is no longer `include()`-d as
PHP (could have executed code injected into a log entry).

### Protecting sensitive directories
`backups/`, `config/`, and `download/` now get a deny-all `.htaccess` written automatically
at startup (installs that live inside the web root - cPanel, `public_html/` - would
otherwise expose full database backups and a copy of `config.php`, secrets included).

### Docker
Credentials are no longer logged or baked into an image layer; the php container runs as
`www-data` rather than root (`gosu`); base images are pinned to a version (`mariadb:12`,
`nginx:stable-alpine`, `nginxproxy/nginx-proxy` replacing the deprecated
`jwilder/nginx-proxy`); `up.sh` generates random passwords on first run instead of leaving
the sample placeholders; nginx gets security headers.

### Admin console
New button to enable/disable debug mode without server access. Displays all three version
counters. Step-by-step messages during updates (download, DB backup, migration), with clean
recovery on a fatal error.

### Regressions found and fixed during rollout
Two mistakes on my part, both fixed the same day after the test server reported them: the
voip.ms API only accepts GET requests (not POST, despite what a third-party library I
consulted suggested); TLS verification to SBChat, now on by default, was missing an
actually-wired escape hatch for a self-signed certificate. Both are fixed and verified
against the real infrastructure.

---

## Nouveaux fichiers / New files

- `src/commun/cryptomanager.php` — chiffrement des secrets / secret encryption
- `src/commun/authhelpers.php` — CSRF, contrôle admin, garde CLI/cron / CSRF, admin gate, CLI/cron guard
- `src/reencryptsecrets.php` — script de chiffrement rétroactif / retroactive encryption script
- `src/updatejson/securityupgrade1.sql`, `securityupgrade2.sql` — migrations 34/35 / migrations 34/35
- `SECURITY.md` — comment signaler une vulnérabilité / how to report a vulnerability
- `Docker/certs/` — certificat SBChat auto-signé (monté dans le conteneur) / self-signed SBChat certificate (mounted into the container)

## À faire / Still open

- Choisir une licence (`LICENSE`) avant publication — décision que je n'ai pas prise à
  votre place. / Choose a license (`LICENSE`) before publishing — a decision I did not
  make on your behalf.
- Réécriture de l'historique Git (secrets déjà divulgués dans d'anciens commits) avant de
  rendre le dépôt public. / Git history rewrite (secrets already leaked in old commits)
  before making the repository public.
- Publier une release/tag GitHub pour activer la vérification par SHA épinglé plutôt que la
  branche mouvante. / Publish a GitHub release/tag to enable SHA-pinned verification
  instead of the moving branch.
