# Conventions de SwitchBoard

Ces règles s'appliquent au **code neuf et au code qu'on touche**. On ne renomme pas tout l'ancien code d'un coup : on
nettoie ce qu'on modifie, rien de plus. L'organisation du code est dans `ARCHITECTURE.md`, le vocabulaire dans `LEXIQUE.md`, l'état des serveurs dans `PASSATION.md`.

## 1. Principes

- **Le code doit se lire par un humain.** Il est maintenu par de petites équipes de bénévoles, qui le relisent et le
  modifient elles-mêmes : un code clair vaut mieux qu'un code astucieux.
- **Pas de mécanisme plus gros que le besoin.** Pas de framework, pas de couche ajoutée « au cas où ».
- **Pas de rustine sur du vieux code.** On corrige la cause ; si le correctif est trop gros, on en discute d'abord.
- **On nettoie ce qu'on touche** : code mort, appels de debug et lignes commentées retirés dans les fichiers modifiés.
- **Mêmes façons de faire partout.** Si une règle existe ici, on la suit même quand l'ancien code fait autrement.
- **On commente le pourquoi, pas le quoi.** Le code dit déjà ce qu'il fait.

## 2. Noms

Le français est la langue des noms dans le code neuf, sans accents : les noms restent faciles à taper et à chercher.

| Élément | Règle | Exemple |
|---|---|---|
| Classe | PascalCase, français, plus de suffixe `Manager` | `GroupeSonnerie` |
| Fichier de classe | identique à la classe | `GroupeSonnerie.php` |
| Méthode, fonction | camelCase, verbe français | `lireReperes()` |
| Variable, propriété | camelCase | `$listeConservee` |
| Booléen | commence par `est` ou `a` | `$estMembre` |
| Constante | MAJUSCULES | `DUREE_SONNERIE_DEFAUT` |
| Colonne, table | style existant, minuscules collées, aucun renommage | `mainaccount` |
| Script, page | minuscules collées, nom stable | `refreshsbchat.php` |
| Vocabulaire | celui de `LEXIQUE.md` | comité membre |

Restent en anglais : les noms imposés par l'extérieur (p. ex. `setRingGroup`, méthode de l'API voip.ms) et les mots
techniques sans équivalent courant.

*Pourquoi pas de renommage de colonnes :* le gain est faible, et l'ancien code lit encore ces colonnes pendant une mise à
jour.

## 3. Couches

- Trois couches : **entrée** (cron ou page), puis **module**, puis `DatabaseManager`.
- Pas de SQL hors de `DatabaseManager`.
- Pas de `echo`, `die`, `$_GET`, `$_POST` ni HTML dans une classe de module : seules les entrées parlent à l'extérieur.
- Une classe par fichier. Les dépendances passent par le constructeur.
- Le banc d'essai vérifiera ces règles par un `grep`.

*Pourquoi :* une classe qui écrit à l'écran ou lit la requête ne peut plus être testée seule ni réutilisée par un cron.

## 4. Code

- Tabulations pour l'indentation.
- Types déclarés dans le code neuf.
- Sortie rapide (`return`, `continue`) plutôt que des blocs imbriqués.
- Viser moins de 50 lignes par fonction.
- Des exceptions plutôt que `die()`. `catch (\Throwable)` seulement dans les entrées, jamais dans un module.
- Pas de code commenté, pas de bandeau « version du », pas d'appel `debugXxx()` : Git garde l'historique.
- **Chemins** : toujours par les constantes de l'amorce (`SRC_DIRECTORY`, `COMMUN_DIRECTORY`, `PORTAIL_DIRECTORY`, `CONFIG_DIRECTORY`...), jamais
  `dirname(__FILE__)`, `__DIR__` ni chemin relatif (`include 'pages/utilisateur/...'` dépend du dossier courant). Exceptions : la
  ligne qui charge l'amorce, `BASE_DIRECTORY`, et ce qui tourne sans l'amorce (`utils/jobRestore.php`, le début de
  `miseajour.php`). Le banc d'essai le vérifie.
- **Amorce** (`commun/lesconstantes.php`) : elle seule règle le fuseau horaire, charge `vendor/` et déclare l'autoload de
  `src/modules/` ; on ne les refait nulle part ailleurs, et une classe de `src/modules/` ne se charge jamais à la main.
  L'amorce n'écrit rien sur la sortie.

*Pourquoi les exceptions :* un `die()` arrête tout le cron et les comités suivants ne sont jamais traités.

*Pourquoi les chemins en constantes :* une classe qui change de dossier ne casse ni ses voisins ni les crons. Et
`Docker/generate_mail_config.sh` et `mailoauthtoken.php` ne chargent que l'amorce, en lisant sa sortie.

## 5. Sécurité

Le contexte est dans `SECURITY.md` et `CHANGELOG-SECURITE.md`.

- Tout ce qui modifie un état est un formulaire POST : `csrfField()` dans le formulaire (ou `boutonAction()` pour un bouton seul),
  `requireCsrf()` au début du gestionnaire. `requireCsrf()` n'accepte que le POST : aucun jeton ne passe dans une adresse.
- Une nouvelle page s'ajoute dans la table des routes (`src/modules/portail/Routes.php`), avec son droit, et nulle part
  ailleurs : `managementportal.php` contrôle ce droit avant d'appeler la page. Une action garde le sien dans son gestionnaire.
- Aucun GET ne modifie quoi que ce soit : ni lien, ni favori, ni autre page ne déclenche une action. Une page qui agit
  (`Routes::ACTIONS`) répond à un POST par une redirection ; ouverte en GET, elle montre le dernier résultat (une fois) ou renvoie au tableau de bord.
