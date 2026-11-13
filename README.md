# SwitchBoard

**[English](#english)** · **[Français](#francais)**

<a id="english"></a>
## English

SwitchBoard is a web portal for volunteer AA help-line committees. It reads each committee's on-call calendar and keeps
its voip.ms ring group in step with it, sends SMS and SwitchBoard Chat reminders to the volunteers on call, and emails
monthly cost and call statistics reports.

### Installing

**With Docker (recommended).** One script installs and starts everything on a VPS, a home server, a Linux or Windows PC,
or a Raspberry Pi, mail relay included: see [Docker/README.md](Docker/README.md).

**Manual install (shared hosting, cPanel).** You need:

- PHP 8.3 or 8.4, with the extensions `curl`, `gettext`, `json`, `mbstring`, `mysqli` (with the `mysqlnd` driver),
  `openssl` and `zip`;
- a MariaDB or MySQL database (MariaDB 10.11 and 12 are in use) and a database user with all rights on it;
- HTTPS: the portal must be reached over HTTPS (on a local install, use `https://localhost`);
- write access to the install folder for the user that runs PHP.

1. Get the code into a working folder, not directly into the web root:
   `git clone https://github.com/Switchboarders/SwitchBoard.git`
2. In the `SwitchBoard/` folder, unzip the dependencies: `unzip src/precompileddependencies*.zip`. This creates
   `SwitchBoard/vendor/`.
3. Copy `SwitchBoard/config/configempty.php` to `config.php` and fill it in: the database server, name, user and
   password, and an encryption key (see [Security](#security)). The tables are created on the first visit to the portal.
4. Upload the contents of `SwitchBoard/` to your site (for example `public_html/switchboard/`). It must belong to the
   user that runs PHP (for example `www-data`), or SwitchBoard cannot write its logs, backups and updates.
5. Open `https://yoursite/switchboard/` and create the administrator account.
6. Add the cron jobs: the administration console shows the exact commands. Call PHP 8.3 or later by its full path (on
   cPanel, for example `/opt/cpanel/ea-php84/root/usr/bin/php`).
7. Set up mail: SwitchBoard emails its errors, warnings and reports. A manual install needs a sendmail-compatible relay
   (for example `msmtp`) on the server itself; the Docker setup configures one for you. Test it from the console:
   *Console d'administration → Courriel de test*. Microsoft 365 requires OAuth2 instead of a password: see
   [MAIL-OAUTH-SETUP.md](MAIL-OAUTH-SETUP.md) (also usable with Google).

If you run into trouble, please [open an issue](https://github.com/Switchboarders/SwitchBoard/issues).

### Updating, backups and rollback

The administration console shows when a new version is published on GitHub and installs it in one click; over SSH,
`php src/miseajour.php` does the same. Each update keeps a backup in `backups/<date>/` (the code, and the database when
the update changes it), and `php src/utils/jobRestore.php` rolls back to it. No GitHub token is needed. Details, in
French: [ReadmeMajAuto.md](ReadmeMajAuto.md).

<a id="security"></a>
### Security

SwitchBoard stores third-party API credentials (voip.ms, SwitchBoard Chat, MAS) in its database, so run it behind HTTPS
only, and make sure `config/`, `backups/` and `.git/` are not reachable from the web (see
`Docker/nginx/nginx.conf.sample` for a working example of the required deny rules if you are not using the Docker setup).

`config/config.php` (copied from `config/configempty.php`) supports the following optional settings, each of which can
also be provided as an environment variable of the same name in capitals (e.g. `SB_GITHUB_TOKEN` instead of
`$githubtoken`):

| Setting | Purpose |
|---|---|
| `$appencryptionkey` | Encrypts stored secrets (voip.ms API password, SwitchBoard Chat/MAS tokens) at rest. **Strongly recommended.** Generate with `php -r "echo base64_encode(random_bytes(32));"`. Losing this key makes existing encrypted secrets unrecoverable — back it up with your database backups. Without it, these secrets remain stored in plaintext (legacy behavior). |
| `$githubtoken` | Only needed if you fork SwitchBoard into a **private** repository and want the built-in updater to work against it. Public repositories update without any token. |
| `$cronwebkey` | Only needed if your hosting (e.g. cPanel) can only trigger scheduled tasks via a URL instead of a real CLI cron job. Required as `?key=...` on the batch scripts under `src/*.php`; leave empty if you run them via CLI cron (recommended). |
| `$modedebug` | Shows PHP errors (server paths, SQL) in the browser instead of only logging them. Development only — never enable in production. |
| `$sbchatcabundle` | Path to a self-signed certificate to trust for the SwitchBoard Chat / MAS connection. Only needed if that server uses a self-signed certificate. See [Self-signed SBChat certificate](#sbchat-certificate) below. |
| `$allowinsecuretls` | Last resort: disables TLS verification for the SwitchBoard Chat / MAS connection entirely. SBChat/MAS tokens travel over that connection, so this exposes them to interception — prefer `$sbchatcabundle` above. |

Found a security issue? See [SECURITY.md](SECURITY.md) for how to report it privately.
[CHANGELOG-SECURITE.md](CHANGELOG-SECURITE.md) (French and English) summarizes the security changes and what they mean
for an existing deployment.

<a id="sbchat-certificate"></a>
### Self-signed SBChat certificate

Skip this section if your SwitchBoard Chat / MAS server uses a normal certificate (Let's Encrypt, etc.). The simplest
way is in the administration console: *Certificat SwitchBoard Chat / MAS* trusts it in one click. Using the Docker
setup? See the [Docker instructions](Docker/README.md#sbchat-certificate): they use a container path, not the server
path below.

If the server uses a self-signed certificate, SwitchBoard refuses to connect to it (cURL error #60) unless you tell it to
trust that certificate. The recommended way keeps TLS verification on:

1. Extract the certificate and save it anywhere on your server that PHP can read — outside the web root if possible,
   e.g. next to (not inside) `config/`:

       echo | openssl s_client -connect sbchat.mydomain.ca:3443 2>/dev/null | openssl x509 > /path/on/your/server/sbchat-cert.pem

2. In `config/config.php`, point `$sbchatcabundle` at that path **on your server**:

       $sbchatcabundle = "/path/on/your/server/sbchat-cert.pem";

   Make sure the file is readable by the user PHP runs as (e.g. `chmod 644 /path/on/your/server/sbchat-cert.pem`).

3. As a last resort you can instead set `$allowinsecuretls = true;`, which disables TLS verification for the SBChat/MAS
   connection entirely — this exposes SBChat/MAS tokens to interception, so prefer the certificate above.

### SwitchBoard Chat / MAS

SwitchBoard can connect to a self-hosted SwitchBoard Chat server and a Matrix Authentication Service (MAS) server for
SMS-to-chat, on-call scheduling and Matrix account management. Neither is included in this repository: see
[SBCHAT-SETUP.md](SBCHAT-SETUP.md) (French and English) for the step-by-step setup.

### Calendar events

Each calendar event is one volunteer on call: the **title** is their name, the **description** holds their phone number
(10 digits; a leading "1" is not counted). Marks in the title: `off->` (not added to the voip.ms ring group), `!` (SMS
reminder one hour before), `*` (SMS reminder the day before).

The description can also hold **markers** of the form `name->value`, for that person only:

| Marker | Meaning | Values | Without the marker |
|---|---|---|---|
| `faire1->` | Must the volunteer press 1 to accept the call? | `o`/`oui`/`y`/`yes`/`1` or `n`/`non`/`no`/`0` | the committee setting, otherwise yes |
| `nbsec->` | Ring time, in seconds | 1 to 60 | the committee setting, otherwise 25 |

Example: `514-555-1234 faire1->n nbsec->30`. Upper or lower case does not matter. Markers are removed before the phone
number is read, so their digits never mix with it. An unknown marker or an invalid value is ignored (the default
applies) and reported on the calendar validation page. The committee settings ("Ring time" and "Press 1 to accept the
call") are on the committee information page.

### CalDAV calendars (cPanel)

By default SwitchBoard reads a committee's calendar from a secret ICS URL (Google, Outlook). A calendar hosted on the
committee's own domain (cPanel / RoundCube) can be read over CalDAV instead, with a username and password: see
[CALDAV-SETUP.md](CALDAV-SETUP.md) (French and English).

### Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Contributors are listed in [AUTHORS.md](AUTHORS.md). The developer documentation
is in French: [ARCHITECTURE.md](ARCHITECTURE.md) (where things are), [CONVENTIONS.md](CONVENTIONS.md) (how the code is
written), [LEXIQUE.md](LEXIQUE.md) (vocabulary) and [PASSATION.md](PASSATION.md) (current state and how to take over).

### License

Copyright (C) 2026 Switchboarders.

Licensed under the [PolyForm Noncommercial License 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0).
In short: you may use, modify and share SwitchBoard for any **noncommercial** purpose, which includes personal use,
research, and use by charitable or nonprofit organizations such as the AA committees it was written for. Any commercial
use, such as selling it, offering it as a paid service, or running it for a business, is not permitted without separate
permission from the licensor. See [LICENSE](LICENSE) for the full, governing text, including the `Required Notice:` line
that must travel with every copy.

This is **not an OSI-approved open source license** (it restricts commercial use, which the Open Source Definition
doesn't allow): it's source-available.

Earlier versions were published under the GNU Affero General Public License v3.0 with the Commons Clause. Copies already
obtained under those terms keep them.

---

<a id="francais"></a>
## Français

SwitchBoard est un portail web pour les comités bénévoles de l'appel à l'aide des AA. Il lit l'agenda de garde de chaque
comité et tient son groupe de sonnerie voip.ms à jour, envoie des rappels par SMS et par SwitchBoard Chat aux bénévoles
de garde, et envoie chaque mois par courriel les rapports des coûts et des statistiques d'appels.

### Installer

**Avec Docker (recommandé).** Un seul script installe et démarre tout sur un VPS, un serveur maison, un PC Linux ou
Windows, ou un Raspberry Pi, relais de courriel compris : voir [Docker/README.md](Docker/README.md#francais).

**Installation à la main (hébergement mutualisé, cPanel).** Il faut :

- PHP 8.3 ou 8.4, avec les extensions `curl`, `gettext`, `json`, `mbstring`, `mysqli` (avec le pilote `mysqlnd`),
  `openssl` et `zip` ;
- une base de données MariaDB ou MySQL (MariaDB 10.11 et 12 sont en service), et un utilisateur qui a tous les droits
  sur elle ;
- HTTPS : le portail doit être ouvert en HTTPS (sur une installation locale, `https://localhost`) ;
- le droit d'écriture sur le dossier d'installation pour l'utilisateur qui fait tourner PHP.

1. Prendre le code dans un dossier de travail, pas directement à la racine du site :
   `git clone https://github.com/Switchboarders/SwitchBoard.git`
2. Dans le dossier `SwitchBoard/`, extraire les dépendances : `unzip src/precompileddependencies*.zip`. Cela crée
   `SwitchBoard/vendor/`.
3. Copier `SwitchBoard/config/configempty.php` vers `config.php` et le remplir : le serveur, le nom, l'utilisateur et le
   mot de passe de la base, et une clé de chiffrement (voir [Sécurité](#securite)). Les tables se créent à la première
   visite du portail.
4. Envoyer le contenu de `SwitchBoard/` sur le site (par exemple dans `public_html/switchboard/`). Il doit appartenir à
   l'utilisateur qui fait tourner PHP (par exemple `www-data`), sinon SwitchBoard ne peut écrire ni ses journaux, ni ses
   sauvegardes, ni ses mises à jour.
5. Ouvrir `https://votresite/switchboard/` et créer le compte administrateur.
6. Ajouter les tâches cron : la console d'administration donne les commandes exactes. Appeler PHP 8.3 ou plus par son
   chemin complet (sur cPanel, par exemple `/opt/cpanel/ea-php84/root/usr/bin/php`).
7. Régler le courriel : SwitchBoard envoie par courriel ses erreurs, ses avertissements et ses rapports. Une installation
   à la main demande un relais compatible sendmail (par exemple `msmtp`) sur le serveur lui-même ; l'installation Docker
   en configure un. Pour l'essayer : *Console d'administration → Courriel de test*. Microsoft 365 exige OAuth2 au lieu
   d'un mot de passe : voir [MAIL-OAUTH-SETUP.md](MAIL-OAUTH-SETUP.md) (marche aussi avec Google).

En cas de problème, [ouvrez une issue](https://github.com/Switchboarders/SwitchBoard/issues).

### Mise à jour, sauvegardes et retour arrière

La console d'administration montre quand une nouvelle version est publiée sur GitHub et l'installe en un clic ; par SSH,
`php src/miseajour.php` fait la même chose. Chaque mise à jour garde une sauvegarde dans `backups/<date>/` (le code, et
la base de données quand la mise à jour la change), et `php src/utils/jobRestore.php` y revient. Aucun jeton GitHub
n'est nécessaire. Le détail : [ReadmeMajAuto.md](ReadmeMajAuto.md).

<a id="securite"></a>
### Sécurité

SwitchBoard garde dans sa base de données les identifiants d'API de services tiers (voip.ms, SwitchBoard Chat, MAS) :
ne le faites tourner qu'en HTTPS, et vérifiez que `config/`, `backups/` et `.git/` ne sont pas accessibles par le web
(sans l'installation Docker, voir `Docker/nginx/nginx.conf.sample` pour un exemple des règles de refus nécessaires).

`config/config.php` (copié de `config/configempty.php`) accepte les réglages facultatifs suivants. Chacun peut aussi être
donné par une variable d'environnement du même nom en majuscules (par exemple `SB_GITHUB_TOKEN` au lieu de
`$githubtoken`) :

| Réglage | À quoi il sert |
|---|---|
| `$appencryptionkey` | Chiffre les secrets gardés dans la base (mot de passe d'API voip.ms, jetons SwitchBoard Chat et MAS). **Fortement recommandé.** Pour en créer une : `php -r "echo base64_encode(random_bytes(32));"`. Sans cette clé, les secrets déjà chiffrés sont perdus : gardez-la avec vos sauvegardes de la base. Sans elle, ces secrets restent en clair (comportement d'avant). |
| `$githubtoken` | Seulement pour une copie de SwitchBoard dans un dépôt **privé**, pour que la mise à jour intégrée y ait accès. Un dépôt public se met à jour sans jeton. |
| `$cronwebkey` | Seulement si l'hébergeur (par exemple cPanel) ne peut lancer les tâches planifiées que par une adresse web, et non par un vrai cron en ligne de commande. Se met en `?key=...` sur les scripts de `src/*.php` ; à laisser vide avec un cron en ligne de commande (recommandé). |
| `$modedebug` | Affiche les erreurs PHP (chemins du serveur, SQL) dans le navigateur au lieu de seulement les journaliser. Pour le développement seulement ; jamais en production. |
| `$sbchatcabundle` | Chemin d'un certificat auto-signé à approuver pour la connexion à SwitchBoard Chat / MAS. Seulement si ce serveur utilise un certificat auto-signé. Voir [Certificat SBChat auto-signé](#certificat-sbchat) plus bas. |
| `$allowinsecuretls` | En dernier recours : coupe toute vérification TLS de la connexion à SwitchBoard Chat / MAS. Les jetons SBChat et MAS passent par cette connexion et pourraient être interceptés : préférez `$sbchatcabundle`. |

Une faille de sécurité ? Voir [SECURITY.md](SECURITY.md) pour la signaler en privé.
[CHANGELOG-SECURITE.md](CHANGELOG-SECURITE.md) (français et anglais) résume les changements de sécurité et ce qu'ils
changent pour une installation existante.

<a id="certificat-sbchat"></a>
### Certificat SBChat auto-signé

Passez cette section si votre serveur SwitchBoard Chat / MAS a un certificat ordinaire (Let's Encrypt, etc.). Le plus
simple est dans la console d'administration : *Certificat SwitchBoard Chat / MAS* l'approuve en un clic. Avec
l'installation Docker, voir les [instructions Docker](Docker/README.md#fr-sbchat-certificate) : elles utilisent un
chemin dans le conteneur, pas le chemin du serveur ci-dessous.

Si le serveur a un certificat auto-signé, SwitchBoard refuse de s'y connecter (erreur cURL #60) tant qu'on ne lui dit pas
d'approuver ce certificat. La méthode recommandée garde la vérification TLS :

1. Extraire le certificat et l'enregistrer n'importe où sur le serveur où PHP peut le lire, hors de la racine du site si
   possible, par exemple à côté de `config/` (pas dedans) :

       echo | openssl s_client -connect sbchat.mondomaine.ca:3443 2>/dev/null | openssl x509 > /chemin/sur/le/serveur/sbchat-cert.pem

2. Dans `config/config.php`, faire pointer `$sbchatcabundle` vers ce chemin **sur le serveur** :

       $sbchatcabundle = "/chemin/sur/le/serveur/sbchat-cert.pem";

   Le fichier doit être lisible par l'utilisateur qui fait tourner PHP (par exemple
   `chmod 644 /chemin/sur/le/serveur/sbchat-cert.pem`).

3. En dernier recours, on peut plutôt mettre `$allowinsecuretls = true;`, qui coupe toute vérification TLS de la
   connexion à SBChat / MAS : les jetons SBChat et MAS pourraient être interceptés, donc préférez le certificat.

### SwitchBoard Chat / MAS

SwitchBoard peut se connecter à un serveur SwitchBoard Chat auto-hébergé et à un serveur Matrix Authentication Service
(MAS) pour les SMS vers le clavardage, les horaires de garde et la gestion des comptes Matrix. Ni l'un ni l'autre n'est
dans ce dépôt : voir [SBCHAT-SETUP.md](SBCHAT-SETUP.md) (français et anglais) pour la procédure pas à pas.

### Événements de l'agenda

Chaque événement de l'agenda est un bénévole de garde : le **titre** est son nom, la **description** contient son numéro
de téléphone (10 chiffres ; un « 1 » initial n'est pas compté). Marques du titre : `off->` (pas inscrit dans le groupe de
sonnerie voip.ms), `!` (rappel SMS une heure avant), `*` (rappel SMS la veille).

La description peut aussi contenir des **repères** de la forme `nom->valeur`, pour cette personne seulement :

| Repère | Sens | Valeurs | Sans le repère |
|---|---|---|---|
| `faire1->` | Le bénévole doit-il faire le 1 pour accepter l'appel ? | `o`/`oui`/`y`/`yes`/`1` ou `n`/`non`/`no`/`0` | le réglage du comité, sinon oui |
| `nbsec->` | Durée de sonnerie, en secondes | 1 à 60 | le réglage du comité, sinon 25 |

Exemple : `514-555-1234 faire1->n nbsec->30`. Majuscules et minuscules sont acceptées. Les repères sont retirés avant la
lecture du numéro : leurs chiffres ne s'y mêlent jamais. Un repère inconnu ou une valeur invalide est ignoré (la valeur
par défaut s'applique) et signalé dans la page de validation de l'agenda. Les réglages du comité (« Durée de sonnerie »
et « Faire le 1 pour accepter l'appel ») sont dans la page des informations du comité.

### Agendas CalDAV (cPanel)

Par défaut, SwitchBoard lit l'agenda d'un comité par une adresse ICS secrète (Google, Outlook). Un agenda hébergé sur le
domaine du comité (cPanel / RoundCube) peut plutôt être lu par CalDAV, avec un nom d'utilisateur et un mot de passe :
voir [CALDAV-SETUP.md](CALDAV-SETUP.md) (français et anglais).

### Contribuer

Voir [CONTRIBUTING.md](CONTRIBUTING.md). Les contributeurs sont dans [AUTHORS.md](AUTHORS.md). La documentation des
développeurs : [ARCHITECTURE.md](ARCHITECTURE.md) (où est quoi), [CONVENTIONS.md](CONVENTIONS.md) (comment le code
s'écrit), [LEXIQUE.md](LEXIQUE.md) (vocabulaire) et [PASSATION.md](PASSATION.md) (où on en est, et comment reprendre).

### Licence

Copyright (C) 2026 Switchboarders.

Sous [licence PolyForm Noncommercial 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0). En bref : vous
pouvez utiliser, modifier et partager SwitchBoard à toute fin **non commerciale**, ce qui comprend l'usage personnel, la
recherche, et l'usage par des organismes de bienfaisance ou sans but lucratif comme les comités AA pour qui il a été
écrit. Tout usage commercial, comme le vendre, l'offrir comme service payant ou le faire tourner pour une entreprise,
demande une permission distincte du titulaire de la licence. Le texte complet, qui fait foi, est dans
[LICENSE](LICENSE), avec la ligne `Required Notice:` qui doit accompagner chaque copie.

Ce **n'est pas une licence open source approuvée par l'OSI** (elle restreint l'usage commercial, ce que la définition de
l'open source ne permet pas) : le code source est disponible, sous ces conditions.

Les versions précédentes ont été publiées sous la licence GNU Affero General Public License v3.0 avec la Commons Clause.
Les copies déjà obtenues sous ces conditions les gardent.
