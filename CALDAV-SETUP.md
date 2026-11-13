# Agenda CalDAV (compte cPanel) / CalDAV calendar setup

Ce guide explique comment faire lire à SwitchBoard l'agenda d'un comité hébergé sur le cPanel de
son propre domaine, au lieu d'une « URL secrète » Google ou Outlook. Il ne concerne que l'agenda
principal (mise à jour des groupes de sonneries et rappels SMS) ; le calendrier de SwitchBoard
Chat (`sbchatcalendarurl`) n'est pas touché.

This guide explains how to have SwitchBoard read a committee's calendar hosted on its own domain's
cPanel, instead of a Google or Outlook "secret URL". It only applies to the main calendar (ring
group updates and SMS reminders); the SwitchBoard Chat calendar (`sbchatcalendarurl`) is unchanged.

---

## Procédure (Français)

### 1. Quand l'utiliser

- **Google ou Outlook** : gardez le type « URL secrète (Google / Outlook) ». Rien à changer.
- **Agenda hébergé sur le cPanel du domaine du comité** (RoundCube) : utilisez le type « CalDAV ».
  Il n'existe pas d'URL secrète pour ce genre d'agenda ; SwitchBoard s'authentifie avec un nom
  d'utilisateur et un mot de passe.

### 2. Préparer le compte dans cPanel

1. **Créer un compte courriel dédié à l'agenda** (ex. `agenda@exemple.org`). Un compte séparé
   évite de partager le mot de passe d'un vrai courriel.
2. Dans *Comptes de messagerie*, vérifier que le statut est « Non restreint ». Un compte neuf peut
   être « Restricted » au début ; tant qu'il l'est, l'accès échoue avec une erreur 401.