- Requêtes préparées seulement. Toute sortie HTML est échappée.
- Les secrets sont chiffrés (`Chiffrement`) et jamais écrits en dur.
- L'URL d'un appel voip.ms n'est jamais écrite dans un journal (elle contient le mot de passe de l'API).
- Les numéros de téléphone sont masqués dans les journaux.

## 6. Alertes

Décision d'un mainteneur, revue le 2026-11-08 (`archives/REFONTE-2026-11.md`).

- Les variations de l'API voip.ms (délais, erreurs passagères) vont **au journal, jamais en courriel**.
- Le **signal de vie** de la sonnerie passe en échec seulement si **tous** les comités échouent : au 2e passage de suite si
  c'est à cause de voip.ms, tout de suite si c'est une exception. Un comité sans changement n'appelle pas voip.ms et n'est pas
  en échec : une panne de voip.ms ne s'y voit donc qu'à la resynchronisation de 3 h, ou si tous les comités ont un changement.
  On ne le rend pas plus sensible : un seul comité ne doit pas le faire tomber.
- Un problème propre à un comité (voip.ms en échec depuis plus d'une heure, exception, agenda non téléchargé, numéro ou nom
  invalide) envoie **au plus un courriel par 24 heures, par comité et par problème**.
- Quand tous les comités échouent et qu'un signal de vie est configuré, voip.ms et les exceptions n'envoient aucun courriel par
  comité : le signal de vie s'en charge. L'agenda et les numéros ou noms invalides partent quand même : il n'en dit rien.
- **On n'ajoute pas d'alerte par passage de cron.**

*Pourquoi :* le cron passe plusieurs fois par heure et voip.ms varie souvent ; une alerte par passage noierait les vrais
problèmes. Mais une panne qui dure ou un bogue de code ne doivent pas rester invisibles.

## 7. Base de données et migrations

- Une migration doit rester **compatible avec l'ancien code** : un retour arrière remet l'ancien `src/` sans toucher la BD.
- Ajouts seulement. Tout `NOT NULL` reçoit un `DEFAULT`.
- Une instruction SQL par entrée de `dbVersion.json`.
- **Renommer ou retirer une colonne prend deux versions** : la première cesse de l'utiliser, la seconde la retire.
- La structure de départ du banc d'essai n'est jamais modifiée : on ajoute des migrations.

## 8. Ce qui ne bouge jamais

Sans décision explicite des mainteneurs, on ne renomme ni ne déplace :

- les scripts cron (`refreshallringgroups.php` et les autres `src/*.php` appelés par cron) ;
- `managementportal.php`, `token.php`, `mailoauthtoken.php`, `commun/lesconstantes.php` ;
- le dossier `updatejson/` et le nom du zip des dépendances ;
- `config/configempty.php` ;
- le chemin `SwitchBoard/src/` dans le dépôt ;
- le nid `Docker/web` et les permissions dans le conteneur Docker.

Aucun fichier ni dossier nommé `backups` dans `src/` : `recurse_copy()`, qui sert aux sauvegardes de `jobBackup.php`,
l'ignore à n'importe quelle profondeur.

*Pourquoi :* les serveurs, les crons et la mise à jour automatique dépendent de ces chemins précis.

## 9. Tests

- Le banc d'essai (voir `ARCHITECTURE.md`) doit être vert avant toute fusion. Tant qu'il n'existe pas, c'est
  `SwitchBoard/tests/run_tests.sh` qui doit passer.
- Chaque bogue corrigé reçoit un test qui l'aurait attrapé.
- Les tests se lancent dans le banc, pas sur un serveur réel. Aucun test ne touche un vrai service, sauf
  `tests/banc/reel/essai_voipms.py`, lancé seulement sur demande : l'application elle-même ne tourne jamais sur un
  compte voip.ms réel.

## 10. Livraison

- Travail dans une branche partie de `main`. Le banc passe. Fusion dans `main` **sur demande d'un mainteneur** ; une contribution
  externe arrive par une PR (voir `CONTRIBUTING.md`).
- **Le numéro de version est donné à la fusion**, pas au début du travail.
- `codeVersion` (dans `codeVersion.json`) égale toujours le dernier `noVersion` de `dbVersion.json`. Chaque version
  ajoute son entrée `noVersion`, avec `"sql": ""` si rien ne change en BD. `ReadmeMajAuto.md` décrit le mécanisme.
- Ordre de déploiement : le serveur pilote d'abord (installation cPanel, sans comité actif) : on y vérifie l'installation et
  les pages. Puis le serveur principal (installation Docker) : on y observe le premier changement de garde.
- On ne supprime jamais une branche terminée.
- Message de commit en français : ce qui change et les essais faits.
- Une issue se ferme à la main, avec l'accord d'un mainteneur et un commentaire qui dit ce qui est réglé et ce qui reste.

## 11. Traductions et documentation

- Toute chaîne visible est encadrée par `_()`.
- Régénérer les traductions : `cd SwitchBoard/src && ../updatelangscript.sh` (le script utilise des chemins relatifs
  à `src/`). Puis traduire les nouvelles chaînes dans le `.po` en_US, retirer les marques `#, fuzzy` posées par msgmerge
  sur ces chaînes (ce sont des traductions devinées, souvent fausses), et recompiler avec `msgfmt`. Sous Docker, le
  conteneur redémarre pour voir les nouveaux `.mo`.
- **Fin de chantier :** faire remonter dans la documentation ce qui reste vrai, archiver le déroulement (daté, court,
  figé), et **retirer autant qu'on ajoute**. Un guide suivi par Git ne contient ni donnée personnelle ni nom réel.
