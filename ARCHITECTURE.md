# Architecture de SwitchBoard

Ce guide dit **où est quoi** et **pourquoi**. Les règles d'écriture sont dans `CONVENTIONS.md`, le vocabulaire dans `LEXIQUE.md`,
l'état des serveurs et la façon d'installer dans `PASSATION.md`, le déroulement de la refonte de 2026 dans
`archives/REFONTE-2026-11.md`.

## Le dépôt

```
SwitchBoard/src/
  managementportal.php, token.php, mailoauthtoken.php      entrées web
  refreshallringgroups.php, refreshsbchat.php, sendnotificationsmss.php, sendnotificationsbchat.php,
  genstatistics.php, genstatsLAA.php, gendidcostreport.php, mailcanary.php, checkinfra.php        entrées cron
  miseajour.php, updatemanager.php, reencryptsecrets.php   mise à jour et outils en ligne de commande
  commun/       l'amorce (lesconstantes.php), les fonctions partagées (authhelpers, oauthcourriel, surveillance,
                certificatssbchat, statsreportrenderer) et la structure figée de la BD : aucune classe
  modules/      toutes les classes, une par fichier, chargées seules : agenda/ chat/ donnees/ portail/ rapports/ systeme/ telephonie/
  portail/      les pages web : Webpage, AdminPanel, UserAccess, TokenAccess, puis pages/utilisateur/, pages/admin/ et
                pages/jeton/ (chaque page avec ses feuilles de style et ses scripts)
  languages/    traductions gettext (fr_CA, en_US)
  utils/        sauvegarde (jobBackup.php), retour arrière (jobRestore.php), dépôt GitHub, verrou exclusif
  updatejson/   codeVersion.json, dbVersion.json et les migrations SQL
SwitchBoard/tests/      les tests (run_tests.sh) et le banc d'essai (banc/)
Docker/                 l'installation Docker (serveur principal)
```

À la racine d'une installation, hors de `src/` (une mise à jour remplace `src/` et n'y touche pas) : `config/` (réglages, verrous
dans `config/locks/`), `logs/`, `calendarcache/`, `backups/`, `download/`.

**Ne bougent jamais** sans décision des mainteneurs : les scripts cron, `managementportal.php`, `token.php`,
`mailoauthtoken.php`, `commun/lesconstantes.php`, `updatejson/`, le nom du zip des dépendances, `config/configempty.php`, le
chemin `SwitchBoard/src/` (liste complète : `CONVENTIONS.md`, section 8).

## Les couches

Trois couches : une **entrée** (cron ou page), un **module**, puis `DatabaseManager`. Seules les entrées parlent à
l'extérieur (`echo`, `$_GET`, `$_POST`) ; le SQL reste dans `DatabaseManager`.

- **L'amorce** (`commun/lesconstantes.php`) est chargée la première par chaque entrée : chemins (`SRC_DIRECTORY`,
  `COMMUN_DIRECTORY`, `PORTAIL_DIRECTORY`, `CONFIG_DIRECTORY`...), fuseau horaire, `vendor/` et l'autoload. Elle n'écrit rien sur la sortie.
- **L'autoload** (5 lignes, sans namespace) cherche `src/modules/*/NomClasse.php`. Une classe de `modules/` ne se charge
  jamais à la main, et la déplacer d'un module à l'autre ne change rien pour ses appelants.
