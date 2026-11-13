# Surveillance dead-man's-switch / Dead-man's-switch monitoring

Ce guide couvre la surveillance externe de SwitchBoard : plutôt que d'attendre que SwitchBoard
signale lui-même un problème (impossible s'il est planté ou si l'hôte au complet est en panne),
SwitchBoard envoie régulièrement un signal de vie ("ping") à un service externe gratuit, qui vous
alerte si ce signal cesse d'arriver. C'est le principe du "dead-man's-switch" : le silence
lui-même est l'alerte.

This guide covers external monitoring for SwitchBoard: instead of waiting for SwitchBoard to
report its own problem (impossible if it's hung or the whole host is down), SwitchBoard
regularly sends a heartbeat ping to a free external service, which alerts you if that ping stops
arriving. This is the "dead-man's-switch" principle: silence itself is the alert.

---

## Procédure (Français)

### 1. Prérequis

Rien à installer sur votre serveur — juste un compte gratuit chez un service de surveillance
externe. Ce guide utilise [healthchecks.io](https://healthchecks.io) comme exemple (gratuit
jusqu'à 20 checks, largement suffisant ici), mais n'importe quel service basé sur des URLs de
ping avec suffixe `/fail` fonctionne de la même façon.

### 2. Créer les 4 checks

Créez un compte gratuit sur [healthchecks.io](https://healthchecks.io), puis créez 4 checks avec
les réglages suivants (Menu → Add Check, puis Period/Grace dans les réglages du check) :

| Nom suggéré | Période (Period) | Délai de grâce (Grace) | Ce qu'il surveille |
|---|---|---|---|
| SwitchBoard - Groupes de sonnerie | 10 minutes | 10 minutes | refreshallringgroups.php - **le plus critique**, doit tourner en tout temps |
| SwitchBoard - SBChat | 10 minutes | 10 minutes | refreshsbchat.php - aussi critique |
| SwitchBoard - Courriel | 30 minutes | 15 minutes | mailcanary.php - test réel d'envoi via msmtp |
| SwitchBoard - Infrastructure | 30 minutes | 15 minutes | checkinfra.php - base de données + espace disque |

Chaque check a sa propre URL de ping (ex.: `https://hc-ping.com/xxxxxxxx-xxxx-xxxx-xxxx`) —
notez-les, vous en aurez besoin à l'étape 4.

**Quand le check « Groupes de sonnerie » passe en échec :**

- tous les comités lèvent une exception : tout de suite ;
- tous les comités sont en échec, avec au moins une erreur de voip.ms : au 2e passage de suite. Le premier passage
  reste un succès et le corps du ping le dit, pour ne pas alerter sur un incident de 10 minutes chez voip.ms ;
- chevauchement de deux passages, plantage du script, ou plus aucun ping : comme les autres checks.

Sinon il reste bon, même si certains comités échouent : le corps du ping donne leur nombre, et l'admin reçoit un
courriel par 24 heures pour un comité en échec depuis 6 passages de suite. Personne de garde et aucun changement ne
sont pas des échecs. Tant que tous les comités échouent, aucun courriel par comité ne part si ce check est configuré.

**Limite :** un comité sans changement ne fait aucun appel à voip.ms et compte donc comme réussi. Une panne de voip.ms
ne met ce check en échec que si tous les comités appellent voip.ms : à la resynchronisation quotidienne, après 3 h, ou si
tous ont un changement à faire. Le courriel d'un comité en échec depuis 6 passages couvre le cas d'un comité qui a un
changement à faire pendant que les autres n'en ont pas.

**Le check « Courriel »** fait un second essai 30 secondes après un échec, et il n'échoue que si les deux ratent. Une
réussite au second essai reste un succès, et le corps du ping le dit.

### 3. Configurer votre canal de notification

Tout se passe chez healthchecks.io, rien à faire dans SwitchBoard pour cette partie. Sous
l'onglet **Integrations** de votre projet, ajoutez le canal de votre choix : courriel, SMS,
Telegram, [ntfy](https://ntfy.sh) (notification push gratuite, sans compte à créer), Pushover,
Discord, Slack, appel téléphonique, et plusieurs autres. Si plusieurs administrateurs doivent
être alertés, la plupart des canaux (Telegram, ntfy, Discord/Slack) supportent nativement un
groupe/canal partagé où tout le monde reçoit la même alerte.

### 4. Coller les URLs dans SwitchBoard

Connectez-vous en tant qu'administrateur, menu → *Console d'administration → Surveillance
(dead-man's-switch)*. Pour chacun des 4 checks, collez l'URL de ping de succès notée à l'étape 2
(l'URL de ping d'échec est optionnelle - par défaut, SwitchBoard utilise la même URL avec `/fail`
à la fin, la convention healthchecks.io). Cliquez *Enregistrer*.

### 5. Envoyer un ping de test

Pour chaque ligne, cliquez *Envoyer un ping de test maintenant*. Ce bouton envoie volontairement
un ping d'échec suivi immédiatement d'un ping de succès (un simple ping de succès ne déclenche
généralement AUCUNE notification chez healthchecks.io si le check était déjà en bonne santé — il
n'y a alors aucun changement d'état à signaler) : vous devriez donc recevoir une vraie
notification sur votre canal choisi dans les secondes qui suivent, et le check redevient normal
aussitôt après (pas besoin de le remettre à zéro vous-même). **Faites cette étape avant de faire
confiance à l'horaire** : puisque toute la surveillance repose sur le silence = alerte, une URL
mal collée échouerait silencieusement sans ce test.

### 6. Confirmer qu'une vraie alerte fonctionne

La seule étape que SwitchBoard ne peut pas simuler lui-même :

1. **Panne d'un seul sous-système** : par exemple, renommez temporairement `/etc/msmtprc`
   (`docker exec sb_php-fpm mv /etc/msmtprc /etc/msmtprc.bak`), attendez le prochain passage de
   `mailcanary.php` (aux 5 ou 35 minutes), et confirmez que SEUL le check "Courriel" devient
   rouge et qu'une notification arrive bien par le canal choisi. Remettez ensuite
   `/etc/msmtprc.bak` en place.
2. **Panne complète** : `docker compose down` (ou `docker stop sb_php-fpm`), attendez que les
   délais de grâce soient dépassés, et confirmez que les 4 checks passent au rouge par pur
   silence, avec une vraie notification qui arrive sur votre téléphone/canal choisi. C'est la
   preuve que la surveillance fonctionne même quand SwitchBoard est complètement hors service.

### Dépannage

| Symptôme | Cause | Solution |
|---|---|---|
| Un check reste gris ("no data") sur healthchecks.io même après avoir cliqué "Envoyer un ping de test" | L'URL collée dans SwitchBoard contient une faute de frappe ou un espace, ou le conteneur ne peut pas atteindre Internet en sortie. | Recopiez l'URL directement depuis healthchecks.io (évitez de la retaper) ; vérifiez la connectivité sortante du conteneur (`docker exec sb_php-fpm curl -I https://healthchecks.io`). |
| Le check "Courriel" échoue systématiquement sur une installation cPanel/hébergement mutualisé, alors que l'envoi fonctionne bien | Corrigé : `mailcanary.php` détecte maintenant automatiquement si `msmtp` est présent. S'il ne l'est pas (courriel envoyé via le sendmail/exim natif du serveur, ex.: cPanel), le test se fait directement avec `mail()` de PHP plutôt que d'exiger `msmtp` (qui n'a alors jamais existé sur ce type d'installation). | Mettez à jour vers la dernière version - aucune configuration à changer, la détection est automatique. |
| "Chevauchement détecté" apparaît régulièrement pour le check Groupes de sonnerie ou SBChat | Le rafraîchissement prend systématiquement plus de 10 minutes (beaucoup de comités, API voip.ms lente/limitée). | C'est une vraie alerte, pas un faux positif - voir combien de comités sont configurés et si l'API voip.ms est limitée (codes 429) durant cette période ; envisagez d'espacer certains comités sur des horaires différents si le volume grandit. |
| Le compte gratuit healthchecks.io a atteint sa limite de 20 checks | Vous avez d'autres projets utilisant le même compte. | Créez un compte/projet séparé pour SwitchBoard, ou passez à un forfait payant si le volume le justifie. |

---

## Procedure (English)

### 1. Prerequisites

Nothing to install on your server — just a free account with an external monitoring service.
This guide uses [healthchecks.io](https://healthchecks.io) as the example (free up to 20 checks,
plenty for this), but any ping-URL-based service with a `/fail` suffix convention works the same
way.

### 2. Create the 4 checks

Create a free account on [healthchecks.io](https://healthchecks.io), then create 4 checks with
these settings (Menu → Add Check, then Period/Grace in the check's settings):

| Suggested name | Period | Grace | What it monitors |
|---|---|---|---|
| SwitchBoard - Ring groups | 10 minutes | 10 minutes | refreshallringgroups.php - **the most critical one**, must run at all times |
| SwitchBoard - SBChat | 10 minutes | 10 minutes | refreshsbchat.php - also critical |
| SwitchBoard - Mail | 30 minutes | 15 minutes | mailcanary.php - a real send test via msmtp |
| SwitchBoard - Infra | 30 minutes | 15 minutes | checkinfra.php - database + disk space |

Each check has its own ping URL (e.g. `https://hc-ping.com/xxxxxxxx-xxxx-xxxx-xxxx`) — note them
down, you'll need them in step 4.

**When the "Ring groups" check fails:**

- every committee raises an exception: right away;
- every committee fails, with at least one voip.ms error: on the 2nd run in a row. The first run stays a success and
  the ping body says so, to avoid alerting on a 10-minute voip.ms incident;
- two runs overlap, the script crashes, or the pings stop: same as the other checks.

Otherwise it stays healthy even if some committees fail: the ping body gives their number, and the admin gets one email
per 24 hours for a committee failing for 6 runs in a row. Nobody on call and no change are not failures. While every
committee fails, no per-committee email goes out if this check is configured.

**Limitation:** a committee with no change makes no call to voip.ms, so it counts as successful. A voip.ms outage only
fails this check if every committee calls voip.ms: at the daily resynchronization, after 3 a.m., or if all of them have a
change to make. The email for a committee failing for 6 runs in a row covers a committee that has a change to make while
the others do not.

**The "Mail" check** tries again 30 seconds after a failure and only fails if both attempts fail. A success on the
second attempt stays a success, and the ping body says so.

### 3. Configure your notification channel

This all happens on healthchecks.io's side, nothing to do in SwitchBoard for this part. Under
your project's **Integrations** tab, add whichever channel you prefer: email, SMS, Telegram,
[ntfy](https://ntfy.sh) (free push notification, no account needed), Pushover, Discord, Slack,
phone call, and several others. If multiple admins need alerting, most channels (Telegram, ntfy,
Discord/Slack) natively support a shared group/topic where everyone gets the same alert.

### 4. Paste the URLs into SwitchBoard

Log in as an administrator, menu → *Console d'administration → Surveillance
(dead-man's-switch)*. For each of the 4 checks, paste the success ping URL noted in step 2 (the
failure ping URL is optional — by default SwitchBoard uses the same URL with `/fail` appended,
the healthchecks.io convention). Click *Enregistrer* (Save).

### 5. Send a test ping

For each row, click *Envoyer un ping de test maintenant* (Send a test ping now). This button
deliberately sends a failure ping immediately followed by a success ping (a plain success ping
usually triggers NO notification on healthchecks.io if the check was already healthy — there's
no state change to report): you should get a real notification on your chosen channel within
seconds, and the check goes back to normal right after (no need to reset it yourself). **Do this
before trusting the schedule** — since the whole monitoring approach relies on silence = alert, a
mistyped URL would fail silently without this test.

### 6. Confirm a real alert actually fires

The one step SwitchBoard cannot simulate on its own:

1. **Single-subsystem failure**: e.g. temporarily rename `/etc/msmtprc`
   (`docker exec sb_php-fpm mv /etc/msmtprc /etc/msmtprc.bak`), wait for the next
   `mailcanary.php` run (at :05 or :35), and confirm ONLY the "Mail" check goes red and a
   notification actually arrives through your chosen channel. Restore `/etc/msmtprc.bak`
   afterward.
2. **Full outage**: `docker compose down` (or `docker stop sb_php-fpm`), wait for the grace
   periods to elapse, and confirm all 4 checks go red via pure silence, with a real notification
   arriving on your phone/chosen channel. This proves monitoring works even when SwitchBoard is
   completely down.

### Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| A check stays grey ("no data") on healthchecks.io even after clicking "Send a test ping now" | The URL pasted into SwitchBoard has a typo/stray whitespace, or the container can't reach the internet outbound. | Copy the URL directly from healthchecks.io (avoid retyping it); check the container's outbound connectivity (`docker exec sb_php-fpm curl -I https://healthchecks.io`). |
| The "Mail" check fails consistently on a cPanel/shared-hosting install, even though sending actually works | Fixed: `mailcanary.php` now auto-detects whether `msmtp` is present. If it isn't (mail sent via the server's native sendmail/exim, e.g. cPanel), the test uses PHP's `mail()` directly instead of requiring `msmtp` (which never existed on that kind of install in the first place). | Update to the latest version - no configuration change needed, detection is automatic. |
| "Overlap detected" shows up regularly for the Ring groups or SBChat check | The refresh consistently takes longer than 10 minutes (many comités, slow/rate-limited voip.ms API). | This is a real alert, not a false positive — check how many comités are configured and whether the voip.ms API is being rate-limited (429s) during that window; consider spreading some comités onto different schedules if volume keeps growing. |
| The free healthchecks.io account hit its 20-check limit | Other projects are using the same account. | Create a separate account/project for SwitchBoard, or upgrade to a paid plan if volume justifies it. |