3. Régler les restrictions du compte : **Logging In → Allow** (c'est ce curseur qui commande
   l'accès au webmail *et* à CalDAV) ; **Receiving** et **Sending** peuvent rester suspendus, ce qui
   rend le compte étanche au vrai courriel sans gêner le calendrier.
4. Ouvrir le webmail (RoundCube) de ce compte. Il contient **deux calendriers** :
   - « Default » : stocké dans la base interne de RoundCube, **jamais accessible en CalDAV** ;
   - **« cPanel CalDAV Calendar »** : le seul que SwitchBoard peut lire.

   **Créez tous les événements dans « cPanel CalDAV Calendar », jamais dans « Default ».**

### 3. Construire l'adresse

```
https://<domaine-racine>:2080/calendars/<adresse-courriel-complète>/calendar/
```

Exemple : `https://exemple.org:2080/calendars/agenda@exemple.org/calendar/`

- Utilisez le **domaine racine** du comité, pas un sous-domaine comme `mail.exemple.org` : ce dernier
  peut être dirigé ailleurs (par exemple vers Office 365) et répondre 403.
- Le port **2080** est celui constaté sur nos hébergements cPanel. Pour un autre hébergeur, la page
  cPanel *Configurer un client de messagerie* du compte affiche les adresses CalDAV réelles.
- Le nom d'utilisateur est l'**adresse courriel complète** ; le mot de passe est **celui du compte
  courriel** (le même que pour le webmail).

### 4. Configurer SwitchBoard

Dans *Informations du compte* (ou *Éditer informations utilisateur* pour un administrateur) :

1. **Type de calendrier** : « CalDAV (compte cPanel avec authentification) ».
2. **Adresse du calendrier** : l'adresse construite à l'étape 3.
3. **Nom d'utilisateur du calendrier** : l'adresse courriel complète du compte.
4. **Mot de passe du calendrier** : saisi une seule fois. Il n'est jamais réaffiché ; la page indique
   seulement « configuré ». Laisser le champ vide conserve la valeur actuelle.
5. Enregistrer, puis ouvrir *Groupes de sonneries → Validation du calendrier* pour tester.

Le mot de passe est chiffré en base (préfixe `enc:v1:`) si une clé de chiffrement est configurée
(`$appencryptionkey` / `APP_ENCRYPTION_KEY`, voir le README) ; sinon il est conservé en clair, comme
les autres secrets. L'**adresse** du calendrier, elle, n'est jamais chiffrée.

### 5. Règles pour les événements

SwitchBoard lit l'agenda CalDAV exactement comme un agenda ICS classique :

- **Titre** : le nom du répondant.
- **Description** : son numéro de téléphone (10 chiffres ; un « 1 » initial n'est pas compté).
- Un titre contenant `off->` est **ignoré** : la personne n'est pas inscrite dans voip.ms (elle est,
  par exemple, présente au bureau).
- Un titre contenant `!` déclenche le SMS de rappel « une heure avant » ; un titre contenant `*`
  déclenche celui de « la veille ». Un événement dont l'heure de début et de fin est la même
  (journée entière) ne reçoit pas de rappel « une heure avant ».

Ces marqueurs peuvent se trouver n'importe où dans le titre. La description peut aussi contenir des repères
(`faire1->n`, `nbsec->30`) : voir « Événements de l'agenda » dans le [README](README.md).

### 6. Dépannage

| Symptôme | Cause probable |
|---|---|
| Erreur **401** (à la validation ou par courriel) | Mauvais mot de passe ou nom d'utilisateur ; compte encore « Restricted » ; **Logging In** suspendu. |
| Erreur **403** | Sous-domaine `mail.` utilisé au lieu du domaine racine. |
| « **Aucun événement trouvé** dans la réponse CalDAV » | Agenda vide, ou événements créés dans « Default » au lieu de « cPanel CalDAV Calendar ». |
| Erreur de réponse (autre que 207) | Adresse ou port incorrects ; voir l'étape 3. |

**Comportement en cas d'échec.** Le téléchargement n'est tenté qu'une fois par exécution (pour ne pas
paraître hostile au serveur d'agenda). En cas d'échec, SwitchBoard utilise la dernière copie en cache
sur disque si elle existe, et envoie un courriel d'erreur à l'utilisateur et à l'administrateur.

**Test manuel** (facultatif). Une réponse `HTTP/1.1 207` confirme que l'adresse et les identifiants
sont bons. Mettez le mot de passe entre **apostrophes** : un `$` entre guillemets est interprété par
le shell et tronque le mot de passe.

```
curl -i -X REPORT -u 'agenda@exemple.org:MOT_DE_PASSE' \
  -H 'Depth: 1' -H 'Content-Type: application/xml; charset=utf-8' \
  --data '<?xml version="1.0" encoding="utf-8"?><C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav"><D:prop><C:calendar-data/></D:prop><C:filter><C:comp-filter name="VCALENDAR"><C:comp-filter name="VEVENT"/></C:comp-filter></C:filter></C:calendar-query>' \
  'https://exemple.org:2080/calendars/agenda@exemple.org/calendar/'
```

### 7. Saisir les événements (responsables de comité)

Les applications Calendrier natives (iOS, Android, macOS) gèrent CalDAV sans installation. Le client
de bureau eM Client aussi. Thunderbird n'est pas recommandé pour l'instant : le calendrier Exchange
n'y est pas encore livré.

### 8. Pour l'administrateur : mise à jour de la base de données

La migration `caldavupgrade1.sql` ajoute trois colonnes à la table `userinfo` : `calendartype`
(`secreturl` par défaut, donc aucun agenda existant ne change), `calendarusername` et
`calendarpassword`. Elle est appliquée par la mise à jour automatique.

---

## Procedure (English)

### 1. When to use it

- **Google or Outlook**: keep the "URL secrète (Google / Outlook)" type. Nothing to change.
- **Calendar hosted on the committee's domain cPanel** (RoundCube): use the "CalDAV" type. There is
  no secret URL for these calendars; SwitchBoard authenticates with a username and password.

### 2. Prepare the account in cPanel

1. **Create a mailbox dedicated to the calendar** (e.g. `agenda@example.org`), so no real mailbox
   password is shared.
2. In *Email Accounts*, check the status is "Unrestricted". A new account can start as "Restricted";
   while it is, access fails with a 401 error.
3. Set the account restrictions: **Logging In → Allow** (this switch controls both webmail *and*
   CalDAV access); **Receiving** and **Sending** may stay suspended, making the account sealed off
   from real email without affecting the calendar.
4. Open the account's webmail (RoundCube). It has **two calendars**:
   - "Default": kept in RoundCube's internal database, **never reachable over CalDAV**;
   - **"cPanel CalDAV Calendar"**: the only one SwitchBoard can read.

   **Create every event in "cPanel CalDAV Calendar", never in "Default".**

### 3. Build the address

```
https://<root-domain>:2080/calendars/<full-email-address>/calendar/
```

Example: `https://example.org:2080/calendars/agenda@example.org/calendar/`

- Use the committee's **root domain**, not a subdomain such as `mail.example.org`: it may be routed
  elsewhere (e.g. to Office 365) and answer 403.
- Port **2080** is what we found on our cPanel hosts. For another host, the account's cPanel
  *Configure Mail Client* page shows the real CalDAV addresses.
- The username is the **full email address**; the password is **the mailbox password** (the same
  as for webmail).

### 4. Configure SwitchBoard

In *Informations du compte* (or *Éditer informations utilisateur* for an administrator):

1. **Calendar type**: "CalDAV (compte cPanel avec authentification)".
2. **Calendar address**: the address built in step 3.
3. **Calendar username**: the full email address of the account.
4. **Calendar password**: typed once. It is never shown again; the page only says "configured".
   Leaving the field empty keeps the current value.
5. Save, then open *Groupes de sonneries → Validation du calendrier* to test.

The password is encrypted in the database (`enc:v1:` prefix) when an encryption key is configured
(`$appencryptionkey` / `APP_ENCRYPTION_KEY`, see the README); otherwise it is stored in clear text,
like the other secrets. The calendar **address** itself is never encrypted.

### 5. Event rules

SwitchBoard reads a CalDAV calendar exactly like a regular ICS calendar:

- **Title**: the responder's name.
- **Description**: their phone number (10 digits; a leading "1" is not counted).
- A title containing `off->` is **ignored**: the person is not registered in voip.ms (for example,
  because they are in the office).
- A title containing `!` triggers the "one hour before" SMS reminder; one containing `*` triggers
  the "day before" one. An event whose start and end times are the same (all day) gets no "one hour
  before" reminder.

These markers may appear anywhere in the title. The description can also hold markers (`faire1->n`, `nbsec->30`):
see "Calendar events" in the [README](README.md).

### 6. Troubleshooting

| Symptom | Likely cause |
|---|---|
| **401** error (at validation or by email) | Wrong password or username; account still "Restricted"; **Logging In** suspended. |
| **403** error | `mail.` subdomain used instead of the root domain. |
| "**Aucun événement trouvé** dans la réponse CalDAV" | Empty calendar, or events created in "Default" instead of "cPanel CalDAV Calendar". |
| Response error (other than 207) | Wrong address or port; see step 3. |

**Failure behaviour.** The download is attempted only once per run (so as not to look hostile to the
calendar server). On failure, SwitchBoard falls back to the last cached copy on disk if there is one,
and sends an error email to the user and to the administrator.

**Manual test** (optional). An `HTTP/1.1 207` response confirms the address and credentials are
right. Put the password in **single quotes**: inside double quotes a `$` is expanded by the shell
and truncates the password. See the `curl` command in the French section above.

### 7. Entering events (committee coordinators)

The native Calendar apps (iOS, Android, macOS) handle CalDAV with no installation. The eM Client
desktop app does too. Thunderbird is not recommended for now: Exchange calendars are not delivered
there yet.

### 8. For administrators: database update

The `caldavupgrade1.sql` migration adds three columns to the `userinfo` table: `calendartype`
(`secreturl` by default, so existing calendars are unchanged), `calendarusername` and
`calendarpassword`. The automatic update applies it.