- **Les modules** : `telephonie/` (client voip.ms, groupe de sonnerie, rappels SMS), `agenda/` (lecture de l'agenda,
  répondants, `TraitementComite` : le passage d'un comité), `chat/` (client SwitchBoard Chat et MAS, rappels, noms Matrix),
  `donnees/` (`DatabaseManager`, et `DumpBD` qui écrit la sauvegarde de la BD : les seules classes qui parlent SQL), `rapports/` (statistiques, coûts des DID, comparaison des coûts
  entre comptes, taux de change), `systeme/` (mise à jour, journaux, alertes, bilan d'un passage, chiffrement, fichiers, courriel,
  signal de vie), `portail/` (la table des routes, la langue et les informations de l'utilisateur).
- **Les anciens noms** (v134) : `CryptoManager` → `Chiffrement`, `FileManager` → `Fichiers`, `LogManager` → `JournalDeTache`,
  `EmailManager` → `Courriel`, `DeadManSwitchManager` → `SignalDeVie`, `LanguageManager` → `Langue`, `UserInformations` →
  `InformationsUtilisateur`, `SB_Chat` → `ClientSwitchBoardChat`, `CalendarManager` → `TraitementComite`, `ExchangeRateManager` → `TauxDeChange`,
  `DidCostManager` → `CoutsDid`, `DidCostReportManager` → `RapportCoutsDid`, `StatsReportManager` → `RapportStatistiques`,
  `CostReportJobManager` → `TacheComparaisonCouts`, `MultiAccountCostReportManager` → `ComparaisonCouts`,
  `MultiAccountCostReportExporter` → `ExportComparaisonCouts`. `DatabaseManager` garde son nom : c'est la couche des données.

## Le portail

- **La table des routes** (`modules/portail/Routes.php`) donne, pour chaque valeur de `?page=`, la méthode qui l'affiche et son
  droit : `ADMIN` ou `CONNECTE`. `managementportal.php` contrôle ce droit à un seul endroit, avant l'appel. Une nouvelle page
  s'ajoute là, et nulle part ailleurs.
- **Une page = un traitement + une vue.** `xxx.php` lit la requête, vérifie, appelle les modules, enregistre et prépare chaque
  valeur à montrer ; `xxx.vue.php` ne fait qu'afficher ces valeurs (HTML, `echo`, `if`, `foreach`). Le traitement finit par
  `include PORTAIL_DIRECTORY.'pages/.../xxx.vue.php'` ; la vue partage ses variables.
- **Enregistrer = rediriger.** Un formulaire qui enregistre répond par un 303 vers la même page (`commencerEnregistrement()`,
  `terminerEnregistrement()` dans `authhelpers.php`) : rafraîchir la page ne renvoie rien, et ce que l'enregistrement a dit s'affiche une seule fois.
- **Rien qui agit ne s'accepte par une adresse.** Un bouton qui agit est un petit formulaire POST avec le jeton CSRF (`boutonAction()` ;
  `csrfField()` dans un formulaire ; `requireCsrf()` n'accepte que le POST). Les pages qui agissent (`Routes::ACTIONS` : forcer la sonnerie, rappels,
  SwitchBoard Chat, jeton API) répondent à un POST par une redirection ; ouvertes par un GET, elles montrent ce que la dernière action a dit, une seule
  fois (`resultatDeLAction()`), ou renvoient au tableau de bord. Un formulaire qui enregistre répond de même par une redirection ; le
  **mot de passe d'un utilisateur Matrix n'est jamais gardé en session** : le navigateur le garde le temps de la redirection
  (`sessionStorage`, jamais envoyé au serveur) et le remet dans l'invitation Element X.

## La base de données

Neuf tables. Une installation neuve part de la **structure figée** `commun/structure-figee-<version>.sql` (jamais modifiée),
puis reçoit les migrations de `dbVersion.json` au-dessus de cette version. Une migration est compatible avec l'ancien code (un
retour arrière ne touche pas la BD), n'ajoute que des colonnes avec un `DEFAULT`, et tient en une instruction par entrée.
`codeVersion` égale toujours le dernier `noVersion`. Les structures réelles des deux serveurs sont dans `tests/banc/structure/`.

## Les passages automatiques

Le cron de sonnerie (`refreshallringgroups.php`) passe toutes les 10 à 15 minutes : pour chaque comité, il lit l'agenda,
compare à la liste conservée et ne parle à voip.ms que s'il y a un changement. Il prend le verrou `config/locks/refreshallringgroups.lock`, puis
un verrou par comité (`sonnerie-comite-<id>.lock`, `flock`, libéré par le noyau même si le processus meurt) : la page
« Forcer » et le cron ne mettent jamais le même comité à jour en même temps. Un comité qui échoue n'arrête pas les autres. Le
signal de vie ne tombe que si tous échouent ; les alertes par courriel sont limitées à une par 24 heures, par comité et par
problème (`CONVENTIONS.md`, section 6). Les rappels, les rapports mensuels et les canaris suivent le même principe.

