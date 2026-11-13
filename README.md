Requirements :
-- PHP 8.3/PHP 8.4
-- Https server enabled (managementportal.php is not even recognized without https enabled so you need to use https://localhost on local installation)
-- Extensions(in php.ini file) : gettext (for translation), nd_mysqli (NOT msyqli !! we had a bug here. First deactivate mysqli in your extensions and enable nd_mysqli) and pdo_mysql
-- The folder where your installing SwitchBoard need write access
-- 
Switchboard work currently only for php 8.3 to 8.4 (tested; the dependencies in composer.lock need 8.3 at least).
It's easier to set up an external server.
If you install on a local server, be sure that https has been configured or you will run into trouble.
1. Clone the repository into a development directory - NOT INTO /var/www/html DIRECTLY!;
2. Unzip the external sources in the SwitchBoard/ directory, the zip file is named precompileddependenciesvx.x.x.zip and is located under src/ directory, it contain the ICAL parser and it's dependencies and may contain more dependencies in the future. The folder should look like this /yourswitchboarddir/vendor : 
3. Rename the file /SwitchBoard/config/configempty.php to config.php and fill the empty strings. You must create a MySql database and give the rights to use it to a database user you must also create. The tables needed will create themself when you first call managementportal.php successfully.
4. Transfer the content of the /SwitchBoard/ folder to your website using ftp and access the SwitchBoard Console via yourwebsite.com/yourswitchboarddir/managementportal.php
5. If the content of the /SwitchBoard/ has been transferred to the local host in /var/www/html/, ensure the /SwitchBoard/ folder is owned by owner www-data as it won't be able to write to he database otherwise.
6. The system should ask you to create your admin account.
7. Create a cron job for ring group update and sms updates, you should find suggested cron job commands on the SwitchBoard admin console.
8. Mail: SwitchBoard uses mail to report errors and warnings, so it's worth setting up. This requires a sendmail-compatible relay (e.g. `msmtp`) installed and configured on the server itself — outside SwitchBoard's own setup for a manual install (the [Docker setup](Docker/README.md) ([français](Docker/README.fr.md)) handles this automatically via a setup wizard, no manual mail server config needed). Once configured, test it from the admin console: *Console d'administration → Courriel de test*. Microsoft 365 requires OAuth2 instead of a plain password — see [MAIL-OAUTH-SETUP.md](MAIL-OAUTH-SETUP.md) (also usable with Google).

If you experience issues while installing SwitchBoard, please open an issue.

## Security / Sécurité

SwitchBoard stores third-party API credentials (voip.ms, SwitchBoard Chat, MAS) in its
database, so run it behind HTTPS only, and make sure `config/`, `backups/`, and `.git/`
are not reachable from the web (see `Docker/nginx/nginx.conf.sample` for a working example
of the required deny rules if you are not using the provided Docker setup).

`config/config.php` (copied from `config/configempty.php`) supports the following optional
settings, each of which can also be provided as an environment variable of the same name in
capitals (e.g. `SB_GITHUB_TOKEN` instead of `$githubtoken`):

| Setting | Purpose |
|---|---|
| `$appencryptionkey` | Encrypts stored secrets (voip.ms API password, SwitchBoard Chat/MAS tokens) at rest. **Strongly recommended.** Generate with `php -r "echo base64_encode(random_bytes(32));"`. Losing this key makes existing encrypted secrets unrecoverable — back it up with your database backups. Without it, these secrets remain stored in plaintext (legacy behavior). |
| `$githubtoken` | Only needed if you fork SwitchBoard into a **private** repository and want the built-in auto-updater to work against it. Public repositories update without any token. |
| `$cronwebkey` | Only needed if your hosting (e.g. cPanel) can only trigger scheduled tasks via a URL instead of a real CLI cron job. Required as `?key=...` on the batch scripts under `src/*.php`; leave empty if you run them via CLI cron (recommended). |
| `$modedebug` | Shows PHP errors (server paths, SQL) in the browser instead of only logging them. Development only — never enable in production. |
| `$sbchatcabundle` | Path to a self-signed certificate to trust for the SwitchBoard Chat / MAS connection. Only needed if that server uses a self-signed certificate. See "Self-signed SBChat certificate" below. |
| `$allowinsecuretls` | Last resort: disables TLS verification for the SwitchBoard Chat / MAS connection entirely. SBChat/MAS tokens travel over that connection, so this exposes them to interception — prefer `$sbchatcabundle` above. |

### Self-signed SBChat certificate

Skip this section entirely if your SwitchBoard Chat / MAS server uses a normal certificate
(Let's Encrypt, etc.). Using the Docker setup instead? See the "Self-signed SBChat certificate"
section of [Docker/README.md](Docker/README.md#sbchat-certificate) — it uses a container path, not the server
path below.

If it uses a self-signed one, SwitchBoard will refuse to connect to it (cURL error #60) unless
you tell it to trust that certificate. The recommended way keeps TLS verification ON:

1. Extract the certificate and save it anywhere on your server that PHP can read — outside the
   web root if possible, e.g. next to (not inside) `config/`:

       echo | openssl s_client -connect sbchat.mydomain.ca:3443 2>/dev/null | openssl x509 > /path/on/your/server/sbchat-cert.pem

2. In `config/config.php`, point `$sbchatcabundle` at that path **on your server** — this is a
   real filesystem path here, not a container path:

       $sbchatcabundle = "/path/on/your/server/sbchat-cert.pem";

   Make sure the file is readable by whichever user your web server/PHP runs as (e.g.
   `chmod 644 /path/on/your/server/sbchat-cert.pem`).

3. As a last resort you can instead set `$allowinsecuretls = true;`, which disables TLS
   verification for the SBChat/MAS connection entirely — this exposes SBChat/MAS tokens to
   interception, so prefer the certificate above.

Found a security issue? See [SECURITY.md](SECURITY.md) for how to report it responsibly.

Want to contribute? See [CONTRIBUTING.md](CONTRIBUTING.md). Contributors are listed in [AUTHORS.md](AUTHORS.md).

See [CHANGELOG-SECURITE.md](CHANGELOG-SECURITE.md) (bilingual FR/EN) for a full summary of
the security changes above and what they mean for an existing deployment.

## SwitchBoard Chat / MAS

SwitchBoard can connect to a self-hosted SwitchBoard Chat server and a Matrix Authentication
Service (MAS) server for SMS-to-chat, on-call scheduling, and Matrix account management.
Neither is included in this repository — see [SBCHAT-SETUP.md](SBCHAT-SETUP.md) (bilingual
FR/EN) for the full step-by-step setup procedure.

## Calendar events / Événements de l'agenda

**English.** Each calendar event is one responder: the **title** is their name, the **description** holds their phone
number (10 digits; a leading "1" is not counted). Marks in the title: `off->` (not added to the voip.ms ring group), `!`
(SMS reminder one hour before), `*` (SMS reminder the day before).

The description can also hold **markers** of the form `name->value`, for that responder only:

| Marker | Meaning | Values | Without the marker |
|---|---|---|---|
| `faire1->` | Must the responder press 1 to accept the call? | `o`/`oui`/`y`/`yes`/`1` or `n`/`non`/`no`/`0` | the committee setting, otherwise yes |
| `nbsec->` | Ring time, in seconds | 1 to 60 | the committee setting, otherwise 25 |

Example: `514-555-1234 faire1->n nbsec->30`. Upper or lower case does not matter. Markers are removed before the phone
number is read, so their digits never mix with it. An unknown marker or an invalid value is ignored (the default
applies) and reported on the calendar validation page. The committee settings ("Ring time" and "Press 1 to accept the
call") are on the committee information page.

**Français.** Chaque événement de l'agenda est un répondant : le **titre** est son nom, la **description** contient son
numéro de téléphone (10 chiffres ; un « 1 » initial n'est pas compté). Marques du titre : `off->` (pas inscrit dans le
groupe de sonnerie voip.ms), `!` (rappel SMS une heure avant), `*` (rappel SMS la veille).

La description peut aussi contenir des **repères** de la forme `nom->valeur`, pour ce répondant seulement :

| Repère | Sens | Valeurs | Sans le repère |
|---|---|---|---|
| `faire1->` | Le répondant doit-il faire le 1 pour accepter l'appel ? | `o`/`oui`/`y`/`yes`/`1` ou `n`/`non`/`no`/`0` | le réglage du comité, sinon oui |
| `nbsec->` | Durée de sonnerie, en secondes | 1 à 60 | le réglage du comité, sinon 25 |

Exemple : `514-555-1234 faire1->n nbsec->30`. Majuscules et minuscules sont acceptées. Les repères sont retirés avant la
lecture du numéro : leurs chiffres ne s'y mêlent jamais. Un repère inconnu ou une valeur invalide est ignoré (la valeur
par défaut s'applique) et signalé dans la page de validation de l'agenda. Les réglages du comité (« Durée de sonnerie »
et « Faire le 1 pour accepter l'appel ») sont dans la page des informations du comité.

## CalDAV calendars (cPanel)

By default SwitchBoard reads a committee's calendar from a secret ICS URL (Google, Outlook). A calendar hosted on the
committee's own domain (cPanel / RoundCube) can be read over CalDAV instead, with a username and password — see
[CALDAV-SETUP.md](CALDAV-SETUP.md) (bilingual FR/EN) for the setup procedure.

## License

Copyright (C) 2026 Switchboarders.

Licensed under the [PolyForm Noncommercial License 1.0.0](https://polyformproject.org/licenses/noncommercial/1.0.0).
In short: you may use, modify, and share SwitchBoard for any **noncommercial** purpose, which includes
personal use, research, and use by charitable or nonprofit organizations such as the AA committees it was
written for. Any commercial use, such as selling it, offering it as a paid service, or running it for a
business, is not permitted without separate permission from the licensor. See [LICENSE](LICENSE) for the
full, governing text, including the `Required Notice:` line that must travel with every copy.

This is **not an OSI-approved open source license** (it restricts commercial use, which the Open Source
Definition doesn't allow) — it's source-available.

Earlier versions were published under the GNU Affero General Public License v3.0 with the Commons Clause.
Copies already obtained under those terms keep them.
