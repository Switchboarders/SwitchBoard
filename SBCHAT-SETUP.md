# Configuration de SwitchBoard Chat (SBChat) et Matrix Authentication Service (MAS) / SBChat & MAS setup

SBChat et MAS sont deux serveurs **externes**, distincts de SwitchBoard et non inclus dans ce
dépôt : SwitchBoard s'y connecte comme client (API REST), mais ne les héberge pas et ne les
installe pas. Ce guide couvre uniquement le côté SwitchBoard (les champs à remplir, l'ordre des
étapes) — l'installation de vos serveurs SBChat et MAS eux-mêmes est un prérequis externe.

SBChat and MAS are two **external** servers, separate from SwitchBoard and not included in this
repository: SwitchBoard connects to them as a REST client but does not host or install them.
This guide covers only the SwitchBoard side (which fields to fill in, in which order) — setting
up your own SBChat and MAS servers is an external prerequisite.

---

## Procédure (Français)

### 1. Prérequis

Avant de commencer, vous devez déjà avoir :
- Un serveur **SBChat** en fonction, accessible en HTTPS (ex. `https://monserveur.org:3443`),
  exposant son API sous `{adresse}/sbchat_api`.
- Un **jeton API SBChat de démarrage** ("admin"), obtenu directement depuis le processus de
  mise en place de votre serveur SBChat (SwitchBoard ne peut pas générer ce tout premier
  jeton lui-même — voir §3).
- (Optionnel, seulement si vous gérez les comptes Matrix des agents depuis SwitchBoard) Un
  serveur **MAS** (Matrix Authentication Service) en fonction, et un client OAuth2 (ID +
  secret) déjà enregistré côté MAS avec la portée `urn:mas:admin`. Cet enregistrement se fait
  entièrement du côté MAS — SwitchBoard ne fait qu'utiliser un ID/secret déjà créé.
