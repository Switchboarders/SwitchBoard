# Configuration du courriel OAuth2 (Microsoft 365 / Google) / OAuth2 mail setup

Ce guide couvre l'authentification OAuth2 pour le courriel, nécessaire pour Microsoft 365 (qui
retire progressivement les mots de passe simples pour le SMTP) et disponible aussi pour Google.
Deux étapes existent en dehors de SwitchBoard, dans la console du fournisseur lui-même — rien
dans SwitchBoard ne peut les éliminer, seulement les rendre inutiles à refaire ensuite.

This guide covers OAuth2 mail authentication, needed for Microsoft 365 (which is phasing out
plain SMTP passwords) and also available for Google. Two steps happen outside SwitchBoard, in
the provider's own console — nothing in SwitchBoard can eliminate them, only make them a
one-time thing you never touch again.

---

## Procédure (Français)

### 1. Prérequis

Si vous préférez un simple mot de passe (compte Google avec mot de passe d'application, par
exemple), OAuth n'est pas nécessaire — voir la section courriel de [Docker/README.fr.md](Docker/README.fr.md#mail).
Utilisez ce guide seulement si votre fournisseur l'exige (Microsoft 365, de plus en plus) ou si
vous préférez OAuth.

### 2A. Microsoft 365 : inscrire l'application

1. Allez sur [portal.azure.com](https://portal.azure.com) → *Microsoft Entra ID* → *Inscriptions
   d'applications* → *Nouvelle inscription*.
2. Nom : ce que vous voulez (ex. « SwitchBoard Mail »). Types de comptes pris en charge :
   « Comptes dans cet annuaire organisationnel uniquement » suffit dans la plupart des cas.
   Aucun URI de redirection n'est nécessaire : le flux utilisé (« device code ») n'en demande pas.
3. Une fois créée, notez l'**ID d'application (client)** affiché sur la page de présentation —
   c'est votre `SB_SMTP_OAUTH_CLIENT_ID`. Notez aussi, juste à côté, l'**ID de l'annuaire (locataire)**
   (Tenant ID) — avec le type de compte par défaut choisi à l'étape 2 (mono-tenant), ce Tenant ID
   est **obligatoire**, voir l'étape 7 ci-dessous.
4. *Authentification* → activez **« Autoriser les flux clients publics »** (obligatoire pour le
   flux device code, qui ne demande pas de secret).
5. Rien à faire dans *Autorisations API* : contrairement au flux « application » (client
   credentials), le flux device code utilisé ici demande la permission `SMTP.Send` directement
   au moment de l'autorisation (depuis la console admin SwitchBoard, *Courriel OAuth*) — c'est
   Microsoft qui affiche alors l'écran de consentement, sans qu'il faille chercher/ajouter cette
   permission soi-même dans le portail au préalable. (Cette permission délégué n'apparaît d'ailleurs
   pas toujours dans le sélecteur du portail, même quand on cherche « Office 365 Exchange Online »
   - normal, elle n'a jamais besoin d'y être ajoutée pour ce flux.)
6. Si votre tenant exige un consentement administrateur, l'écran de consentement affiché pendant
   l'autorisation (étape suivante) vous le dira à ce moment-là ; sinon, demandez à votre
   administrateur Microsoft 365 de compléter l'autorisation une première fois, ou d'accorder le
   consentement pour l'application depuis *Entreprise applications* dans le portail Azure.
7. **Champ Tenant dans SwitchBoard :** avec le type de compte par défaut (mono-tenant), collez-y
   l'**ID de l'annuaire (locataire)** noté à l'étape 3 (ou le nom de domaine de votre organisation, ex.
   `contoso.onmicrosoft.com`) — ne le laissez PAS vide. Le laisser vide envoie la demande
   d'autorisation à l'adresse générique `common`, que Microsoft refuse pour une inscription
   mono-tenant avec l'erreur **AADSTS50059** (« No tenant-identifying information found »). Ce
   champ ne peut rester vide que si vous avez explicitement choisi un type de compte multi-tenant
   à l'étape 2 (rare, pas nécessaire pour un usage normal).

### 2B. Microsoft 365 : activer le SMTP authentifié sur la boîte

Nécessite un accès administrateur Microsoft 365 — désactivé par défaut sur les tenants récents,
et distinct de l'inscription de l'application ci-dessus.

