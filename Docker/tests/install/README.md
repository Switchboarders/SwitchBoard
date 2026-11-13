# Tests d'installation Docker / Docker install tests

À relancer après tout changement important (Dockerfiles, docker-compose, up.sh, up.ps1, scripts de
démarrage) pour garantir que l'installation fonctionne toujours, partout.
Run these after any significant change to make sure the install still works everywhere.

| Script | Durée | Ce qu'il vérifie |
|---|---|---|
| `static.sh` | secondes | Syntaxe des scripts, fichiers compose valides (toutes les combinaisons), fins de ligne protégées pour Windows (`.gitattributes`, vrai clone avec `core.autocrlf=true`), encodage de `up.ps1`/`up.cmd`. |
| `run-linux.sh` | ~15 min par distribution | Installation réelle sur Debian, Ubuntu, Fedora, Rocky, Alpine et Arch (une « machine » Docker-dans-Docker par distribution, avec le Docker de cette distribution). |
| `run-linux.sh --distros pi` | ~1 h | Raspberry Pi OS 64 bits : toutes les images construites et lancées en ARM64 sous émulation QEMU (ajoutée au noyau le temps du test, puis retirée). Pas dans la liste par défaut, car lent. |
| `run-lan.sh` | ~15 min | Vrai réseau local : une installation (Debian) sur cet ordinateur, ports publiés (18080/18443), ouverte depuis d'autres ordinateurs du réseau par SSH (arch-desktop sous Linux, vieuxpcwindows sous Windows) : redirection https, page, connexion admin. |
| `run-windows.sh` | ~15 min (~2 min sans Docker Desktop) | `tests/install/windows.ps1` sur un PC Windows par SSH (Windows PowerShell 5.1). |

```bash
cd Docker
./tests/install/static.sh
./tests/install/run-linux.sh                       # toutes les distributions
./tests/install/run-linux.sh --distros "debian alpine" --keep   # quelques-unes, en gardant les conteneurs
./tests/install/run-lan.sh                         # vrai réseau local (clients : arch-desktop, vieuxpcwindows)
./tests/install/run-windows.sh                     # vieuxpcwindows via arch-desktop
./tests/install/run-windows.sh --sans-installation # vérifications rapides, sans installation complète
./tests/install/run-windows.sh monpc ""            # autre PC, connexion SSH directe
```

Les tests demandent à GitHub si le dépôt est public, et attendent en conséquence : sans jeton, une
installation (dépôt public) ou un refus clair (dépôt privé). Tant que le dépôt est privé, les tests
Linux prennent le jeton dans `SB_GITHUB_TOKEN` ou, à défaut, dans la session GitHub CLI
(`gh auth token`). Il ne va que dans les machines de test jetables.

Chaque script affiche `PASS`/`FAIL` par vérification, un bilan, et se termine en erreur s'il y a un
échec. Les journaux de `run-linux.sh` sont dans `tests/install/results/<distribution>/` (ignoré par git).

## run-linux.sh : ce qui est vérifié, pour chaque distribution

Le dossier `Docker/` est copié tel qu'un `git clone` le donnerait, puis :

**Mode autonome** (`./up.sh --non-interactive`) :
- aucune question, attend que SwitchBoard soit prêt ; les 5 conteneurs tournent ; le réseau `web` est créé ;
- `.env` : mots de passe aléatoires, clé `APP_ENCRYPTION_KEY` de 32 octets, mode mémorisé, adresse IP dans le certificat ;
- depuis un autre « appareil » du réseau : `http://` redirige vers `https://`, `https://localhost`,
  `https://<IP>` et `https://<nom de la machine>` répondent, le certificat couvre l'adresse IP ;
- création du premier compte admin par le formulaire, connexion, mauvais mot de passe refusé ;
- rapport `diagnostic.sh` : sections présentes, aucun mot de passe, clé ni jeton ;
- cron actif, php-fpm pas en root ;
- relancer `up.sh` ne change rien (mêmes secrets) ; `docker compose restart` et `down` + `up.sh` gardent les données ;
- port 80 déjà pris : `up.sh` s'arrête avec une explication.

**Mode reverse-proxy** (`--mode=reverse-proxy`, dans un dossier avec espaces et accents) : 3 conteneurs, ports 80/443 laissés libres,
`sb_web_server` joignable sur le réseau `web`.

