# Passation

Pour la personne, et le Claude, qui reprend le travail à la place de l'autre mainteneur. Ce fichier dit **comment** reprendre
et **où on en est** ; **où est quoi** est dans `ARCHITECTURE.md`, les règles d'écriture dans `CONVENTIONS.md`, le vocabulaire dans
`LEXIQUE.md`, le déroulement de la refonte de 2026 dans `archives/REFONTE-2026-11.md`. Aucun secret, aucun nom ni numéro réel
dans ce fichier : ces informations passent par un autre canal.

## Qui a la main

**Une seule personne à la fois** travaille sur le code (sinon : deux numéros de version, des fusions qui se chevauchent, des
installations en double). Celui qui prend la main ajoute une ligne ici, dans un commit à part ; celui qui la rend, une ligne aussi.

| Date | Dernier état connu |
|---|---|
| 2026-11-10 | Refonte reprise par un nouveau mainteneur ; v116 installée sur les 2 serveurs |
| 2026-11-12 | Refonte terminée en v130 (voir l'archive). Serveur principal en v131 (installée le jour même par un mainteneur) ; v132 à v135 publiées ; serveur pilote vu en v124 : **les v125 à v131 y restent à installer** |
| 2026-11-12 | Dossier de la refonte fermé après la revue du plan : v136 publiée (le dernier GET qui agissait). Serveur principal en v135, serveur pilote en v124. Personne n'a la main |
| 2026-11-12 | Préparation de la publication du code : v137 (la mise à jour suit `main`, sans jeton GitHub). Voir « Passage au dépôt public » |

## Où on en est (2026-11-12)

Le code est en **v137**, sur `main`. Dans le dépôt privé, `SwitchBoard2.0` et `main` restent identiques jusqu'au passage au dépôt
public : les serveurs d'avant la v137 suivent `SwitchBoard2.0`, ceux d'après suivent `main`. Dernier état connu des serveurs :
- **Serveur pilote** (cPanel, MariaDB 10.11, aucun comité actif) : v124. Les crons appellent le PHP par son chemin complet : ils marchent sans changement.
- **Serveur principal** (Docker, MariaDB 12, les comités réels) : **v135 installée le 2026-11-12** par un mainteneur. Vu après l'installation : un vrai changement de garde
  à 13 h (21 appels à voip.ms, aucun échec) ; au dernier relevé (22 h 20, heure du conteneur), le passage du cron de sonnerie traite les 15 comités
  sans échec et aucune erreur PHP n'est au journal. Son dépôt `Docker/` est à jour. Pas de `BD.sql` à sa racine.
- **Incident en cours, hors du code** : depuis 12 h 50 le 2026-11-12, le serveur principal n'atteint plus `outlook.office.com` ni `smtp.office365.com` (IPv4 ;
  `login.microsoftonline.com` répond). Les comités dont l'agenda est chez Microsoft tournent sur l'agenda en cache : la garde change aux heures prévues, mais une
  modification faite dans leur agenda après la panne n'est pas vue. Les courriels sortants (alertes, rapports) échouent. À voir avec l'hébergeur ; à régler avant le
  1er décembre (rapports mensuels).

Les v125 à v135 sont faites sur le serveur principal (les v136 et v137 y restent à installer) ; toutes restent à installer sur le pilote (sans SQL : la version saute directement à la v137). Après l'installation :