**Option A (PowerShell, la plus fiable) :**
```
Install-Module -Name ExchangeOnlineManagement
Connect-ExchangeOnline
Set-CASMailbox -Identity agent@votredomaine.com -SmtpClientAuthenticationDisabled $false
```

**Option B (centre d'administration Microsoft 365) :** *Utilisateurs actifs* → sélectionnez
l'utilisateur → onglet *Courrier* → *Gérer les applications de messagerie* → activez
**« SMTP authentifié »**.

### 2C. Microsoft 365 : éviter que le courriel arrive dans les pourriels (SPF/DKIM/DMARC)

Sans lien avec SwitchBoard ou l'autorisation OAuth elle-même - l'envoi peut très bien
« réussir » (aucune erreur SMTP) tout en atterrissant dans les pourriels côté destinataire, si le
domaine d'envoi n'a pas ces trois enregistrements DNS :

1. **SPF** (`TXT` sur le domaine, ex. `exemple.org`) : doit inclure
   `include:spf.protection.outlook.com`. La plupart des tenants Microsoft 365 l'ont déjà par
   défaut - vérifiez avec `nslookup -type=txt votredomaine.com` (ou un outil en ligne comme
   mxtoolbox.com).
2. **DKIM** : PAS activé par défaut sur un domaine personnalisé (seul le domaine
   `*.onmicrosoft.com` l'a automatiquement). À activer dans le *Centre d'administration Microsoft
   Defender* → *Email & collaboration → Règles → Règles de menace → Paramètres
   d'authentification du courrier → DKIM* → sélectionnez votre domaine → Microsoft fournit deux
   enregistrements `CNAME` (`selector1._domainkey` et `selector2._domainkey`) à ajouter chez
   votre hébergeur DNS, puis cliquez **Activer**. Vérifiez avec
   `nslookup -type=cname selector1._domainkey.votredomaine.com` - une réponse vide signifie que
   ce n'est pas configuré.
3. **DMARC** (`TXT` sur `_dmarc.votredomaine.com`) : absent par défaut, aucun enregistrement
   automatique. Commencez en mode observation :
   `v=DMARC1; p=none; rua=mailto:vous@votredomaine.com`, resserrez plus tard vers `p=quarantine`
   une fois confirmé que le courrier légitime passe bien.

Sans DKIM ni DMARC, le SPF seul ne suffit souvent plus pour les filtres antipourriel modernes
(Gmail en particulier) - c'est la cause la plus fréquente d'un courriel qui « part » sans erreur
mais atterrit quand même dans les pourriels.

### 3A. Google : projet et identifiants OAuth

1. [console.cloud.google.com](https://console.cloud.google.com) → créez un projet (ou utilisez
   un projet existant).
2. *API et services* → *Écran de consentement OAuth* → type **« Externe »** → remplissez le nom
   de l'app et un courriel de support. Dans **« Utilisateurs test »**, ajoutez l'adresse Gmail
   que vous allez utiliser pour l'envoi.
   L'app reste en mode « Test », ce qui est normal et suffisant ici : chaque déploiement
   SwitchBoard a sa propre inscription pour 1 ou 2 boîtes, pas une application partagée qui
   nécessiterait la vérification complète de Google.
3. *Identifiants* → *Créer des identifiants* → **« ID client OAuth »** → type d'application
   **« Application Web »**.
4. Dans **« URI de redirection autorisés »**, ajoutez l'URL exacte de la page d'autorisation
   SwitchBoard, par exemple :
   `https://sb.mondomaine.com/SwitchBoard/SwitchBoard/src/managementportal.php?page=mailoauth`
   (adaptez selon l'emplacement réel de `managementportal.php` sur votre serveur — l'URL doit
   correspondre exactement, sans quoi Google refusera l'échange).
5. Notez l'**ID client** (`SB_SMTP_OAUTH_CLIENT_ID`) et le **code secret du client**
   (`SB_SMTP_OAUTH_CLIENT_SECRET`).

### 4. Configurer SwitchBoard

**Docker (recommandé) :** lors de l'assistant `./up.sh`, choisissez l'option **2) OAuth2** pour
le courriel, indiquez le fournisseur, l'adresse courriel et le Client ID (+ Client Secret pour
Google). Vous pouvez aussi éditer `.env` directement : `SB_SMTP_OAUTH_PROVIDER`
(`microsoft365` ou `google`), `SB_SMTP_OAUTH_CLIENT_ID`, `SB_SMTP_OAUTH_CLIENT_SECRET` (Google
seulement), `SB_SMTP_USER` (l'adresse à autoriser).

**Installation manuelle :** ces mêmes réglages existent dans `config/config.php`
(`$smtpoauthprovider`, `$smtpoauthclientid`, `$smtpoauthclientsecret`, `$smtpoauthtenant`), mais
vous devez vous-même installer et configurer msmtp (ou équivalent) sur votre serveur, avec son
`passwordeval` pointant vers `php /chemin/vers/SwitchBoard/src/mailoauthtoken.php` — SwitchBoard
ne génère la configuration système du courriel automatiquement que dans la version Docker.

### 5. Autoriser depuis la console admin

1. Connectez-vous en tant qu'administrateur, menu → *Console d'administration* → **Courriel
   OAuth** (ou `?page=mailoauth`).
2. **Microsoft 365 :** cliquez *Démarrer l'autorisation*, un code s'affiche avec un lien —
   ouvrez ce lien sur n'importe quel appareil (pas forcément celui-ci), entrez le code, connectez-
   vous et autorisez. Revenez sur SwitchBoard et cliquez *J'ai autorisé, continuer* (si
   l'autorisation n'est pas encore terminée, cliquez de nouveau après l'avoir complétée). Le code
   reste affiché sur cette page même après un rechargement, pour le cas où vous devez le retrouver.
   Si vous vous êtes trompé en l'entrant chez Microsoft (ou si le code a expiré), cliquez *Annuler
   et recommencer* pour obtenir un nouveau code.
3. **Google :** cliquez le lien *Autoriser Google*, connectez-vous et autorisez dans la fenêtre
   qui s'ouvre — Google vous ramène automatiquement sur SwitchBoard, qui termine l'échange
   lui-même.
4. **Installation Docker seulement :** si c'est la première fois que le courriel OAuth est
   configuré sur ce conteneur (réglages et/ou autorisation faits depuis cet assistant après le
   dernier démarrage), redémarrez le conteneur php une fois : `docker compose restart php`
   (depuis le dossier `Docker/`) — sans ce redémarrage, l'autorisation est bien enregistrée mais
   l'envoi de courriel continue d'utiliser l'ancien réglage jusque-là. La page *Courriel OAuth*
   vous le rappelle automatiquement tant que ce n'est pas fait. (Installation manuelle : pas
   nécessaire, `mailoauthtoken.php` relit déjà la configuration à chaque envoi.)
5. Une fois autorisé (et le conteneur redémarré si nécessaire), testez avec *Courriel de test*
   (`?page=mailtest`) — le rafraîchissement du jeton ensuite est entièrement automatique, rien à
   refaire.

### Dépannage

| Message / symptôme | Cause | Solution |
|---|---|---|
| « AADSTS700038 : ... n'est pas un identifiant d'application valide » | Le `SB_SMTP_OAUTH_CLIENT_ID` ne correspond à aucune inscription Azure AD réelle. | Vérifiez que vous avez copié l'« ID d'application (client) », pas l'« ID d'objet » ni l'« ID de l'annuaire (locataire) ». |
| « AADSTS50059 : Aucune information d'identification du tenant... » | Le champ Tenant est vide (envoie la demande à `common`) alors que votre inscription Azure est mono-tenant (réglage par défaut). | Remplissez le champ Tenant avec l'ID de l'annuaire (locataire) ou le domaine de votre organisation (voir étape 2A.7), puis relancez l'autorisation. |
| Échec du consentement / « besoin de l'approbation d'un administrateur » | Votre tenant Microsoft 365 exige un consentement administrateur pour les autorisations déléguées. | Demandez à votre administrateur Microsoft 365 de compléter l'autorisation depuis SwitchBoard une première fois, ou d'accorder le consentement pour l'application depuis *Entreprise applications* dans le portail Azure (voir étape 2A.6). |
| Authentification réussie mais l'envoi échoue quand même (535, « authentification refusée ») | Le SMTP authentifié n'est pas activé sur la boîte (§2B), même si l'autorisation OAuth elle-même a réussi. | Faites activer « SMTP authentifié » sur cette boîte précise par un administrateur Microsoft 365. |
| « msmtp: the server does not support authentication method OAUTHBEARER » | Bug corrigé (commit qui suit "Rappelle de redémarrer..." dans l'historique) : `generate_mail_config.sh` réglait `auth oauthbearer`, mais Microsoft 365 et Google n'acceptent que le mécanisme **XOAUTH2** en SMTP, pas OAUTHBEARER (deux mécanismes SASL différents, malgré le nom voisin) - msmtp les distingue et refuse d'utiliser celui que le serveur n'annonce pas. | Mettez à jour vers la dernière version (`git pull` **dans le dossier de déploiement Docker lui-même**, pas seulement dans `Docker/web/`), puis `docker compose build php && docker compose up -d` - un simple `restart` ne suffit pas ici, `generate_mail_config.sh` est intégré à l'image au moment du build, pas seulement monté dans le conteneur. |
| « msmtp: cannot connect to smtp.office365.com, port 587: Network is unreachable » | Bug corrigé (image Docker) : certains hôtes Docker répondent avec des adresses IPv6 (AAAA) pour `smtp.office365.com` alors que le conteneur n'a aucune connectivité IPv6 réelle - msmtp essaie ces adresses en premier et échoue, sans jamais retomber sur les adresses IPv4 pourtant présentes dans la même réponse DNS. | Mettez à jour vers la dernière version puis `docker compose build php && docker compose up -d` (l'image ajoute une règle de préférence IPv4 dans `/etc/gai.conf`). |
| « msmtp: authentication failed (method XOAUTH2) / 535 5.7.3 Authentication unsuccessful » après avoir autorisé une DEUXIÈME boîte courriel depuis l'assistant | Bug corrigé : si une première boîte avait déjà été autorisée et le conteneur redémarré au moins une fois, `/etc/msmtprc` contient déjà « auth xoauth2 » en permanence - l'avis « redémarrage requis » (basé uniquement sur cette présence) ne s'affichait donc plus en changeant de boîte, alors que `/etc/msmtprc` continuait d'utiliser l'ANCIENNE adresse comme utilisateur SMTP tout en présentant le jeton de la NOUVELLE boîte, ce que Microsoft/Google refusent. | Mettez à jour vers la dernière version (l'avis « redémarrage requis » détecte maintenant aussi un changement de boîte, pas seulement l'absence du mode OAuth), puis redémarrez le conteneur : `docker compose restart php`. |
| « msmtp: cannot read output of '...mailoauthtoken.php' » / « sh: 1: php: not found » dans les journaux, et un courriel qui devrait partir (ex. : rapport d'erreur pour un numéro de téléphone invalide) ne part jamais | Bug corrigé : `generate_mail_config.sh` réglait `passwordeval "php ..."` sans chemin absolu - fonctionne dans un shell interactif normal, mais échoue silencieusement lorsque msmtp est déclenché depuis une tâche cron, dont le sous-shell n'a pas toujours `/usr/local/bin` dans son PATH. | Mettez à jour vers la dernière version puis `docker compose build php && docker compose up -d` (le chemin est maintenant explicite : `/usr/local/bin/php`). |
| « The OAuth client was not found » (Google) | Le `SB_SMTP_OAUTH_CLIENT_ID`/`SB_SMTP_OAUTH_CLIENT_SECRET` ne correspond à aucun client OAuth Google réel, ou vous avez copié les mauvaises valeurs. | Revérifiez les identifiants dans *Identifiants* sur console.cloud.google.com. |
| « redirect_uri_mismatch » (Google) | L'URI de redirection enregistré dans Google Cloud Console ne correspond pas EXACTEMENT à l'URL de `?page=mailoauth` sur votre serveur. | Comparez caractère par caractère (http vs https, présence ou non d'un `/` final, chemin exact) et corrigez dans Google Cloud Console. |
| L'écran de consentement Google affiche « Cette application n'est pas vérifiée » | Normal : votre application reste en mode « Test » (voir §3A.2), ce qui est prévu pour un usage personnel/interne. | Cliquez « Options avancées » puis « Accéder à [nom de l'app] (non sécurisé) » pour continuer — assurez-vous seulement que le compte que vous autorisez est bien dans la liste des « Utilisateurs test ». |
| Ancien jeton oublié, mauvais compte autorisé | Une autorisation précédente est toujours active. | *Console d'administration → Courriel OAuth → Retirer l'autorisation*, puis recommencez avec le bon compte. |

---

## Procedure (English)

### 1. Prerequisites

If you'd rather use a simple password (a Google account with an app password, for example),
OAuth isn't necessary — see the mail section of [Docker/README.md](Docker/README.md#mail). Use this
guide only if your provider requires it (Microsoft 365, increasingly) or if you prefer OAuth.

### 2A. Microsoft 365: register the application

1. Go to [portal.azure.com](https://portal.azure.com) → *Microsoft Entra ID* → *App
   registrations* → *New registration*.
2. Name: anything (e.g. "SwitchBoard Mail"). Supported account types: "Accounts in this
   organizational directory only" is enough in most cases. No redirect URI is needed: the flow
   used (device code) doesn't require one.
3. Once created, note the **Application (client) ID** shown on the overview page — that's your
   `SB_SMTP_OAUTH_CLIENT_ID`. Also note the **Directory (tenant) ID** shown right next to it —
   with the default single-tenant account type from step 2, this Tenant ID is **required**, see
   step 7 below.
4. *Authentication* → enable **"Allow public client flows"** (required for the device code
   flow, which needs no secret).
5. Nothing to do under *API permissions*: unlike the "application" (client credentials) flow, the
   device code flow used here requests the `SMTP.Send` permission directly at authorization time
   (from the SwitchBoard admin console, *Mail OAuth*) — Microsoft shows the consent screen then,
   with no need to hunt down and pre-add this permission in the portal first. (This delegated
   permission often doesn't even show up in the portal's picker, even when searching "Office 365
   Exchange Online" — that's expected, it never needs to be added there for this flow.)
6. If your tenant requires admin consent, the consent screen shown during authorization (next
   step) will say so at that point; otherwise, ask your Microsoft 365 admin to complete the
   authorization once, or to grant consent for the app from *Enterprise applications* in the
   Azure portal.
7. **Tenant field in SwitchBoard:** with the default single-tenant account type, paste in the
   **Tenant ID** you noted in step 3 (or your organization's domain, e.g.
   `contoso.onmicrosoft.com`) — do NOT leave it blank. Leaving it blank sends the authorization
   request to the generic `common` endpoint, which Microsoft rejects for a single-tenant
   registration with error **AADSTS50059** ("No tenant-identifying information found"). Only
   leave this blank if you deliberately chose a multi-tenant account type in step 2 (uncommon,
   not needed for normal use).

### 2B. Microsoft 365: enable Authenticated SMTP on the mailbox

Requires Microsoft 365 admin access — off by default on modern tenants, and separate from the
app registration above.

**Option A (PowerShell, most reliable):**
```
Install-Module -Name ExchangeOnlineManagement
Connect-ExchangeOnline
Set-CASMailbox -Identity agent@yourdomain.com -SmtpClientAuthenticationDisabled $false
```

**Option B (Microsoft 365 admin center):** *Active users* → select the user → *Mail* tab →
*Manage email apps* → enable **"Authenticated SMTP"**.

### 2C. Microsoft 365: keeping mail out of spam (SPF/DKIM/DMARC)

Unrelated to SwitchBoard or the OAuth authorization itself — sending can "succeed" (no SMTP
error at all) while the message still lands in spam on the recipient's end, if the sending
domain is missing these three DNS records:

1. **SPF** (`TXT` on the domain, e.g. `yourdomain.com`): must include
   `include:spf.protection.outlook.com`. Most Microsoft 365 tenants already have this by
   default — check with `nslookup -type=txt yourdomain.com` (or an online tool like
   mxtoolbox.com).
2. **DKIM**: NOT enabled by default on a custom domain (only the `*.onmicrosoft.com` domain
   gets it automatically). Enable it in the *Microsoft Defender admin center* → *Email &
   collaboration → Policies & rules → Threat policies → Email authentication settings → DKIM* →
   select your domain → Microsoft gives you two `CNAME` records (`selector1._domainkey` and
   `selector2._domainkey`) to add at your DNS host, then click **Enable**. Check with
   `nslookup -type=cname selector1._domainkey.yourdomain.com` — an empty answer means it isn't
   configured yet.
3. **DMARC** (`TXT` on `_dmarc.yourdomain.com`): missing by default, no automatic record. Start
   in monitoring mode: `v=DMARC1; p=none; rua=mailto:you@yourdomain.com`, tighten to
   `p=quarantine` later once you've confirmed legitimate mail passes through fine.

Without DKIM and DMARC, SPF alone is often no longer enough for modern spam filters (Gmail
especially) — this is the most common reason mail "sends" with no error but still ends up in
spam.

### 3A. Google: project and OAuth credentials

1. [console.cloud.google.com](https://console.cloud.google.com) → create a project (or use an
   existing one).
2. *APIs & Services* → *OAuth consent screen* → type **"External"** → fill in the app name and
   a support email. Under **"Test users"**, add the Gmail address you'll use for sending.
   The app stays in "Testing" mode, which is normal and sufficient here: each SwitchBoard
   deployment has its own registration for 1-2 mailboxes, not a shared app that would need
   full Google verification.
3. *Credentials* → *Create credentials* → **"OAuth client ID"** → application type
   **"Web application"**.
4. Under **"Authorized redirect URIs"**, add the exact URL of SwitchBoard's authorization page,
   e.g.: `https://sb.yourdomain.com/SwitchBoard/SwitchBoard/src/managementportal.php?page=mailoauth`
   (adjust to where `managementportal.php` actually lives on your server — this must match
   exactly, or Google will refuse the exchange).
5. Note the **Client ID** (`SB_SMTP_OAUTH_CLIENT_ID`) and **Client secret**
   (`SB_SMTP_OAUTH_CLIENT_SECRET`).

### 4. Configure SwitchBoard

**Docker (recommended):** during the `./up.sh` wizard, choose option **2) OAuth2** for mail,
provide the provider, mailbox address, and Client ID (+ Client Secret for Google). You can also
edit `.env` directly: `SB_SMTP_OAUTH_PROVIDER` (`microsoft365` or `google`),
`SB_SMTP_OAUTH_CLIENT_ID`, `SB_SMTP_OAUTH_CLIENT_SECRET` (Google only), `SB_SMTP_USER` (the
address to authorize).

**Manual install:** the same settings exist in `config/config.php` (`$smtpoauthprovider`,
`$smtpoauthclientid`, `$smtpoauthclientsecret`, `$smtpoauthtenant`), but you must install and
configure msmtp (or equivalent) on your own server, with its `passwordeval` pointing at
`php /path/to/SwitchBoard/src/mailoauthtoken.php` — SwitchBoard only auto-generates the system
mail configuration in the Docker setup.

### 5. Authorize from the admin console

1. Log in as an administrator, menu → *Console d'administration* → **Courriel OAuth**
   (or `?page=mailoauth`).
2. **Microsoft 365:** click *Démarrer l'autorisation*, a code appears with a link — open that
   link on any device (not necessarily this one), enter the code, sign in, and authorize. Come
   back to SwitchBoard and click *J'ai autorisé, continuer* (if authorization isn't finished
   yet, click again once it is). The code stays visible on this page even after a reload, in
   case you need to find it again. If you mistyped it on Microsoft's site (or it expired), click
   *Annuler et recommencer* to get a fresh code.
3. **Google:** click the *Autoriser Google* link, sign in and authorize in the window that
   opens — Google redirects back to SwitchBoard automatically, which completes the exchange
   itself.
4. **Docker installs only:** if this is the first time OAuth mail is being configured on this
   container (settings and/or authorization done from this wizard since the container's last
   start), restart the php container once: `docker compose restart php` (from the `Docker/`
   folder) — without this restart, the authorization is saved correctly but mail sending keeps
   using the old setting until then. The *Courriel OAuth* page reminds you of this automatically
   until it's done. (Manual install: not needed, `mailoauthtoken.php` already re-reads the
   config on every send.)
5. Once authorized (and the container restarted if needed), test with *Courriel de test*
   (`?page=mailtest`) — token refresh from then on is fully automatic, nothing to redo.

### Troubleshooting

| Message / symptom | Cause | Fix |
|---|---|---|
| "AADSTS700038: ... is not a valid application identifier" | `SB_SMTP_OAUTH_CLIENT_ID` doesn't match any real Azure AD registration. | Make sure you copied the "Application (client) ID", not the "Object ID" or "Directory (tenant) ID". |
| "AADSTS50059: No tenant-identifying information found..." | The Tenant field is empty (sends the request to `common`) while your Azure registration is single-tenant (the default). | Fill in the Tenant field with your organization's Tenant ID or domain (see step 2A.7), then retry authorization. |
| Consent fails / "needs admin approval" | Your Microsoft 365 tenant requires admin consent for delegated permissions. | Ask your Microsoft 365 admin to complete the authorization from SwitchBoard once, or to grant consent for the app from *Enterprise applications* in the Azure portal (see step 2A.6). |
| Authorization succeeds but sending still fails (535, "authentication unsuccessful") | Authenticated SMTP isn't enabled on the mailbox (§2B), even though the OAuth authorization itself worked. | Have a Microsoft 365 admin enable "Authenticated SMTP" on that specific mailbox. |
| "msmtp: the server does not support authentication method OAUTHBEARER" | Fixed bug (commit after "Rappelle de redémarrer..." in history): `generate_mail_config.sh` set `auth oauthbearer`, but Microsoft 365 and Google only accept the **XOAUTH2** mechanism for SMTP, not OAUTHBEARER (two different SASL mechanisms despite the similar name) - msmtp tells them apart and refuses to use one the server doesn't advertise. | Update to the latest version (`git pull` **in the Docker deployment folder itself**, not just `Docker/web/`), then `docker compose build php && docker compose up -d` - a plain `restart` isn't enough here, `generate_mail_config.sh` is baked into the image at build time, not just mounted into the container. |
| "msmtp: cannot connect to smtp.office365.com, port 587: Network is unreachable" | Fixed bug (Docker image): some Docker hosts return IPv6 (AAAA) addresses for `smtp.office365.com` even though the container has no real IPv6 connectivity - msmtp tries those addresses first and fails, without ever falling back to the IPv4 addresses present in the same DNS response. | Update to the latest version, then `docker compose build php && docker compose up -d` (the image now adds an IPv4-preference rule to `/etc/gai.conf`). |
| "msmtp: authentication failed (method XOAUTH2) / 535 5.7.3 Authentication unsuccessful" after authorizing a SECOND mailbox from the wizard | Fixed bug: if a first mailbox had already been authorized and the container restarted at least once, `/etc/msmtprc` already contains "auth xoauth2" permanently - the "restart needed" notice (based only on that presence) then stopped showing when switching mailboxes, while `/etc/msmtprc` kept using the OLD address as the SMTP user while presenting the NEW mailbox's token, which Microsoft/Google reject. | Update to the latest version (the "restart needed" notice now also detects a mailbox change, not just OAuth mode being absent), then restart the container: `docker compose restart php`. |
| "msmtp: cannot read output of '...mailoauthtoken.php'" / "sh: 1: php: not found" in the logs, and an email that should send (e.g. an error report for an invalid phone number) never does | Fixed bug: `generate_mail_config.sh` set `passwordeval "php ..."` without an absolute path - works fine in a normal interactive shell, but fails silently when msmtp is triggered from a cron job, whose sub-shell doesn't always have `/usr/local/bin` in its PATH. | Update to the latest version, then `docker compose build php && docker compose up -d` (the path is now explicit: `/usr/local/bin/php`). |
| "The OAuth client was not found" (Google) | `SB_SMTP_OAUTH_CLIENT_ID`/`SB_SMTP_OAUTH_CLIENT_SECRET` doesn't match any real Google OAuth client, or you copied the wrong values. | Double-check the credentials under *Credentials* on console.cloud.google.com. |
| "redirect_uri_mismatch" (Google) | The redirect URI registered in Google Cloud Console doesn't EXACTLY match the `?page=mailoauth` URL on your server. | Compare character-by-character (http vs https, trailing slash, exact path) and fix it in Google Cloud Console. |
| Google's consent screen says "This app isn't verified" | Expected: your app stays in "Testing" mode (see §3A.2), which is intended for personal/internal use. | Click "Advanced" then "Go to [app name] (unsafe)" to continue — just make sure the account you're authorizing is listed under "Test users". |
| Old token still around, wrong account authorized | A previous authorization is still active. | *Console d'administration → Courriel OAuth → Retirer l'autorisation*, then start over with the correct account. |