- Si SBChat ou MAS utilise un certificat TLS auto-signé, rien à préparer à l'avance : une fois
  l'adresse du serveur configurée (§3), la console admin peut aller chercher et approuver ce
  certificat en un clic (§4). Un réglage manuel (`$sbchatcabundle` / `SB_SBCHAT_CA_BUNDLE`)
  reste possible si SwitchBoard ne peut pas joindre directement le serveur (pare-feu) — voir
  [README.md](README.md) ou [Docker/README.md](Docker/README.md#fr-sbchat-certificate).

### 2. Réglages de chiffrement

Dans `.env` (Docker) ou `config/config.php` :
- `APP_ENCRYPTION_KEY` (`$appencryptionkey`) : fortement recommandé. Chiffre au repos le jeton
  SBChat et les identifiants MAS stockés en base. Sans elle, ces secrets restent en clair dans
  la base de données.

### 3. Configurer le compte administrateur (jeton de démarrage + MAS)

**Important :** les identifiants MAS (`masurl`, `masclientid`, `massecret`) sont techniquement
stockés par utilisateur, mais dans le code, ils sont **toujours lus depuis le compte de la
personne actuellement connectée qui effectue l'action** — jamais depuis le compte de l'agent
ciblé. En pratique, cela veut dire : **remplissez ces trois champs sur VOTRE PROPRE compte
admin**, pas sur celui d'un agent. Les remplir ailleurs n'a aucun effet.

1. Connectez-vous avec un compte administrateur.
2. Menu → *Informations SwitchBoard Chat du compte* (`?page=sbchatinfo`).
3. Remplissez, sur votre propre compte :
   - **Token API SB Chat** : collez votre jeton de démarrage obtenu à l'étape 1.
   - **Adresse du serveur SwitchBoardChat** : ex. `https://monserveur.org:3443`.
   - **URL du serveur MAS**, **ID du client MAS**, **SECRET du client MAS** (seulement si vous
     comptez gérer les comptes Matrix des agents — voir §5).
4. Soumettre. Les champs à saisie unique (jeton, secret) affichent "configuré" une fois
   enregistrés ; les laisser vides lors d'une prochaine sauvegarde conserve la valeur existante.

### 4. Faire confiance au certificat auto-signé (si nécessaire)

Ignorez cette étape si SBChat/MAS utilise un certificat normal (Let's Encrypt, etc.).

Une fois l'adresse du serveur SwitchBoard Chat configurée (§3), la console admin peut approuver
son certificat directement, sans toucher à `config.php` ni transférer de fichier :

1. Menu → *SwitchBoard Chat* → *Certificat SwitchBoard Chat / MAS* (`?page=sbchatcertificate`,
   admin seulement).
2. Cliquez sur *Vérifier le certificat du serveur*. SwitchBoard se connecte à l'adresse
   configurée à l'étape précédente et affiche un **aperçu** du certificat qu'elle présente
   actuellement (hôte:port réellement contacté, sujet, noms alternatifs, date d'expiration,
   empreinte) — rien n'est encore approuvé à ce stade.
3. **Vérifiez l'aperçu avant de continuer**, en particulier si un avertissement « ne semble PAS
   correspondre au serveur attendu » apparaît : cela signale que le certificat obtenu ne couvre
   pas le nom d'hôte contacté, typiquement parce que l'adresse configurée n'a pas le bon port
   et se connecte par erreur à un autre service sur le même domaine. Dans ce cas, cliquez
   *Annuler*, corrigez l'adresse du serveur (§3) et recommencez, plutôt que d'approuver.
4. Si l'aperçu est correct, cliquez *Oui, approuver ce certificat*.
5. En cas de renouvellement futur (le certificat expire), revenez sur cette page et refaites les
   mêmes étapes — inutile de refaire toute la manipulation manuelle.

Si SwitchBoard ne peut pas joindre directement le serveur SBChat/MAS (pare-feu), la même page
propose une alternative : collez-y le contenu d'un fichier `.pem` obtenu autrement (ex. :
`openssl s_client -connect votreserveur:port | openssl x509`, exécuté depuis un poste qui, lui,
a accès au serveur) — un aperçu s'affiche de la même façon avant l'approbation.

**Si le certificat de SBChat/MAS devient un vrai certificat signé par une autorité reconnue**
(ex. : vous passez d'un certificat auto-signé à Let's Encrypt), retirez le certificat approuvé
ici au lieu de le laisser en place. Approuver un certificat précis fait que SwitchBoard ne fait
confiance qu'à CE fichier exact, à la place de la confiance normale du système — au prochain
renouvellement (60 à 90 jours pour Let's Encrypt), le nouveau certificat ne correspondra plus et
la connexion échouera de nouveau, même si le nouveau certificat est parfaitement valide. Cette
page détecte automatiquement le cas (elle affiche « Ce certificat n'est pas auto-signé ») et
propose un bouton *Supprimer le certificat approuvé* : après ce retrait, SwitchBoard utilise la
confiance normale du système, qui continue de fonctionner tout seul à travers les renouvellements.

**Si `SB_SBCHAT_CA_BUNDLE` est déjà définie** (typiquement un déploiement Docker qui la câble
déjà dans `.env`) : cette variable d'environnement est toujours prioritaire sur ce que la page
enregistre, donc la page le détecte, affiche un avertissement avec la valeur actuelle de la
variable, et masque les boutons plutôt que d'offrir une action sans effet. Pour gérer le
certificat depuis la page à la place, retirez `SB_SBCHAT_CA_BUNDLE` de `.env` (Docker, puis
relancez `./up.sh`) ou de la configuration d'environnement de votre serveur (installation
manuelle), puis rechargez la page.

### 5. Ajouter un agent

Pour chaque agent qui doit utiliser SBChat (SMS, macros, respondants sur appel) :

1. **Prérequis obligatoire** : remplissez d'abord son nom d'utilisateur voip.ms, son mot de
   passe API voip.ms et son numéro de DID sous *Informations du compte* (`?page=userinfo&iduser=X`).
   Sans ces trois champs, la demande de jeton échoue avec le message *"Veuillez remplir votre
   nom d'utilisateur voip.ms ainsi que votre mot de passe API et votre DID..."*.
2. Dans la console d'administration (`?page=adminpanel`), repérez l'agent dans la liste des
   utilisateurs et cliquez sur *SwitchBoard Chat informations*.
3. Cliquez sur *Demander une token API* (visible seulement pour un admin). SwitchBoard appelle
   alors automatiquement l'API de votre serveur SBChat, en utilisant **votre propre** jeton
   (celui du §3) pour authentifier la demande, et enregistre le nouveau jeton généré directement
   sur le compte de l'agent — vous n'avez rien à copier/coller manuellement.

### 6. Compte Matrix de l'agent (optionnel)

Si vous voulez aussi créer ou gérer le compte Matrix (identifiants de connexion au chat) de
l'agent :

1. Menu → *Gérer les utilisateurs du serveur Matrix* (`?page=manageuserssbchat`).
2. Remplissez la partie locale du nom d'utilisateur Matrix (ex. `jdupont` pour
   `@jdupont:matrix.org`), le mot de passe et, si désiré, le courriel.
3. Soumettre : si le compte Matrix n'existe pas encore, il est créé ; s'il existe déjà, son
   mot de passe et/ou son courriel sont mis à jour.

Rappel : cette action utilise elle aussi les identifiants MAS de **votre propre compte admin**
(§3), pas ceux de l'agent.

### 7. Configurer le hook SMS voip.ms

1. Retournez sur la page *Informations SwitchBoard Chat* de l'agent (`?page=sbchatinfo&iduser=X`),
   maintenant que son jeton est configuré (§5).
2. Copiez le lien *Hook SMS à copier/coller dans votre DID voip.ms* affiché sur la page.
3. Dans le portail voip.ms, sous la gestion du DID de cet agent (SMS/MMS), collez cette URL
   comme URL de rappel ("SMS Callback URL").
4. Utilisez le lien *Tester le hook* sur la même page SwitchBoard : la page ouverte doit
   retourner `ok`.

### 8. Activer la synchronisation automatique

Sous *Définir les services utilisés par un utilisateur* (`?page=userservice&iduser=X`), cochez
*Mise à jour de SwitchBoard Chat*. Sans cette case cochée, les tâches cron
(`refreshsbchat.php`, `sendnotificationsbchat.php`) ignorent cet agent, même si son jeton et
son URL d'agenda sont configurés.

Si l'agent doit recevoir des rappels basés sur son agenda de garde, remplissez aussi *URL de
l'agenda* sur sa page *Informations SwitchBoard Chat* (URL privée de son calendrier ICS).

### Dépannage

| Message / symptôme | Cause | Solution |
|---|---|---|
| "sbchatapitoken is empty" / "Please make sure the user requesting a token as already an admin token set." | Le compte **actuellement connecté** (celui qui clique sur "Demander une token API") n'a pas encore de jeton SBChat configuré sur son propre compte. | Configurez d'abord le jeton de démarrage sur le compte admin lui-même (§3). |
| "Veuillez remplir votre nom d'utilisateur voip.ms ainsi que votre mot de passe API et votre DID..." | Les informations voip.ms de l'agent ciblé sont incomplètes. | Complétez `?page=userinfo&iduser=X` pour cet agent (§5, étape 1). |
| "Something went wrong" après une demande de jeton | Le serveur SBChat a refusé ou n'a pas répondu correctement (mauvaise adresse, jeton invalide, serveur injoignable). | Vérifiez l'adresse du serveur et le jeton du compte admin ; testez la connectivité réseau vers le serveur SBChat. |
| Erreur cURL #60 ou #77 | Certificat TLS auto-signé non approuvé (ou chemin de certificat invalide). | Utilisez *Certificat SwitchBoard Chat / MAS* dans la console admin (§4) pour l'approuver en un clic, ou voir la section « Self-signed SBChat certificate » de [README.md](README.md) ou la section « Certificat SBChat auto-signé » de [Docker/README.md](Docker/README.md#fr-sbchat-certificate) pour la méthode manuelle. |
| Erreur cURL #60 : "SSL: no alternative certificate subject name matches target hostname" (même après avoir approuvé le certificat) | Le certificat auto-signé **du serveur SBChat/MAS lui-même** n'a pas d'extension SAN (Subject Alternative Name) — seulement un CN. Les versions récentes d'OpenSSL/cURL exigent une correspondance SAN et ignorent le CN seul. | Régénérez le certificat **sur le serveur SBChat/MAS**, en ajoutant `-addext "subjectAltName=DNS:votre-nom-de-domaine"` à la commande `openssl req`, puis retournez sur *Certificat SwitchBoard Chat / MAS* (§4) pour approuver le nouveau. |
| Même erreur, mais le certificat approuvé (§4) a un sujet/SAN qui ne correspond à *aucun* nom lié à votre serveur SBChat/MAS | L'adresse du serveur (§3) est incomplète (port manquant) : la récupération automatique s'est connectée par défaut au port 443, un tout autre service du même domaine, et son certificat a été approuvé par erreur. | Corrigez l'adresse du serveur (§3, avec le bon port, ex. `:3443`), puis refaites §4 — l'aperçu affiché avant l'approbation indique l'hôte:port exact contacté et avertit si le certificat ne correspond pas, pour éviter de refaire cette erreur. |
| Les champs MAS semblent "configurés" mais les actions Matrix échouent quand même | Ils ont été remplis sur le compte de l'agent au lieu du compte admin qui effectue l'action. | Remplissez `masurl`/`masclientid`/`massecret` sur le compte admin lui-même (§3), pas sur celui de l'agent. |
| Formulaire "Macros SwitchBoard Chat du compte" affiche aussi des champs MAS | Ce formulaire (`?page=sbchatmacros`) contient une copie ancienne des champs MAS, distincte du formulaire principal. Considérez `?page=sbchatinfo` comme la référence à utiliser pour les identifiants MAS. | Utilisez toujours `?page=sbchatinfo` (§3) pour configurer MAS. |

---

## Procedure (English)

### 1. Prerequisites

Before starting, you must already have:
- A running **SBChat** server, reachable over HTTPS (e.g. `https://myserver.org:3443`),
  exposing its API at `{address}/sbchat_api`.
- A **bootstrap ("admin") SBChat API token**, obtained directly from your SBChat server's own
  setup process (SwitchBoard cannot generate this very first token itself — see §3).
- (Optional, only if you'll manage agents' Matrix accounts from SwitchBoard) A running **MAS**
  (Matrix Authentication Service) server, and an OAuth2 client (ID + secret) already
  registered on the MAS side with `urn:mas:admin` scope. That registration happens entirely on
  the MAS side — SwitchBoard only ever uses an ID/secret that already exists.
- If SBChat or MAS uses a self-signed TLS certificate, nothing to prepare in advance: once the
  server address is configured (§3), the admin console can fetch and trust that certificate in
  one click (§4). A manual setting (`$sbchatcabundle` / `SB_SBCHAT_CA_BUNDLE`) is still
  available for when SwitchBoard can't reach the server directly (firewall) — see
  [README.md](README.md) or [Docker/README.md](Docker/README.md#sbchat-certificate).

### 2. Encryption settings

In `.env` (Docker) or `config/config.php`:
- `APP_ENCRYPTION_KEY` (`$appencryptionkey`): strongly recommended. Encrypts the stored SBChat
  token and MAS credentials at rest. Without it, these secrets stay in plaintext in the
  database.

### 3. Configure the administrator account (bootstrap token + MAS)

**Important:** the MAS credentials (`masurl`, `masclientid`, `massecret`) are stored per user
in the database, but the code **always reads them from whoever is currently logged in and
performing the action** — never from the target agent's account. In practice this means:
**fill in these three fields on YOUR OWN admin account**, not on an agent's. Filling them in
elsewhere has no effect.

1. Log in with an administrator account.
2. Menu → *Informations SwitchBoard Chat du compte* (`?page=sbchatinfo`).
3. Fill in, on your own account:
   - **Token API SB Chat**: paste the bootstrap token you obtained in step 1.
   - **Adresse du serveur SwitchBoardChat**: e.g. `https://myserver.org:3443`.
   - **URL du serveur MAS**, **ID du client MAS**, **SECRET du client MAS** (only if you plan
     to manage agents' Matrix accounts — see §5).
4. Submit. Write-once fields (token, secret) show "configuré" once saved; leaving them blank
   on a later save keeps the existing value.

### 4. Trust the self-signed certificate (if needed)

Skip this step if SBChat/MAS uses a normal certificate (Let's Encrypt, etc.).

Once the SwitchBoard Chat server address is configured (§3), the admin console can trust its
certificate directly, without touching `config.php` or transferring any file:

1. Menu → *SwitchBoard Chat* → *Certificat SwitchBoard Chat / MAS* (`?page=sbchatcertificate`,
   admin only).
2. Click *Vérifier le certificat du serveur*. SwitchBoard connects to the address configured in
   the previous step and shows a **preview** of the certificate it currently presents (the
   actual host:port contacted, subject, alternative names, expiration date, fingerprint) —
   nothing is trusted yet at this point.
3. **Check the preview before continuing**, especially if a warning saying the certificate
   "does NOT seem to match the expected server" appears: that means the certificate obtained
   doesn't cover the hostname that was contacted, typically because the configured address is
   missing its port and ends up connecting to a different service on the same domain. If that
   happens, click *Annuler* (cancel), fix the server address (§3), and try again instead of
   trusting it.
4. If the preview looks right, click *Oui, approuver ce certificat* (yes, trust this
   certificate).
5. When the certificate is later renewed (expires), come back to this page and repeat the same
   steps — no need to redo the manual process.

If SwitchBoard can't reach the SBChat/MAS server directly (firewall), the same page offers an
alternative: paste in the contents of a `.pem` file obtained another way (e.g.
`openssl s_client -connect yourserver:port | openssl x509`, run from a machine that does have
access to the server) — a preview is shown the same way before trusting it.

**If SBChat/MAS's certificate becomes a real certificate issued by a recognized authority**
(e.g. you move from self-signed to Let's Encrypt), remove the trusted certificate here instead
of leaving it in place. Trusting one specific certificate makes SwitchBoard trust ONLY that
exact file, instead of the system's normal trust — at the next renewal (60-90 days for Let's
Encrypt), the new certificate won't match anymore and the connection will fail again, even
though the new certificate is perfectly valid. This page detects that case automatically (it
shows "Ce certificat n'est pas auto-signé") and offers a *Supprimer le certificat approuvé*
(remove the trusted certificate) button: after removing it, SwitchBoard uses the system's normal
trust, which keeps working on its own through renewals.

**If `SB_SBCHAT_CA_BUNDLE` is already set** (typically a Docker deployment that already wires
it into `.env`): that environment variable always takes priority over what this page saves, so
the page detects this, shows a warning with the variable's current value, and hides the buttons
instead of offering an action with no effect. To manage the certificate from the page instead,
remove `SB_SBCHAT_CA_BUNDLE` from `.env` (Docker, then run `./up.sh` again) or from your
server's environment configuration (manual install), then reload the page.

### 5. Add an agent

For each agent who needs SBChat (SMS, macros, on-call respondents):

1. **Hard prerequisite**: first fill in their voip.ms username, voip.ms API password, and DID
   number under *Informations du compte* (`?page=userinfo&iduser=X`). Without all three, the
   token request fails with *"Veuillez remplir votre nom d'utilisateur voip.ms ainsi que votre
   mot de passe API et votre DID..."*.
2. In the admin console (`?page=adminpanel`), find the agent in the user list and click
   *SwitchBoard Chat informations*.
3. Click *Demander une token API* (admin-only). SwitchBoard then automatically calls your
   SBChat server's API, authenticating the request with **your own** token (from §3), and
   stores the newly-minted token directly on the agent's account — nothing to copy/paste
   manually.

### 6. Agent's Matrix account (optional)

If you also want to create or manage the agent's Matrix login (chat credentials):

1. Menu → *Gérer les utilisateurs du serveur Matrix* (`?page=manageuserssbchat`).
2. Fill in the Matrix username's local part (e.g. `jdupont` for `@jdupont:matrix.org`), the
   password, and optionally the email.
3. Submit: if the Matrix account doesn't exist yet, it's created; if it already exists, its
   password and/or email are updated.

Reminder: this action also uses the MAS credentials from **your own admin account** (§3), not
the agent's.

### 7. Configure the voip.ms SMS hook

1. Go back to the agent's *Informations SwitchBoard Chat* page (`?page=sbchatinfo&iduser=X`),
   now that their token is configured (§5).
2. Copy the *Hook SMS à copier/coller dans votre DID voip.ms* link shown on the page.
3. In the voip.ms portal, under that agent's DID management (SMS/MMS), paste this URL as the
   SMS callback URL.
4. Use the *Tester le hook* link on the same SwitchBoard page: the resulting page should
   return `ok`.

### 8. Enable automatic syncing

Under *Définir les services utilisés par un utilisateur* (`?page=userservice&iduser=X`), check
*Mise à jour de SwitchBoard Chat*. Without this box checked, the cron jobs
(`refreshsbchat.php`, `sendnotificationsbchat.php`) skip this agent even if their token and
calendar URL are configured.

If the agent should receive reminders based on their on-call schedule, also fill in *URL de
l'agenda* on their *Informations SwitchBoard Chat* page (their private ICS calendar URL).

### Troubleshooting

| Message / symptom | Cause | Fix |
|---|---|---|
| "sbchatapitoken is empty" / "Please make sure the user requesting a token as already an admin token set." | The **currently logged-in** account (the one clicking "Demander une token API") has no SBChat token configured on its own account yet. | Configure the bootstrap token on the admin account itself first (§3). |
| "Veuillez remplir votre nom d'utilisateur voip.ms ainsi que votre mot de passe API et votre DID..." | The target agent's voip.ms info is incomplete. | Fill in `?page=userinfo&iduser=X` for that agent (§5, step 1). |
| "Something went wrong" after requesting a token | The SBChat server rejected or didn't respond correctly (wrong address, invalid token, unreachable server). | Check the server address and the admin account's token; test network connectivity to the SBChat server. |
| cURL error #60 or #77 | Untrusted self-signed TLS certificate (or an invalid certificate path). | Use *Certificat SwitchBoard Chat / MAS* in the admin console (§4) to trust it in one click, or see the "Self-signed SBChat certificate" section of [README.md](README.md) or [Docker/README.md](Docker/README.md#sbchat-certificate) for the manual method. |
| cURL error #60: "SSL: no alternative certificate subject name matches target hostname" (even after trusting the certificate) | The SBChat/MAS server's own self-signed certificate has no Subject Alternative Name (SAN) extension — only a CN. Recent OpenSSL/curl versions require a SAN match and ignore CN alone. | Regenerate the certificate **on the SBChat/MAS server**, adding `-addext "subjectAltName=DNS:your-domain-name"` to the `openssl req` command, then go back to *Certificat SwitchBoard Chat / MAS* (§4) to trust the new one. |
| Same error, but the trusted certificate (§4) has a subject/SAN that doesn't match *any* name related to your SBChat/MAS server | The server address (§3) is incomplete (missing port): the automatic fetch connected to port 443 by default, a completely different service on the same domain, and its certificate got trusted by mistake. | Fix the server address (§3, with the correct port, e.g. `:3443`), then redo §4 — the preview shown before trusting now displays the exact host:port contacted and warns if the certificate doesn't match, to prevent repeating this mistake. |
| MAS fields look "configured" but Matrix actions still fail | They were filled in on the agent's account instead of the admin account performing the action. | Fill in `masurl`/`masclientid`/`massecret` on the admin account itself (§3), not the agent's. |
| "Macros SwitchBoard Chat du compte" form also shows MAS fields | That form (`?page=sbchatmacros`) contains an older, separate copy of the MAS fields, distinct from the main form. Treat `?page=sbchatinfo` as the source of truth. | Always use `?page=sbchatinfo` (§3) to configure MAS. |
