Mise à Jour Automatique

Initial : 2025-10-02

modifié : 2025-12-03

# <span id="anchor"></span>Introduction :

La mise à jour automatique est faite pour être exécutée par le technicien responsable de l’installation et de l’opération du système Switchboard qu’il a lui-même installé sur un serveur (web, vps, hébergeur...etc…) dédié à divers usages technologiques requis et utilisés par une instance de service des AA tels une région, un district...etc...

Elle facilite les mises à jour de l’application dans le sens qu’elle détecte automatiquement si une mise à jour est disponible sur github et offre à l’utilisateur possédant les privilèges d’administrateur dans une installation de Switchboard, d’effectuer cette mise à jour en cliquant sur un bouton!

# <span id="anchor-1"></span>Principe :

Lorsque l’application est utilisée par un utilisateur admin et que celui-ci va dans la « console d’administration », un message indiquant la disponibilité d’une mise à jour s’affiche et un bouton apparaît permettant de lancer la mise à jour en cliquant dessus.

# <span id="anchor-2"></span>Détails fonctionnels:

Voici le travail effectué par l’outil de mise à jour automatique :

<span id="anchor-3"></span>Accède au Github du projet et obtient la version Github du code (\<github\>/SwitchBoarders/SwitchBoard/ de la branche «XYZ» où « XYZ » est la branche qui servira de point de départ pour la mise à jour automatique. Voir note 1.

<span id="anchor-4"></span>Obtient la version serveur du code (\<dossier d'installation SB\>/src/updatejson/codeVersion.json).

<span id="anchor-5"></span>Compare la version du code obtenue du Github avec la version serveur du code :  
Si la version-code-github \> la version-code-serveur

<span id="anchor-6"></span>Affiche le message "mise à jour disponible" et rend disponible un bouton permettant de lancer la mise à jour

Lorsqu'une réponse positive a été reçue de l'utilisateur, la classe `MiseAJour` (`src/modules/systeme/MiseAJour.php`) fait le travail.

**La règle : une panne à n'importe quel point avant le point 7, même un arrêt brutal du processus, laisse l'ancien code qui tourne, et on peut relancer.** Tout ce qui peut échouer est donc fait et vérifié dans `download/` avant de toucher à l'installation.

1. **Vérifier.** PHP 8.3 minimum, extensions, droits d'écriture sur la racine, `src/`, `download/`, `backups/` et `config/locks/`, espace disque (`disk_free_space()` ignore les quotas de cPanel : les échecs d'écriture sont attrapés plus loin). `src/`, `download/` et `backups/` doivent être sur le même disque. Les restes d'un essai précédent sont vidés de `download/`.
2. **Télécharger, extraire dans `download/` et tout vérifier.** Le code neuf, `codeVersion.json`, `dbVersion.json` (`noVersion` en ordre croissant, chaque entrée `file` présente) et le zip de dépendances. Si ce zip a changé ou si `vendor/autoload.php` est absent, il est extrait ici aussi, pour qu'une archive corrompue arrête tout avant la migration. Les tailles des fichiers extraits sont comparées à celles de l'archive.
3. **Sauvegarder la BD** dans `backups/<date>/BD.sql`, s'il y a du SQL à exécuter (voir « Sauvegardes »). Sauvegarde impossible : arrêt, rien n'a changé.
4. **Prendre le verrou du cron de sonnerie**, jusqu'à la fin du point 7. Occupé : arrêt, rien n'a changé, on réessaie dans quelques minutes. Un cron qui tombe pendant la mise à jour saute son passage, sans signal d'échec.
5. **Migrer une entrée à la fois**, avec le SQL de l'archive. Le compteur `db version` avance après chaque entrée réussie. À l'échec, le message dit quelle version est atteinte et laquelle a échoué.
6. **Remplacer `vendor/`** si le point 2 l'a extrait, par renommage. L'ancien va dans `backups/<date>/vendor`, et revient si ça échoue.
7. **Remplacer `src/`** par renommage. L'ancien va dans `backups/<date>/src`, et revient si le nouveau ne peut pas être mis en place.
8. **Faire le ménage des sauvegardes**, seulement après une réussite : voir « Sauvegardes » plus bas. Un échec de ce ménage ne fait qu'un message.
9. **Afficher un message simple et s'arrêter**, sans rien inclure du nouveau `src/` : l'ancien code est encore en mémoire. La console offre un lien de retour.

Une panne après le point 5 laisse une BD en avance sur le code, ce qui est sans danger : une migration reste compatible avec l'ancien code (`CONVENTIONS.md`, section 7). Il suffit de relancer.

**Arrêt brutal :** certains serveurs web tuent une requête PHP quand le navigateur se ferme, malgré `ignore_user_abort()`. La relance reste possible, avec deux limites. Une instruction déjà reçue par le serveur de BD va au bout même si PHP est tué : le compteur reste alors en retard sur le schéma, et la relance s'arrête sur une erreur du genre « Table already exists » ; il faut vérifier le schéma, corriger la ligne `db version` de la table `parameters` et relancer. Et si l'arrêt tombe entre les deux renommages de `src/`, une fraction de seconde, `src/` manque : voir plus bas la commande de secours. Quand c'est possible, préférez donc la mise à jour par SSH.

**Données :** les journaux (`logs/`) et le cache des agendas (`calendarcache/`) sont à la racine de l'installation, hors de `src/` : une mise à jour n'y touche pas. Le code crée ces dossiers et leur `.htaccess` à sa première écriture. Un journal impossible à ouvrir n'arrête jamais un cron : il n'est pas écrit, et la cause va dans le journal d'erreurs de PHP. Au-delà de 5 Mo, un journal passe en `…log.old.txt` à son ouverture, en écrasant l'ancien.

# Sauvegardes:

Une mise à jour laisse un dossier `backups/<date>/` qui contient `src/`, plus `BD.sql` s'il y avait du SQL à exécuter, plus `vendor/` si les dépendances ont changé. `src/utils/jobBackup.php` fait une sauvegarde complète : toute la racine et un `BD.sql` neuf, sans `logs/`, `calendarcache/`, `download/` ni un `BD.sql` oublié à la racine.

Le `BD.sql` est écrit par `DumpBD` (`src/modules/donnees/`, depuis la v138 ; avant, par la bibliothèque `ifsnop/mysqldump-php`) : les tables, leur création et leurs lignes, lues dans une seule transaction. Il se relit avec `jobRestore.php --avec-bd`, ou à la main avec le client de la BD (`mariadb <base> < BD.sql`). Une vue, une séquence ou un déclencheur arrête la sauvegarde, et donc une mise à jour qui a du SQL à exécuter : SwitchBoard n'en crée pas, et la copie les perdrait. Au retour arrière, `restoreDatabase()` envoie le fichier entier en une seule requête : il doit rester sous `max_allowed_packet` du serveur de BD (16 Mo par défaut avec MariaDB), sinon il se relit avec le client de la BD.

Après une mise à jour réussie, **les 5 dossiers les plus récents qui contiennent un `src/` sont gardés**. Les plus vieux partent, comme les dossiers sans `src/` : ce sont des essais ratés. Une mise à jour ratée n'efface rien. Seuls les dossiers dont le nom commence par une date sont regardés : un dossier nommé autrement, posé à la main, reste.

# Retour arrière:

`php src/utils/jobRestore.php`, à partir de la racine d'installation, remet le code de la sauvegarde la plus récente qui contient un `src/`, ou de celle qu'on lui nomme :

    php src/utils/jobRestore.php 2026-11-06a

Il remet `src/`, et `vendor/` s'il est dans la sauvegarde, sans toucher à la BD : ça marche parce qu'une migration reste compatible avec l'ancien code. Une sauvegarde sans `vendor/` dont le zip de dépendances (`src/precompileddependencies*.zip`) n'est pas celui du code en place reçoit le `vendor/` de son zip : le code d'avant la v138 a besoin d'`ifsnop/mysqldump-php`, retiré en v138. `config/` n'est jamais restauré. Le code remplacé est gardé dans un nouveau dossier de `backups/`, donc sans argument, un second retour arrière revient à la version qu'on vient de quitter. La sauvegarde choisie est copiée, pas déplacée : elle reste entière.

Le script tourne en ligne de commande seulement, et il refuse les mêmes cas que `src/miseajour.php` (root, autre compte que celui de `src/`, PHP trop ancien). Il prend les mêmes verrous qu'une mise à jour : si le cron de sonnerie tourne, il s'arrête sans rien changer, et on réessaie dans quelques minutes.

**La BD seulement sur demande**, avec `--avec-bd`, s'il y a un `BD.sql` complet :

    php src/utils/jobRestore.php 2026-11-06a --avec-bd

La BD redevient celle de la sauvegarde, et les tables créées depuis disparaissent : ce qui s'y est passé depuis est perdu. La BD d'avant ce retour arrière est gardée, en `BD.sql`, dans le nouveau dossier.

Le `BD.sql` doit avoir la date du dossier, à un jour près : sa ligne `-- Date:` est lue et affichée, et sans cette date le script refuse, avant d'avoir touché à quoi que ce soit. Les sauvegardes faites par l'ancienne mise à jour peuvent contenir un `BD.sql` bien plus vieux qu'elles. Pour le restaurer quand même, ajoutez `--bd-ancienne`.

**Commande de secours, quand `src/` est cassé ou absent :** le même script se lance depuis une sauvegarde, avec les mêmes arguments.

    php backups/2026-11-06a/src/utils/jobRestore.php 2026-11-06a

Une sauvegarde faite avant cette version contient l'ancien `jobRestore.php`, qui recopie tout le dossier à la racine, `BD.sql` compris : ne le lancez pas. Dans ce cas, écartez le `src/` cassé s'il existe, et remettez le dossier à la main :

    mv src src.casse
    mv backups/2026-11-06a/src src

Le `jobRestore.php` d'une sauvegarde d'avant la v138 ne refait pas `vendor/`, la remise à la main non plus. Si le code remis date d'avant la v138 et que la sauvegarde n'avait pas de `vendor/`, remettez ensuite les dépendances de son zip, à la racine :

    mv vendor vendor.remplace
    unzip -q src/precompileddependencies*.zip

Le serveur web peut garder l'ancien code en mémoire quelques secondes : voir la remarque sur `opcache_reset()` ci-dessous.

# Mise à jour par SSH:

`php src/miseajour.php` fait le même travail que le bouton de la console, à partir de la racine d'installation. Ce script refuse de tourner par le web, même avec la clé de cron, et sous root : sous Docker, `docker exec` entre en root par défaut, il faut donc passer par `docker exec -u www-data <conteneur php> php <chemin>/src/miseajour.php`.

Sur cPanel, le `php` par défaut peut être trop ancien : appelez le bon, p. ex. `/opt/cpanel/ea-php84/root/usr/bin/php src/miseajour.php`.

Par SSH, `opcache_reset()` n'atteint pas le cache du serveur web : celui-ci revoit les fichiers seul après quelques secondes, sauf si `opcache.validate_timestamps` est à 0. Dans ce cas, il faut redémarrer PHP après la mise à jour.

# <span id="anchor-12"></span>En tant que Développeur:

Ce que vous devez faire en tant que développeur :

Faire vos modifications et les tester !

Si des changements ont été nécessaires à la BD,

Extraire un script SQL de phpmyadmin via Exporter lorsqu’il s’agit d’une nouvelle table à créer (un script par table)

et/ou Créer à la main un script SQL qui fera les modifications aux tables existantes

Inscrire ce ou ces scripts dans le fichier dbVersion (une fois testés et fiables) avec pour chacun un noVersion consécutif aux derniers déjà présents (Voir : Le fichier dbVersion, section suivante)

Inscrire le noVersion final dans le fichier \<dossier d’installation\>/src/updatejson/codeVersion.json et aussi dbVersion.

# <span id="anchor-13"></span>Le fichier dbVersion:

Le fichier dbVersion.json contient tous les nos de version de BD avec pour chacun, un script SQL ou un nom de fichier contenant un script SQL permettant d’upgrader la BD à cette version.  
Ce fichier doit être maintenu par un technicien à chaque nouvelle version de SB.  
Il devra ajouter un nouveau noVersion et inscrire le script SQL (ou le nom de fichier) correspondant.  
Au besoin, il pourra ajouter plusieurs lignes (avec un nouveau noVersion à chaque fois), lorsque les changements à la BD sont majeurs.  
De plus, il doit être en ordre ascendant de noVersion sinon une erreur sera levée par l’application !

Le dernier noVersion inscrit dans ce fichier constitue le no de version final de cette livraison et ce même noVersion doit être inscrit au fichier codeVersion.json.
Si une livraison n'implique que des changements au code, alors il faudra inscrire quand-même le noVersion du code dans ce fichier en laissant le champ "SQL" vide (c-à-d: "").

## <span id="anchor-14"></span>Description :

{

"versions":

\[

{"noVersion":"1", "sql":"ALTER TABLE userservice ADD COLUMN sendstats TINYINT(1) DEFAULT 1;"},

{"noVersion":"2", "sql":"CREATE TABLE newtable (colonne1 TINYINT(1) NOT NULL DEFAULT 1);"},

{"noVersion":"3", "sql":"ALTER TABLE newtable ADD COLUMN colonne2 TINYINT(1) NOT NULL DEFAULT 1;"},

{"noVersion":"4", "file":"scriptSqlQuelconque.sql"},

{"noVersion":"5", "php":"scriptPhpQuelconque.php"},

\]

}

La description parle d’elle-même!  
Remarque : l’exécution d’un script php tel que proposé en "noVersion":"5" n’est pas encore possible!

# <span id="anchor-15"></span>Information sur les nos de version:

Il est possible que pour une grosse version de SB à livrer, plusieurs modifications à la BD nécessitent d’utiliser plusieurs nos de version. Dans ce cas plusieurs lignes doivent être ajoutées au fichier dbVersion et la version finale de la livraison (et aussi du code php) sera 5 dans l’exemple précédent.

Quelques exemples :

  
Exemple 1 :

La version en PROD est 3, les noVersion 1 à 3 sont donc déjà au fichier : la version actuellement en développement ajoutera les noVersion 4 et 5. On sera rendus en version 5 !  
Une livraison subséquente devra continuer la numérotation à partir de 6 éventuellement.

Exemple 2 :

La version en PROD est 5, notre livraison est toute petite et ne nécessite que peu de changement à la BD : alors une seule ligne pourrait être nécessaire à rajouter au fichier dbVersion en continuant avec la version 6.  
On sera rendus en version 6 !

Exemple 3 :

La version en PROD est 5, notre livraison est toute petite et ne nécessite aucun changement à la BD : alors une seule ligne est nécessaire à rajouter au fichier dbVersion en continuant avec la version 6, en utilisant la clé « sql » et la valeur « » (vide). On sera rendus en version 6 !

## <span id="anchor-16"></span>A venir :

Éventuellement, il sera possible d’exécuter un script PHP qui effectuera des modifications complexes à la BD au lieu de simplement rouler un script SQL.

## <span id="anchor-17"></span>Notes :

1 : en PROD cette valeur devrait être « stable », mais SB utilisera la valeur inscrite dans la constante BRANCHE_POUR_MAJ du fichier \<dossier d’installation\>/src/commun/lesconstantes.php,  
2 : le noVersion inscrit dans les fichiers ci-haut est complètement indépendant du nom de la branche sur github!