**Erreurs d'utilisation et pannes** (Debian) : chaque cas doit s'arrêter avec un message clair, sans
laisser un état cassé :
- `sh up.sh` (relancé avec bash) ; questions interrompues (aucun `.env` à moitié écrit) ;
- `--host` adresse IP ou invalide (refusé, aucun `.env` créé) ; `.env` du Bloc-notes (BOM, CRLF),
  jeton entre guillemets, domaine `HTTPS://Sb.Exemple.org/` (corrigés) ;
- `.env` supprimé alors que la base existe (refusé : pas de nouveaux mots de passe) ;
- utilisateur hors du groupe `docker` ; `.env` créé avec sudo ;
- GitHub injoignable (port 443 bloqué) ; faux certificat (proxy, date fausse) ; service Docker arrêté ;
- deuxième installation dans un autre dossier pendant que la première tourne (refusée, la première
  n'est pas touchée) ; mot de passe de la base changé dans `.env` après l'installation (expliqué ;
  remettre l'ancien répare) ;
- Docker arrêté en pleine construction des images (expliqué ; relancer `up.sh` termine l'installation).

**Sans Docker** : `up.sh` explique comment l'installer. **Jeton GitHub** (obligatoire, vérifié auprès
de GitHub avant toute construction) : sans jeton, `up.sh` refuse en quelques secondes sans rien
démarrer ; jeton refusé (Debian) : même chose, et le jeton n'est ni affiché ni enregistré dans `.env` ;
installation déjà faite avec un jeton devenu invalide : avertissement, mais SwitchBoard redémarre. **Debian seulement** : le complément
`docker-compose.windows.yml` (base et code dans des volumes Docker) fonctionne.

Infrastructure : réseau `sbtest-lan` (le « réseau local »), conteneur `sbtest-client` (curl + openssl),
miroir Docker Hub `sbtest-mirror` (volume `sbtest-mirror-data`, garde les images d'une exécution à
l'autre). Rien ne touche aux conteneurs SwitchBoard qui tourneraient déjà sur la machine de test.
Nettoyage complet : `docker rm -f sbtest-mirror sbtest-client; docker volume rm sbtest-mirror-data; docker network rm sbtest-lan`.

## windows.ps1

Toujours : syntaxe de `up.ps1` ; préparation sans question (`-PrepareOnly`) avec des scripts
volontairement convertis en CRLF (ils doivent revenir en LF) ; `.env` sans BOM, secrets aléatoires,
fuseau IANA, nom du PC dans le certificat ; relance ; mode reverse-proxy ; message clair sans Docker ;
`docker compose config` avec le séparateur `;` (client Docker portable téléchargé dans
`%LOCALAPPDATA%\sbtest-tools` si Docker Desktop est absent, sans droits admin).

Erreurs d'utilisation : `.env` du Bloc-notes (BOM, CRLF) avec jeton entre guillemets et domaine
`HTTPS://…/` (corrigés), `-HostName` adresse IP ou invalide (refusé, aucun `.env`), `.env` supprimé
alors que le volume de la base existe (refusé).

Jeton GitHub : sans jeton et avec un jeton refusé, `up.ps1` refuse avec la raison (sans enregistrer
le jeton refusé) ; un jeton valide est enregistré dans `.env`.

Si Docker Desktop fonctionne (moteur Linux) : installation complète, `https://localhost` /
`https://<IP du PC>`, création du premier compte admin par le formulaire, rapport `diagnostic.ps1`
(sections présentes, accents lisibles, aucun secret), deuxième installation refusée, mot de passe de la
base changé puis remis. Elle est sautée si une vraie installation SwitchBoard existe déjà sur le PC
(conteneurs `sb_*` ou volumes `switchboarddocker_*`), pour ne jamais la toucher. Les tests travaillent
dans `%USERPROFILE%\sbtest-install-auto` et refusent de supprimer ce dossier s'ils ne l'ont pas créé.

Docker Desktop demande la virtualisation activée dans le BIOS/UEFI et WSL 2. S'il est installé mais
que la virtualisation est désactivée, le test vérifie que `up.ps1` le dit tout de suite. S'il est
arrêté, `windows.ps1` le démarre dans la session de l'utilisateur connecté (tâche planifiée).
`run-windows.sh` envoie le jeton GitHub (`SB_GITHUB_TOKEN` ou `gh auth token`) dans un fichier
effacé dès sa lecture.

`vieuxpcwindows` (HP ProBook 6460b, i5-2520M, Windows 10, Docker Desktop 4.95 installé pour
l'utilisateur seul) : 25 vérifications réussies, installation complète comprise (~15 min).