## La mise à jour et le retour arrière

`miseajour.php` (SSH) ou le bouton de la console installent la dernière version : la dernière « release » du dépôt public, ou à
défaut la branche `main` (`BRANCHE_POUR_MAJ`), demandée sans jeton (un jeton GitHub est facultatif ; refusé, il est ignoré et la
console le dit). Tout est téléchargé et vérifié dans
`download/` avant de toucher à l'installation, le code et la BD sont sauvegardés dans `backups/<date>/`, `src/` est remplacé
par un renommage, puis les migrations passent une à une. Une panne avant le remplacement laisse l'ancien code en marche.
Après le remplacement, l'ancien code (en mémoire) ne charge plus rien depuis le disque. Retour arrière :
`php src/utils/jobRestore.php [dossier] [--avec-bd]` ; il sait restaurer une sauvegarde de l'ancienne disposition des dossiers
(avant la v130). Détail et procédure à la main : `ReadmeMajAuto.md`.

## Le banc d'essai

`SwitchBoard/tests/banc/lancer.sh` (environ 5 minutes, Docker) construit l'image du serveur principal, y met l'application
telle quelle et de **faux services** (voip.ms, GitHub, agendas, Banque du Canada, signal de vie, SwitchBoard Chat, MAS, Microsoft, Google, courriel) sur
un réseau sans sortie : aucun test ne peut toucher un vrai service ni envoyer un vrai message. Un scénario par domaine (sonnerie,
rappels, rapports, portail, mise à jour, journaux, sauvegardes, alertes, installation neuve...). Il garde aussi des **références** suivies
par Git, qui prouvent qu'un changement de code ne change rien d'autre :
- `reference/voipms-*.txt` : les requêtes que l'application fait à voip.ms, scénario par scénario ;
- `reference/*.eml` : les courriels de rapport ;
- `reference/page-*.html` : le HTML de 25 pages (scénario 48) ;
- `tests/banc/structure/` : la structure de la BD de chaque serveur.

Un écart voulu se régénère avec `lancer.sh --nouvelles-references` et se relit par `git diff`. `--mariadb 10.11` essaie la
version du serveur pilote. **Le banc est vert avant toute fusion.** Il ressemble au serveur principal (Docker), pas à une
installation cPanel : le serveur pilote couvre ce trou. `tests/banc/reel/essai_voipms.py` est le seul essai avec le vrai voip.ms, lancé seulement sur demande.

## Les deux serveurs

- **Serveur pilote** : installation cPanel, MariaDB 10.11, sans comité actif. On y installe d'abord et on y vérifie la version et les pages.
- **Serveur principal** : installation Docker, MariaDB 12, les comités réels. On y observe le premier changement de garde.

## Ce qui reste à faire un jour

- Les classes venues de `commun/` écrivent encore à l'écran (`echo`) : la sortie des crons et celle des pages « Forcer » et des rappels en
  dépendent, et leurs méthodes gardent leurs noms anglais d'avant (`encrypt()`, `createFile()`...). Contre `CONVENTIONS.md`, on les
  sépare et on les renomme quand on les touche. Déjà séparés : la validation de l'agenda (`validerAgenda()` retourne les messages, la page les
  met en HTML) et l'affichage d'un journal (`texteDuJournal()` retourne le texte, la page le met en forme).
- `Langue` charge encore `portail/useraccess.php` à la main (UserAccess n'est pas dans `modules/`).
- Deux écrans n'ont pas de redirection après envoi, par choix : le rapport multi-comptes (le calcul se fait par petits appels JSON, et le CSV ou le
  fichier Excel est un téléchargement) et la mise à jour par la console (la progression s'écrit en continu dans la réponse).
