# Refonte de SwitchBoard, novembre 2026 (archive)

Document figé, dossier fermé le 2026-11-12 en v136. Il résume le déroulement ; ce qui reste vrai est dans `ARCHITECTURE.md`,
`CONVENTIONS.md` et `PASSATION.md`. Le plan de travail complet (fiches, problèmes un par un, tableau des tâches) est dans l'historique Git :
`git show 1f2e44b:REFONTE.md`.

## But

Rendre le code lisible et sûr pour de petites équipes de bénévoles, sans jamais risquer la ligne de garde : la sonnerie de
l'aide téléphonique (groupes de sonnerie voip.ms mis à jour depuis les agendas) ne doit pas tomber. Livraison par étapes, chacune en
production quand le banc d'essai est vert, fusion directe dans `SwitchBoard2.0`.

## Ce qui a été fait, version par version

| Étape | Versions | Résultat |
|---|---|---|
| 1. Correctif de l'agenda | v106 | Un agenda illisible ne vide plus le groupe de sonnerie (voip.ms refusait le groupe vide, et le code effaçait les renvois du comité) |
| 2. Banc d'essai Docker | sans version | Image du serveur principal, faux services, réseau sans sortie, références suivies par Git ; la structure des BD relevée en lecture seule |
| 3. Mise à jour fiable | v107, v108, v109 | Nouveau moteur : tout vérifié dans `download/` avant de toucher l'installation, sauvegarde, remplacement de `src/` par renommage, migrations une à une, retour arrière ; version témoin, puis les pages ergonomiques |
| 4. Socle | v110, v111, v112, v128 | Amorce unique et chemins en constantes ; table des routes (une faille de session d'utilisateur supprimé corrigée) ; ménage du code mort ; installation neuve depuis la structure figée |
| 5. Messages | v113, v115 | Classe `Journal`, erreurs passagères de voip.ms au journal seulement, alertes limitées à une par 24 h, comité et problème |
| 6. Agenda et téléphonie | v116, v117 | `Agenda`, `NumeroTelephone`, `ClientVoipms`, `GroupeSonnerie` : reprises sur les pannes passagères de voip.ms (403, 525), un groupe vide n'est jamais envoyé |
| 7. Rappels | v118 | `RappelsSms`, `RappelsChat` |
| 8. Rapports | v126 | `CorpsDuRapport` ; courriels identiques à la référence |
| 9. Pages | v119 à v129 | Jeton CSRF sur les liens qui agissent (v119) ; « Forcer » par comité (v121) et verrou par comité (v123) ; redirection après un enregistrement, une dizaine de formulaires (v122, v124, v127) ; macros SwitchBoard Chat (v125) ; séparation traitement / vue de 23 pages (v129) |
| Divers | v120 | PHP 8.3 comme plancher, chemin du PHP des cron |
| 10. Fin | v130 | `ringgroupupdater/` vidé : `commun/`, `portail/`, `languages/` ; documentation remontée dans `ARCHITECTURE.md`, ce fichier en archive |
| Suite : installation neuve | v131 | La BD neuve reçoit les migrations au-dessus de la structure figée (le compteur restait à 127) ; `prepare_switchboard.sh` ne crée plus `src/ringgroupupdater/logs`. Installation Docker neuve vérifiée de bout en bout (76 vérifications sur Debian) |
| Suite : plus aucun GET n'agit | v132, v136 | « Forcer », rappels (SMS compris), SBChat, jeton API, supprimer un utilisateur, mode debug, effacer les journaux, supprimer une macro (v132), puis l'envoi d'un rapport depuis « Mes statistiques » et la console, oublié en v132 et trouvé à la revue de fin (v136) : des boutons POST avec jeton, suivis d'une redirection |
| Suite : redirection après envoi | v133 | Utilisateurs Matrix (le mot de passe n'est jamais gardé par le serveur : le navigateur le remet dans l'invitation) et assistant Courriel OAuth (le code de Google ne reste pas dans l'adresse), contre de faux MAS, Microsoft et Google |
| Suite : classes | v134, v135 | Les 17 classes de `commun/` dans `modules/`, avec un nom français (`DatabaseManager` garde le sien) ; plus aucun `require_once` de classe. La validation de l'agenda et l'affichage d'un journal ne produisent plus de HTML |
| Licence | sans version | PolyForm Noncommercial 1.0.0 à la place de l'AGPL v3 avec la Commons Clause (fusion `b9a21fd`, à la demande d'un mainteneur) |

**Numéros d'issues inversés.** Les messages de commit de la v121 à la v135 disent `#77` pour « Forcer » par comité et `#78` pour la
redirection après l'enregistrement. Sur GitHub, c'est l'inverse : #77 est « Retour depuis la validation de l'agenda : le navigateur
demande de renvoyer le formulaire » (la redirection), #78 est « Console admin : lien Forcer… ». Les commentaires du code et du banc sont
corrigés en v136 ; l'historique Git ne se réécrit pas.

## Décisions qui valent encore

- Pas de framework, pas de namespaces, pas d'autoload Composer ; pas de renommage de colonnes ; `DatabaseManager` n'est pas découpé.
- Aucun test ne touche un vrai service ; l'application ne tourne jamais sur le compte voip.ms réel.
- On ne touche pas au nid `Docker/web` ni aux permissions du conteneur.
- Le rognage des journaux à 10 000 lignes est gardé (la rotation à 5 Mo n'est qu'un filet) ; l'historique réel d'un journal est donc de 10 000 lignes.
- Le serveur pilote reçoit chaque version d'abord ; le serveur principal ensuite.

## Ce qui est resté tel quel (à reprendre au besoin)

Relevé à la revue de fin, en reprenant le plan point par point (problèmes 1 à 14, tâches 0.1 à 10.1).

**Dans le code** (choix ou limites connues, sans danger pour la sonnerie) :
- Les classes venues de `commun/` écrivent encore à l'écran et gardent leurs méthodes en anglais ; `Langue` charge `portail/useraccess.php` à la main.
- Sans redirection après envoi, par choix : le rapport multi-comptes (appels JSON, téléchargement) et l'installation par la console (progression en continu).
- La page « Forcer » montre une ligne vide de trop après certains messages de `GroupeSonnerie` (`\n` déjà suivis d'un `<br>`) ; les retirer change cette page.
- Le numéro de téléphone suit `NumeroTelephone` pour l'agenda, les groupes et les SMS, mais pas encore dans `genstatsLAA.php`, `CoutsDid` ni la page des
  informations du comité (`validatePhoneNumber()`).
- `validateForm()` (3 formulaires) appelle `window.location.reload()` à l'envoi, depuis le premier commit, sans but connu : sans effet sur l'envoi.
- Limites du moteur de mise à jour (problèmes 6 et 13) : `vendor/` absent ne se répare pas par `miseajour.php` ; OPcache sans `validate_timestamps`
  garde l'ancien code jusqu'au redémarrage de PHP ; un arrêt entre les deux renommages se répare par `jobRestore.php`.

**Sur les serveurs** :
- **Vieux `BD.sql` à la racine** : absent du serveur principal ; à vérifier et retirer à la main sur le serveur pilote.
- Serveur pilote : la page « Suggestions de commandes Cron » (v120) doit afficher le PHP de cPanel (`ea-php84`) ; pas encore regardé.
- Un troisième serveur, s'il existe, n'a pas sa structure de BD relevée.

**Vérifications réelles qui restent** (le banc ne les remplace pas) : comparer à la main le groupe de sonnerie réel chez voip.ms et l'agenda d'un
comité ; la longueur maximale d'un nom de groupe chez le vrai voip.ms (entre 16 et 25 caractères) ; les rapports mensuels du 1er décembre, comparés
à ceux du 1er novembre (v126).

**Aux mainteneurs** : l'accord sur le plan et `CONVENTIONS.md` (tâche 0.1) et les trois points « à confirmer » (livraison par étapes, ordre pilote
puis principal sans PR pour tous, renommage de colonne sur deux versions) ; l'accord des titulaires de droits sur la licence ; la fermeture des issues
#28, #55, #68, #72, #77 et #78, réglées dans le code mais encore ouvertes.