| Version | Contenu | À vérifier après l'installation |
|---|---|---|
| 125 | Page des macros : « Aucune macro » pour un centre d'appel sans macro | Ouvrir « Macros » sur un comité sans macro |
| 126 | Rapports (tâche 8.1) | **Touche les courriels mensuels** (coût des DID, statistiques) : le 1er du mois, comparer le courriel reçu à celui du mois d'avant (mêmes lignes, mêmes totaux) |
| 127 | Redirection après l'approbation ou le retrait d'un certificat SwitchBoard Chat | Approuver puis retirer un certificat : la page revient seule, F5 ne refait rien |
| 128 | Une installation neuve part de la structure figée | Aucun effet sur un serveur existant ; à essayer sur une base vide |
| 129 | Séparation traitement / vue de 23 pages | Aucun octet de HTML ne change : ouvrir l'accueil, la console, les informations du compte, SwitchBoard Chat, l'entente, les macros, le certificat, le courriel de test, le signal de vie et les services |
| 130 | **`ringgroupupdater/` vidé** : `commun/`, `portail/`, `languages/` | Les pages gardent leur style (les feuilles de style et les scripts ont de nouvelles adresses) ; les crons tournent ; un retour arrière par `jobRestore.php` marche |
| 131 | Installation neuve : la BD reçoit elle-même les migrations au-dessus de la structure figée (jusqu'ici le compteur restait à 127, sans effet tant que les migrations étaient vides) ; `prepare_switchboard.sh` ne crée plus `src/ringgroupupdater/logs` | Aucun effet sur un serveur existant ; sur une installation Docker neuve, « version du schéma de BD » égale la version du code |
| 132 | **Plus aucun GET n'agit** (sauf l'envoi d'un rapport, réglé en v136) : « Forcer », rappels (SMS compris : `remindersms` et `remindersmsjustbefore` s'ouvraient sans jeton), rafraîchir SBChat, jeton API, supprimer un utilisateur, mode debug, effacer les journaux et supprimer une macro sont des boutons POST avec jeton, suivis d'une redirection | Ouvrir le menu (Groupes de sonneries, SwitchBoard Chat) : mêmes entrées, même aspect ; « Forcer » depuis le menu et depuis la console : le résultat s'affiche, F5 ne refait rien ; une ancienne adresse `?page=refreshringgroup&csrftoken=…` ramène au tableau de bord sans rien faire |
| 133 | Redirection après envoi pour le formulaire des **utilisateurs Matrix** (le mot de passe n'est jamais gardé en session : le navigateur le remet dans l'invitation) et pour l'assistant **Courriel OAuth** (le code d'autorisation de Google ne reste plus dans l'adresse) | Créer un utilisateur Matrix : l'invitation s'affiche avec le mot de passe, F5 ne recrée rien ; Courriel OAuth : « Démarrer », « J'ai autorisé, continuer » et le retour de Google ramènent à la page |
| 134 | **Les anciennes classes de `commun/` passent dans `modules/`** avec un nom français (table des noms dans `ARCHITECTURE.md`) ; `DatabaseManager` garde son nom et va dans `modules/donnees/`. Plus aucun `require_once` de classe : l'autoload s'en charge. Aucun changement de comportement : mêmes requêtes à voip.ms, mêmes courriels, mêmes pages | Les crons tournent ; **un retour arrière par `jobRestore.php` vers une sauvegarde d'avant la v134** (il cherche `DatabaseManager` dans les trois dispositions) ; ouvrir quelques pages |
| 135 | La validation de l'agenda (`TraitementComite::validerAgenda()`) et l'affichage d'un journal (`JournalDeTache::texteDuJournal()`) ne produisent plus de HTML : les pages le font | La page de validation de l'agenda, le lien de validation sans connexion (`token.php`) et les journaux au bas de la page principale : mêmes textes qu'avant |
| 136 | **« Envoyer ce rapport par courriel »** (« Mes statistiques », et le rapport d'un comité dans la console) devient un bouton POST avec jeton, suivi d'une redirection : c'était le dernier GET qui agissait, oublié en v132. Les commentaires disent maintenant les bons numéros d'issues (#77 : redirection ; #78 : « Forcer » par comité) | Ouvrir « Mes statistiques » : mêmes rapports, même aspect ; envoyer un rapport : « Courriel envoyé » s'affiche une fois, F5 ne renvoie rien ; dans la console, pour un comité membre d'un compte principal, le courriel ne contient que son DID |
| 137 | **Préparation du dépôt public.** La mise à jour suit la branche `main` et marche sans jeton GitHub ; un jeton refusé (révoqué, expiré) est ignoré : la demande se refait sans lui, et la console le dit. `up.sh`, `up.ps1` et `prepare_switchboard.sh` installent sans jeton depuis le dépôt public. `SECURITY.md` (signalement privé), `CONTRIBUTING.md`, `AUTHORS.md` ; données de test sans numéro ni adresse réels | La console montre « disponible sur GitHub » avec un numéro (sinon, la raison s'affiche) ; avec un jeton révoqué : « GitHub refuse le jeton configuré », et la vérification se fait quand même |

Un point à connaître : la v130 déplace les fichiers. L'installation par le moteur de mise à jour remplace `src/` d'un bloc, donc rien
de l'ancienne disposition ne reste. Si un navigateur garde une ancienne feuille de style en cache, un rechargement complet règle l'affaire.

**La licence** est PolyForm Noncommercial 1.0.0 depuis le 2026-11-12 (fusion de la branche `licence-polyform`, à la demande d'un mainteneur). Un changement de
licence demande en principe l'accord de chaque titulaire de droits : le courriel du 2026-11-11 à l'autre mainteneur est resté sans réponse, et les
deux autres contributeurs n'ont pas été contactés. Si l'un d'eux s'y oppose, `git revert` de la fusion remet l'ancienne licence ; les copies déjà obtenues gardent leurs conditions (c'est dit dans le README).

**Restent sans date** : la liste complète, relevée à la revue de fin en reprenant le plan point par point, est à la fin de `archives/REFONTE-2026-11.md`
(dans le code, sur les serveurs, les vérifications réelles, ce qui revient aux mainteneurs). Les plus proches : installer les v125 à v136 sur le pilote et y retirer le vieux
`BD.sql` s'il existe ; installer la v136 sur le serveur principal ; relire les rapports du 1er décembre ; fermer les issues #28, #55, #68, #72, #77 et #78 (réglées dans le code).

## Passage au dépôt public

Le code devient public dans un **dépôt neuf**, sur la branche `main`, en un seul commit. Le dépôt privé n'est pas rendu public :
son historique, ses branches et ses issues contiennent des secrets et des données personnelles. Il est renommé et reste privé, comme
archive. Ordre à suivre, chaque étape faite avant la suivante :

1. **v137** (fait) : la mise à jour suit `main` et marche sans jeton ; un jeton refusé est ignoré ; les installateurs installent sans jeton.
2. **`main` dans le dépôt privé**, identique à `SwitchBoard2.0`, pour que les serveurs passés en v137 trouvent leur branche. Tant que le
   dépôt privé sert, chaque version est poussée sur les deux.
3. **Installer la v137** sur le pilote, le serveur principal et tout autre serveur connu. Un serveur resté avant la v137 cherchera
   `SwitchBoard2.0`, qui n'existera pas dans le dépôt public : il ne verra plus de mise à jour (une installation à la main règle l'affaire).
4. **Révoquer et changer les secrets** qui ont figuré dans l'historique du dépôt privé (la liste est remise aux mainteneurs, hors dépôt).
   À faire même si le passage est retardé.
5. **Accords** : celui des contributeurs sur la licence et sur leur nom dans `AUTHORS.md`. `ifsnop/mysqldump-php` (GPL-3.0, dans le
   zip des dépendances, qui sert à la sauvegarde de la BD) ne s'accorde pas avec une licence non commerciale : **décidé le 2026-11-12,
   publier tel quel et la remplacer ensuite** (tâche ouverte : remplacer la sauvegarde de la BD, refaire le zip, banc), ou demander un avis.
6. **Renommer le dépôt privé** en `SwitchBoard-archive` ; il garde ses issues et reste privé.
7. **Créer le dépôt public** `Switchboarders/SwitchBoard`, vide, et y pousser le dépôt préparé (`main`). Branche par défaut : `main`.
8. **Réglages du dépôt public**, posés avant la première poussée : `main` ne peut être ni supprimée ni écrasée (poussée forcée), et
   aucune autre branche ne peut être créée. Un ancien clone ne peut donc rien y pousser par erreur (son historique n'a aucun lien avec
   `main`). Puis : signalement privé de vulnérabilités (onglet *Security*), détection des secrets avec blocage à la poussée, alertes
   Dependabot.
9. **Vérifier** : le banc d'essai passe sur GitHub ; sur chaque serveur, la console montre « disponible sur GitHub » avec le bon numéro
   et aucun avertissement de jeton (sinon, retirer ou remplacer le jeton) ; le banc d'installation passe sans jeton
   (`Docker/tests/install/run-linux.sh`, il voit seul que le dépôt est public).
10. **Anciens clones** : dans chacun, `git remote set-url origin https://github.com/Switchboarders/SwitchBoard-archive.git` (sinon il
    pointe vers le dépôt public), puis cloner le dépôt public à part. Le dossier `Docker/` du serveur principal est un clone de l'ancien
    dépôt : le recloner depuis le dépôt public au prochain changement de `Docker/` (les conteneurs en marche n'en dépendent pas).

## Une modification, pas à pas

1. **Une branche locale** partie de `main` : `git switch main && git pull && git switch -c nom-court`. Le dépôt public n'accepte
   aucune autre branche que `main` : une branche à partager passe par une copie (fork).
2. **Le banc d'essai** : `SwitchBoard/tests/banc/lancer.sh` (Docker requis ; MariaDB 12 par défaut, environ 5 minutes). Il doit être **vert avant toute
   fusion** ; on ajoute `--mariadb 10.11` **seulement si la version contient du SQL**. Les défauts déjà connus sont marqués `connu()` et ne rendent pas le banc rouge.
   Un changement qui ne doit rien changer à l'écran ou aux requêtes de voip.ms se prouve par les références (`reference/`) : elles restent identiques.
   Ne jamais enchaîner le banc et un commit sans lire le code de sortie du banc.
3. **Relire le diff**, pas seulement le résultat du banc. Si la modification change la lecture des agendas ou la sonnerie, comparer en plus l'ancien et le nouveau code
   sur de vrais agendas en cache d'un serveur.
4. **Version** : `codeVersion` dans `src/updatejson/codeVersion.json` **et** une entrée `noVersion` égale dans `src/updatejson/dbVersion.json`
   (`"sql": ""` si aucune migration), dans le même commit. Jamais l'un sans l'autre. Le numéro se donne à la fusion.
5. **Fusion dans `main`**, **sur demande d'un mainteneur seulement** (une contribution externe arrive par une PR, voir `CONTRIBUTING.md`). On ne supprime jamais une branche. Une issue se
   ferme à la main, avec l'accord d'un mainteneur et un commentaire qui dit ce qui est réglé et ce qui reste.
6. **Installation** : serveur pilote d'abord, puis serveur principal, par la console d'administration (clic d'un admin) ou par SSH (`php src/miseajour.php`).
   Vérifier la version, la BD, l'absence d'erreur PHP dans les journaux, puis le premier changement de garde réel sur le serveur principal.
7. **Mettre à jour la table « Où on en est »** ci-dessus.

## Règles à ne pas enfreindre

- **Rien n'est commité ni poussé sans la demande d'un mainteneur.** Montrer le diff avant.
- **Le téléphone de garde ne doit jamais être cassé** : toute modification qui touche voip.ms, la sonnerie, la mise à jour ou les droits d'accès demande le plus
  de soin. Le banc ne remplace pas l'observation d'un vrai changement de garde.
- **Ne pas toucher** au nid `Docker/web` ni aux permissions du conteneur (décision, pas une omission), ni à la liste « Ne bougent jamais » de `CONVENTIONS.md` (section 8).
- Pas de rustine sur du vieux code : si une correction touche du code mal fichu, on le réécrit proprement, avec les mêmes façons de faire partout.
  Pas de gros chantier de normalisation sans en parler d'abord. Un test par bogue corrigé.
- Aucun secret dans le dépôt, les journaux ou les messages : un mot de passe API voip.ms apparaît dans l'URL des appels ; ne jamais afficher une URL
  d'appel, masquer les numéros de téléphone dans tout ce qu'on cite. Aucun nom de personne ni de serveur réel dans un guide suivi par Git.
- **Une version fusionnée dans `main` fait apparaître la bannière de mise à jour sur les serveurs**, et n'importe quel admin peut cliquer.
  Ne fusionner que si on est prêt à l'installer (et pas pendant une minute 0 : le cron de sonnerie bloque la mise à jour).

## Accès (hors dépôt)

Cette liste dit **de quoi on a besoin**, pas où le trouver.

| Besoin | Pour quoi |
|---|---|
| Docker et Git sur le poste, droit de pousser sur `main` | Écrire le code, lancer le banc, fusionner |
| Console d'administration des 2 serveurs | Installer une version (le serveur principal exige une 2FA envoyée par courriel) |
| SSH du serveur principal (Docker) | Lire les journaux des crons, vérifier un changement de garde |
| Accès au serveur pilote (cPanel, SSH) | Installer et vérifier d'abord là |
| Compte voip.ms (lecture) | Contrôler le groupe de sonnerie réel après un changement de garde |

Si un de ces accès manque un jour, la partie « vérifier » revient à celui qui l'a : le code et le banc se font sans.

## Si on s'arrête en pleine tâche

Écrire ici l'état exact (branche, ce qui est fait, ce qui reste, ce qui est vérifié ou non). Une branche non fusionnée et non commitée reste sur le poste de celui qui
travaillait : si la main change, **commiter et pousser la branche d'abord, sur demande d'un mainteneur**, sinon l'autre ne la verra pas.
