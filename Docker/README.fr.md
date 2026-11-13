# SwitchBoard avec Docker

[English](README.md) · **Français**

La version Docker de SwitchBoard est un serveur complet et autonome. Elle fonctionne de la même façon
sur un VPS, un serveur maison, un PC Linux, un PC Windows ou un Raspberry Pi. Un seul script installe
et démarre tout : `./up.sh` sous Linux et sur Raspberry Pi, `up.cmd` sous Windows.

<a id="contents"></a>
## Table des matières

- [Avant de commencer](#before-you-start)
- [Démarrage rapide](#quick-start)
- [Installer sous Linux](#install-linux)
  - [Debian, Raspberry Pi OS](#linux-debian)
  - [Ubuntu, Linux Mint](#linux-ubuntu)
  - [Fedora](#linux-fedora)
  - [Rocky Linux, AlmaLinux, RHEL](#linux-rhel)
  - [Arch Linux, Manjaro](#linux-arch)
  - [Alpine Linux](#linux-alpine)
- [Installer sous Windows](#install-windows)
- [Installer sur un Raspberry Pi](#install-raspberry-pi)
- [macOS](#install-macos)
- [Utiliser up.sh / up.ps1](#running-up)
  - [Les deux modes : autonome ou derrière un reverse-proxy](#modes)
  - [Installation entièrement automatique (sans question)](#non-interactive)
  - [Commandes courantes](#everyday-commands)
- [Accès depuis le réseau local](#lan-access)
- [Référence de configuration](#configuration)
  - [Les conteneurs](#containers)
  - [Le fichier .env](#env-file)
  - [Le fichier nginx.conf](#nginx-conf)
  - [Courriel (SMTP)](#mail)
  - [Certificat SBChat auto-signé](#sbchat-certificate)
  - [Surveillance (dead-man's-switch)](#monitoring)
- [Utiliser un reverse-proxy externe](#reverse-proxy)
- [Dépannage](#troubleshooting)
- [Rapport de diagnostic](#diagnostic)
- [Tests d'installation](#install-tests)
- [Développement avec Docker](#development)

<a id="before-you-start"></a>
## Avant de commencer

Il vous faut :

1. **Docker** avec le module **Compose** (Docker Desktop sous Windows). La section de votre plateforme,
   plus bas, montre comment l'installer. Les scripts de SwitchBoard n'installent jamais Docker
   eux-mêmes : ils vérifient sa présence et expliquent quoi faire s'il manque.
2. **Un accès Internet à GitHub.** Le dépôt SwitchBoard est public : le serveur le télécharge sans
   jeton. Un jeton GitHub est **facultatif**. Il ne sert qu'à suivre une copie privée du dépôt, ou à
   lever la limite de GitHub pour les requêtes sans jeton (60 par heure et par adresse IP) lors de la
   vérification des mises à jour. Pour en créer un : photo de profil → *Settings* → *Developer settings*
   → *Personal access tokens* → *Fine-grained tokens*, avec l'accès en lecture à **Contents** et
   **Metadata**. L'installateur vérifie l'accès auprès de GitHub avant de construire quoi que ce soit.
3. **Les ports 80 et 443 libres** sur la machine, pour le [mode autonome](#modes). Si un autre serveur
   web les utilise déjà, prenez plutôt le [mode reverse-proxy](#reverse-proxy).
4. Un système **64 bits** : x86-64 (PC, VPS) ou ARM64 (Raspberry Pi 3/4/5, Zero 2 W).

<a id="quick-start"></a>
## Démarrage rapide

Une fois Docker installé pour votre plateforme :

```bash
git clone https://github.com/Switchboarders/SwitchBoard.git
cd SwitchBoard/Docker
./up.sh
```

Sous Windows, double-cliquez plutôt sur **`up.cmd`** dans le dossier `Docker`. À la fin, le script
affiche les adresses à ouvrir, par exemple `https://localhost` et `https://192.168.1.20`. Créez le
premier compte administrateur sur la page qui s'ouvre.

Le premier démarrage prend quelques minutes, ou de 10 à 20 minutes sur un Raspberry Pi : SwitchBoard
est téléchargé et ses dépendances installées. Le script attend et vous prévient quand c'est prêt.

<a id="install-linux"></a>
## Installer sous Linux

Sur toutes les distributions : installez Docker, démarrez-le, permettez à votre utilisateur de s'en
servir, puis [clonez et lancez `./up.sh`](#quick-start). Les commandes ci-dessous sont celles des
[tests d'installation](#install-tests), qui installent réellement SwitchBoard sur chacune de ces
distributions.

Après vous être ajouté au groupe `docker`, déconnectez-vous puis reconnectez-vous (ou redémarrez) pour
que `docker` fonctionne sans `sudo`. Vous pouvez aussi lancer `sudo ./up.sh`.

<a id="linux-debian"></a>
### Debian, Raspberry Pi OS

```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```

Le script officiel installe Docker Engine et le module Compose, puis démarre le service.

<a id="linux-ubuntu"></a>
### Ubuntu, Linux Mint

Soit le script officiel ([comme pour Debian](#linux-debian)), soit les paquets d'Ubuntu :

```bash
sudo apt-get update && sudo apt-get install -y git docker.io docker-compose-v2
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

<a id="linux-fedora"></a>
### Fedora

```bash
sudo dnf -y install dnf-plugins-core git
sudo dnf config-manager addrepo --from-repofile=https://download.docker.com/linux/fedora/docker-ce.repo
sudo dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Sur Fedora 40 et plus ancien, la commande du dépôt est
`sudo dnf config-manager --add-repo https://download.docker.com/linux/fedora/docker-ce.repo`.

<a id="linux-rhel"></a>
### Rocky Linux, AlmaLinux, RHEL

```bash
sudo dnf -y install dnf-plugins-core git
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

N'utilisez pas Podman, proposé par défaut sur ces systèmes : SwitchBoard a besoin de Docker.

<a id="linux-arch"></a>
### Arch Linux, Manjaro

```bash
sudo pacman -Syu --needed docker docker-compose git
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Le paquet `docker` d'Arch n'a pas le module `buildx`. SwitchBoard se construit très bien sans lui ;
Docker affiche un avertissement « legacy builder » que vous pouvez ignorer.

<a id="linux-alpine"></a>
### Alpine Linux

En tant que root (Alpine n'a souvent pas `sudo`), en remplaçant `mon_utilisateur` par votre
utilisateur :

```bash
apk add bash git docker docker-cli-compose
rc-update add docker default && service docker start
addgroup mon_utilisateur docker
```

`up.sh` a besoin de `bash`, qu'Alpine n'installe pas par défaut.

<a id="install-windows"></a>
## Installer sous Windows

Windows 10 ou 11, 64 bits. Docker Desktop fait tourner les conteneurs Linux de SwitchBoard dans WSL 2.

1. **Activez la virtualisation** (Intel VT-x ou AMD-V) dans le BIOS/UEFI si ce n'est pas déjà fait.
   Pour vérifier : *Gestionnaire des tâches* → *Performance* → *Processeur*, ligne « Virtualisation :
   Activé ». Pour l'activer : redémarrez, entrez dans le BIOS (souvent F2, F10, Suppr ou Échap au
   démarrage), activez « Virtualization Technology » et enregistrez. Certains portables (dont HP)
   n'appliquent ce changement qu'après un **arrêt complet**, pas un simple redémarrage.
2. **Installez [Docker Desktop](https://www.docker.com/products/docker-desktop)**, ou lancez
   `winget install Docker.DockerDesktop`. Ouvrez-le une fois, acceptez les conditions (aucun compte
   nécessaire) et attendez « Engine running ». Acceptez s'il propose d'installer ou de mettre à jour WSL.
3. **Récupérez SwitchBoard.** Soit avec [Git pour Windows](https://git-scm.com/download/win), par le même
   `git clone` que dans le [démarrage rapide](#quick-start), soit en téléchargeant le ZIP sur GitHub
   (*Code* → *Download ZIP* sur la branche `main`), puis en l'extrayant.
4. **Double-cliquez sur `up.cmd`** dans le dossier `Docker`. Il pose les mêmes questions que `up.sh`.
   Pour ouvrir aussi le pare-feu pour l'[accès depuis le réseau local](#lan-access), faites une fois
   clic droit sur `up.cmd` → *Exécuter en tant qu'administrateur*.

Ce que `up.cmd` / `up.ps1` fait pour vous sous Windows :

- Il trouve Docker Desktop, qu'il soit installé pour tous les utilisateurs ou seulement pour le vôtre,
  et le démarre au besoin.
- Il s'arrête tout de suite avec des instructions si la virtualisation est désactivée dans le BIOS, ou
  si Docker Desktop est réglé sur les conteneurs Windows.
- Il remet les scripts Linux en fins de ligne LF si Git ou un éditeur les a convertis en CRLF.
- Il garde la base de données et le code de SwitchBoard dans des volumes Docker plutôt que dans les
  dossiers `db_data` et `web`, grâce à `docker-compose.windows.yml`, ajouté automatiquement. NTFS ne
  distingue pas les majuscules et il est très lent vu de l'intérieur des conteneurs.
- Lancé en administrateur, il ajoute une règle de pare-feu « SwitchBoard HTTP/HTTPS » pour les ports
  80/443 sur les réseaux **privés**.
- Il fonctionne aussi par SSH, pour une installation à distance. Il utilise alors Docker sans le
  Gestionnaire d'identification Windows, inaccessible depuis une session SSH.

Dans PowerShell, `.\up.ps1 -NonInteractive` installe sans aucune question. Les options sont `-Mode`,
`-HostName`, `-Email`, `-TimeZone`, `-NoWait` et `-NoFirewall` ; voir
[Installation entièrement automatique](#non-interactive).

<a id="install-raspberry-pi"></a>
## Installer sur un Raspberry Pi

SwitchBoard fonctionne sur un Raspberry Pi 3, 4, 5 ou Zero 2 W avec un système **64 bits**. Toutes
ses images existent en ARM64, mais MariaDB n'a pas de version ARM 32 bits : un Raspberry Pi OS 32 bits
ne fonctionnera pas, et `./up.sh` le dit. Un Pi 4 ou 5 avec 2 Go de mémoire ou plus est recommandé.

1. Avec [Raspberry Pi Imager](https://www.raspberrypi.com/software/), installez **Raspberry Pi OS Lite
   (64-bit)**. Dans ses réglages, activez SSH, choisissez un utilisateur, le Wi-Fi au besoin, et un nom
   d'hôte comme `switchboard`.
2. Connectez-vous au Pi (`ssh utilisateur@switchboard.local`) et installez Docker
   [comme sous Debian](#linux-debian).
3. Déconnectez-vous puis reconnectez-vous, et suivez le [démarrage rapide](#quick-start).
4. Ouvrez `https://switchboard.local` ou `https://<adresse du Pi>` depuis n'importe quel appareil du
   réseau.

Le premier démarrage construit l'image PHP sur le Pi : comptez de 10 à 20 minutes sur un Pi 4. Une
base de données use une carte SD : préférez un SSD USB (le Pi 4/5 peut démarrer dessus), et
sauvegardez régulièrement `.env` et la base. Donnez au Pi une adresse fixe dans votre routeur pour
qu'elle ne change pas.

<a id="install-macos"></a>
## macOS

`up.sh` fonctionne aussi sous macOS avec [Docker Desktop](https://www.docker.com/products/docker-desktop)
installé et démarré, mais macOS n'est pas couvert par les [tests d'installation](#install-tests).

<a id="running-up"></a>
## Utiliser up.sh / up.ps1

`./up.sh` (ou `up.cmd` sous Windows) est le seul point d'entrée nécessaire, pour la première
installation comme par la suite. À chaque lancement, il :

1. vérifie que Docker et Compose sont installés et en marche, et explique quoi faire sinon ;
2. à la première utilisation, crée `.env` à partir de `.env_sample` :
   - génère des mots de passe aléatoires pour la base et une clé `APP_ENCRYPTION_KEY`, et reprend le
     fuseau horaire de la machine ;
   - pose quelques questions : mode, domaine, courriel pour les certificats, courriel sortant.
   Chaque question peut être passée avec Entrée et remplie plus tard en modifiant `.env`.
3. vérifie l'accès au dépôt auprès de GitHub, comme `git clone` le fera :
   - **Sans jeton :** le dépôt est public, rien à faire. S'il ne l'est pas (une copie privée),
     l'installateur le dit et demande un jeton, ou, lancé sans question, s'arrête en donnant la raison.
   - **Un jeton que GitHub refuse** (inconnu, expiré, révoqué) : le dépôt étant public, l'installateur
     le dit, l'ignore et continue. Retirez-le ou remplacez-le dans `.env`.
   - **SwitchBoard déjà installé :** un problème de jeton donne seulement un avertissement, pour ne
     jamais bloquer un redémarrage.
   - Un jeton donné dans `SB_GITHUB_TOKEN` remplace celui de `.env`. Un jeton valide est enregistré
     dans `.env` ; un jeton refusé ne l'est jamais.
4. crée `nginx/nginx.conf` à partir de `nginx.conf.sample` s'il n'existe pas ;
5. crée le réseau Docker `web` au besoin, et vérifie que les ports 80/443 sont libres (mode autonome) ;
6. construit les images (avec un nouvel essai automatique) et démarre les conteneurs ;
7. attend que SwitchBoard réponde, puis affiche les adresses à ouvrir. L'attente suit la progression du
   premier démarrage, pour ne pas abandonner sur une machine lente.

Relancer `./up.sh` est sans risque : il garde vos mots de passe et vos réglages. C'est aussi la façon
d'appliquer un changement de `.env`, ou de nouveaux fichiers Docker après un `git pull`.

<a id="modes"></a>
### Les deux modes : autonome ou derrière un reverse-proxy

- **Autonome** (par défaut) : SwitchBoard a son propre reverse-proxy (`sb_nginx-proxy`) et obtient ses
  certificats TLS de Let's Encrypt (`sb_letsencrypt`). Il lui faut les ports 80 et 443. Avec
  `VIRTUAL_HOST=localhost`, il utilise un certificat auto-signé et il est
  [joignable depuis le réseau local](#lan-access).
- **Reverse-proxy** : vous avez déjà un reverse-proxy (nginx, Nginx Proxy Manager, Traefik…) sur les
  ports 80/443. SwitchBoard ne démarre que `sb_web_server`, `sb_php-fpm` et `sb_mariadb` ; voir
  [Utiliser un reverse-proxy externe](#reverse-proxy).

La première utilisation demande le mode voulu et le mémorise dans `.env` (`COMPOSE_FILE=...`). Pour
changer de mode, supprimez cette ligne de `.env` et relancez `./up.sh`.

<a id="non-interactive"></a>
### Installation entièrement automatique (sans question)

`./up.sh --non-interactive` (ou `-y`) installe et démarre tout avec des valeurs sûres :
- des mots de passe et une clé `APP_ENCRYPTION_KEY` aléatoires ;
- le fuseau horaire de la machine ;
- le mode autonome sur `localhost`.

Sans terminal (par exemple lancé depuis un autre script), il ne pose jamais de question non plus.

| up.sh (Linux, Pi, macOS) | up.ps1 (Windows) | Variable d'environnement | Rôle |
|---|---|---|---|
| `--mode=standalone\|reverse-proxy` | `-Mode` | `SB_MODE` | [Mode](#modes) |
| `--host=sb.mondomaine.com` | `-HostName` | `SB_HOST` | Domaine (`VIRTUAL_HOST`), `localhost` par défaut |
| `--email=moi@exemple.com` | `-Email` | `SB_EMAIL` | Courriel pour Let's Encrypt |
| `--tz=America/Toronto` | `-TimeZone` | `SB_TZ` | Fuseau horaire, celui de la machine par défaut |
| `--no-wait` | `-NoWait` | | Ne pas attendre que SwitchBoard soit prêt |
| `--prepare-only` | `-PrepareOnly` | | Préparer et vérifier la configuration (`.env`, jeton, domaine, mode) sans rien démarrer |
| | `-NoFirewall` | | Ne pas ajouter la règle du pare-feu Windows |
| | | `SB_GITHUB_TOKEN` | Jeton GitHub (facultatif : copie privée du dépôt, ou limite de requêtes plus haute) |
| | | `SB_SMTP_*` | Réglages du courriel, copiés dans `.env` |

Exemple : `./up.sh -y --host=sb.mondomaine.com --email=moi@mondomaine.com`.
`./up.sh --help` liste tout.

<a id="everyday-commands"></a>
### Commandes courantes

Comme `COMPOSE_FILE` est enregistré dans `.env`, les commandes `docker compose` simples fonctionnent
depuis le dossier `Docker` dès que `./up.sh` a été lancé une fois :

```bash
docker compose ps                # état des conteneurs
docker compose logs -f php       # suivre le journal de SwitchBoard (premier démarrage, erreurs)
docker logs sb_letsencrypt       # demandes de certificats
docker compose restart           # tout redémarrer
docker compose down              # arrêter (les données sont gardées)
./up.sh                          # redémarrer / appliquer un changement de .env / après un git pull
```

Un simple `docker compose up` avant le premier `./up.sh` utilise `docker-compose.yml`. Ce fichier est
volontairement un garde-fou : il refuse de démarrer et renvoie vers `./up.sh`, ce qui évite de démarrer
la mauvaise configuration par erreur. Si vous préférez lancer Compose vous-même dès le début, utilisez
toujours `--build` :
- mode autonome : `docker compose -f docker-compose.standalone.yml up -d --build` ;
- mode reverse-proxy : `docker compose -f docker-compose.reverse-proxy.yml up -d --build`.

<a id="lan-access"></a>
## Accès depuis le réseau local

En mode autonome avec `VIRTUAL_HOST=localhost`, SwitchBoard répond aussi à l'adresse et au nom de la
machine sur le réseau local. Depuis n'importe quel téléphone ou ordinateur du même réseau, vous pouvez
ouvrir :
- `https://192.168.x.y` ;
- `https://<nom-de-la-machine>.local`, qui fonctionne sur la plupart des réseaux.

`./up.sh` affiche ces adresses à la fin. Le certificat auto-signé les couvre, et il est recalculé à
chaque `./up.sh` (par exemple après un changement d'adresse) : les navigateurs affichent leur
avertissement une fois.

Pare-feu :

- **Linux, Raspberry Pi** : rien à faire. Docker ouvre lui-même les ports publiés 80/443 ; il passe
  même par-dessus les règles de `ufw`. Un pare-feu dans votre routeur ou à l'extérieur de la machine
  peut quand même les bloquer.
- **Windows** : lancez une fois `up.cmd` en administrateur ; il ajoute la règle pour les réseaux
  **privés**. Si Windows classe votre réseau comme *Public*, `up.ps1` le signale : réglez-le à *Privé*
  dans *Paramètres* → *Réseau et Internet* → votre réseau.
  - Si vous avez un jour cliqué « Autoriser » à l'invite du pare-feu Windows pour Docker Desktop sur
    un réseau public, Windows a ajouté une règle qui laisse entrer tous les ports Docker sur n'importe
    quel réseau public (café, hôtel…).
  - Vous pouvez la retirer dans *Pare-feu Windows Defender* → *Règles de trafic entrant* →
    `com.docker.backend.exe` (Public).

Pour joindre SwitchBoard depuis Internet, utilisez un vrai domaine (`--host=sb.mondomaine.com`) avec
les ports 80/443 redirigés vers cette machine. Let's Encrypt fournit alors un certificat valide.

<a id="configuration"></a>
## Référence de configuration

<a id="containers"></a>
### Les conteneurs

- **sb_letsencrypt** (mode autonome) : obtient et renouvelle les certificats TLS.
- **sb_nginx-proxy** (mode autonome) : reverse-proxy sur les ports 80 et 443. Il génère le certificat
  auto-signé pour `localhost` et les adresses du réseau local.
- **sb_web** (`sb_web_server` en mode reverse-proxy) : le serveur web nginx.
- **sb_php-fpm** : là où SwitchBoard tourne. Au premier démarrage, il télécharge SwitchBoard depuis
  GitHub (`prepare_switchboard.sh`), installe ses dépendances, configure la base et le courriel, et
  exécute les tâches planifiées (cron).
- **sb_mariadb** : la base de données MariaDB.

Tous sont sur le réseau Docker externe `web`, que `./up.sh` crée (`docker network create web`).

<a id="env-file"></a>
### Le fichier .env

`.env` contient la configuration du serveur. `./up.sh` le crée à la première utilisation : pas besoin
de le modifier pour une première installation. Il est ignoré par git, donc un `git pull` ne l'écrase
jamais. **Sauvegardez-le** : sans sa clé `APP_ENCRYPTION_KEY`, les secrets enregistrés dans la base sont
perdus.

Pour le créer vous-même, copiez `.env_sample` vers `.env` et remplacez au moins `MYSQL_ROOT_PASSWORD`
et `MYSQL_PASSWORD` par vos propres valeurs sûres. N'utilisez jamais celles de l'exemple.

- **COMPOSE_PROJECT_NAME** : nom du projet Docker Compose ; inutile de le changer.
- **COMPOSE_FILE** : le [mode](#modes), écrit par `./up.sh`.
- **DEFAULT_EMAIL** : utilisé pour les certificats. Il est visible dans les certificats de votre
  domaine.
- **VIRTUAL_HOST** : `localhost` pour une installation locale ou sur le réseau local, ou votre domaine
  ou sous-domaine en production (`VIRTUAL_HOST=sb.mondomaine.com`). L'un ou l'autre, pas les deux. Avec
  un domaine, le conteneur letsencrypt crée les certificats.
- **TZ** : fuseau horaire, pris dans la deuxième colonne de la
  [liste de la base tz](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones)
  (ex. `America/Toronto`).
- **MYSQL_ROOT_PASSWORD, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD** : réglages de la base. Utilisez
  des mots de passe sûrs (`./up.sh` en génère d'aléatoires). Vous pouvez aussi changer
  `MYSQL_DATABASE` et `MYSQL_USER` pour vos propres valeurs.
- **GITHUB_TOKEN** : facultatif. Laissez-le vide pour le dépôt public. Un jeton en lecture seule lève
  la limite de GitHub pour les requêtes sans jeton lors de la vérification des mises à jour, ou permet
  de suivre une copie privée du dépôt. Un jeton que GitHub refuse est ignoré (la vérification se fait
  sans lui, et la console d'administration le dit). Voir [Avant de commencer](#before-you-start).
- **APP_ENCRYPTION_KEY** : chiffre les secrets voip.ms, SwitchBoard Chat et MAS enregistrés dans la
  base (et le courriel OAuth). `./up.sh` / `up.cmd` la vérifient à chaque lancement : vide, ils en
  génèrent une ; mal formée (autre chose que 32 octets en base64, 44 caractères), ils s'arrêtent, car
  SwitchBoard l'ignorerait. Les secrets enregistrés en clair avant qu'elle existe sont alors chiffrés.
  Pour en créer une vous-même : `openssl rand -base64 32`. Sauvegardez-la avec vos sauvegardes de la
  base : la perdre rend les secrets chiffrés irrécupérables.
- **CRON_WEB_KEY** : seulement pour déclencher les scripts de traitement par HTTP au lieu du cron
  intégré au conteneur (`cronjobs`). Sinon, laissez vide.
- **SB_SBCHAT_CA_BUNDLE / SB_ALLOW_INSECURE_TLS** : seulement pour un serveur SwitchBoard Chat / MAS
  avec un certificat **auto-signé** ; voir [Certificat SBChat auto-signé](#sbchat-certificate).
- **SB_SMTP_HOST / SB_SMTP_PORT / SB_SMTP_USER / SB_SMTP_PASSWORD / SB_SMTP_FROM / SB_SMTP_TLS** :
  courriel avec un simple mot de passe ; voir [Courriel](#mail).
- **SB_SMTP_OAUTH_PROVIDER / SB_SMTP_OAUTH_CLIENT_ID / SB_SMTP_OAUTH_CLIENT_SECRET /
  SB_SMTP_OAUTH_TENANT** : courriel avec OAuth2 (Microsoft 365 ou Google) ; voir [Courriel](#mail) et
  [MAIL-OAUTH-SETUP.md](../MAIL-OAUTH-SETUP.md).
- **SB_DMS_\*** : adresses de surveillance ; voir [Surveillance](#monitoring).

Après un changement de `.env`, relancez `./up.sh`.

<a id="nginx-conf"></a>
### Le fichier nginx.conf

`nginx/nginx.conf` est la configuration du serveur web `sb_web`. Il protège le conteneur php-fpm, dans
les deux modes. Comme `.env`, il est ignoré par git et créé par `./up.sh` à partir de
`nginx/nginx.conf.sample` à la première utilisation (ou créez-le vous-même :
`cp nginx/nginx.conf.sample nginx/nginx.conf`). Son `server_name` doit correspondre à `VIRTUAL_HOST`.

Installations existantes : `nginx.conf` n'est jamais remplacé, il n'a donc pas les nouvelles lignes
`resolver` de l'exemple. Ces lignes font relire à nginx l'adresse du conteneur php, ce qui évite des
erreurs 502 après une mise à jour de php seul. `./up.sh` redémarre déjà le conteneur web quand php est
recréé. Si vous recréez aussi php à la main, copiez les lignes `resolver` et `set $php_fpm` de
`nginx.conf.sample`.

<a id="mail"></a>
### Courriel (SMTP)

SwitchBoard envoie par courriel les erreurs et avertissements qui touchent l'acheminement des appels :
ça vaut la peine de le faire fonctionner. Si le courriel est brisé, des problèmes peuvent passer
inaperçus.

L'assistant de `./up.sh` demande l'hôte, le port, l'utilisateur et le mot de passe de votre relais
SMTP, et les écrit dans `.env` (`SB_SMTP_*`). Le conteneur php configure msmtp à partir de ces
variables **au démarrage** (`generate_mail_config.sh`). Pour changer un mot de passe de courriel,
modifiez `.env` et relancez `./up.sh` ; aucune reconstruction n'est nécessaire. Vous pouvez passer le
courriel dans l'assistant et le configurer plus tard. SwitchBoard démarre quand même, mais les avis
d'erreur ne partiront pas.

**Simple mot de passe** (le choix par défaut de l'assistant) :
- Un compte Google avec un [mot de passe d'application](https://support.google.com/accounts/answer/185833)
  est recommandé, pas votre mot de passe habituel.
- Utilisez un compte qui a déjà servi : un compte tout neuf risque davantage d'être classé comme
  pourriel.
- Google permet 500 courriels par jour, largement assez pour les avis.
- D'autres fournisseurs peuvent fonctionner, mais attendez-vous à des difficultés : Yandex a déjà
  classé des comptes neufs comme pourriel.
- **Microsoft 365 abandonne les mots de passe SMTP** : utilisez OAuth pour lui.

**OAuth2** (Microsoft 365 ou Google) :
1. Passez le courriel dans l'assistant (option 3).
2. Dans SwitchBoard, allez à *Console d'administration → Courriel OAuth* et choisissez un fournisseur.
3. Suivez les étapes affichées pour inscrire une application dans la console du fournisseur. C'est une
   étape unique, en dehors de SwitchBoard : Azure AD pour Microsoft, Google Cloud Console pour Google.
   Voir [MAIL-OAUTH-SETUP.md](../MAIL-OAUTH-SETUP.md) pour une version plus détaillée.
4. Collez l'identifiant client (et le secret pour Google), puis cliquez sur *Autoriser*.
5. Lancez une fois `docker compose restart php` ensuite, pour que le courriel passe en mode OAuth.

L'option 2 de l'assistant permet plutôt de mettre les variables `SB_SMTP_OAUTH_*` directement dans
`.env`, ce qui est utile pour une installation scriptée.

**Testez-le** dans *Console d'administration → Courriel de test*. Un vrai courriel est envoyé, et
l'erreur SMTP réelle s'affiche en cas d'échec (mauvais mot de passe, mauvais port…).

<a id="sbchat-certificate"></a>
### Certificat SBChat auto-signé

Passez cette section si votre serveur SwitchBoard Chat a un certificat normal (Let's Encrypt, etc.).
Pour la configuration complète de SBChat/MAS (jeton d'amorçage, agents, crochet SMS voip.ms…), voir
[SBCHAT-SETUP.md](../SBCHAT-SETUP.md). Sans Docker, voir plutôt [../README.md](../README.md).

Avec un certificat auto-signé, SwitchBoard refuse de se connecter (erreur cURL #60) tant que vous ne
lui dites pas de faire confiance à ce certificat. La méthode recommandée garde la vérification TLS
active :

1. Enregistrez le certificat dans le dossier `certs/`. Il est monté en lecture seule dans le conteneur
   php, à `/etc/ssl/sbchat`. Depuis le dossier `Docker/` :

       echo | openssl s_client -connect sbchat.mondomaine.ca:3443 2>/dev/null | openssl x509 > certs/sbchat-cert.pem

2. Dans `.env`, donnez le chemin **dans le conteneur**, pas celui de la machine. Un chemin de la
   machine donne l'erreur cURL #77 :

       SB_SBCHAT_CA_BUNDLE=/etc/ssl/sbchat/sbchat-cert.pem

3. `docker compose down && ./up.sh`

En dernier recours, `SB_ALLOW_INSECURE_TLS=1` désactive complètement la vérification TLS pour la
connexion SBChat/MAS. Les jetons SBChat/MAS passent par cette connexion : ils peuvent alors être
interceptés. Préférez le certificat.

<a id="monitoring"></a>
### Surveillance (dead-man's-switch)

Les courriels d'erreur de SwitchBoard dépendent de SwitchBoard lui-même : ils n'aident pas si le
conteneur gèle ou si la machine tombe.

1. Dans SwitchBoard, allez à *Console d'administration → Surveillance (dead-man's-switch)*.
2. Entrez des adresses de « ping » d'un service de battement de cœur gratuit comme
   [healthchecks.io](https://healthchecks.io).

SwitchBoard appelle chaque adresse après avoir vérifié un sous-système essentiel : mise à jour des
groupes d'appel, mise à jour SBChat, livraison du courriel, base de données et disque. C'est le
service **externe** qui vous alerte quand un ping n'arrive pas.

Voir [DEADMANSWITCH-SETUP.md](../DEADMANSWITCH-SETUP.md) pour la marche à suivre complète, y compris
les canaux d'avis (SMS, Telegram, notification push…).

<a id="reverse-proxy"></a>
## Utiliser un reverse-proxy externe

Lancez `./up.sh` et choisissez l'option 2 (reverse-proxy externe), ou `./up.sh -y --mode=reverse-proxy`.
Il utilise ensuite toujours `docker-compose.reverse-proxy.yml`.

- Le réseau `web` doit exister (`./up.sh` le crée) et votre reverse-proxy doit y être branché.
- Dirigez votre reverse-proxy vers le **port 80 du conteneur `sb_web_server`**.
- Laissez `nginx/nginx.conf` en place : il protège le conteneur php-fpm même dans ce mode.

**Nginx Proxy Manager** : exemple de `docker-compose.yml`. Les quatre dernières lignes le branchent sur
le réseau `web` pour qu'il puisse joindre SwitchBoard.

```yaml
services:
  app:
    image: 'jc21/nginx-proxy-manager:latest'
    restart: unless-stopped
    ports:
      - '80:80'
      - '81:81'
      - '443:443'
    volumes:
      - ./data:/data
      - ./letsencrypt:/etc/letsencrypt
      - ./nginx.conf:/etc/nginx/nginx.conf
    networks:
      - web
networks:
  web:
    name: web
    external: true
```

**nginx seul** : ajoutez le sous-domaine au bloc `server` du port 80, puis ajoutez un bloc 443. Dans
cet exemple, les deux domaines partagent un certificat :

```nginx
server {
    listen 80;
    server_name matrix.mondomaine.ca sb.mondomaine.ca;
    ...
}
server {
    listen 443 ssl;
    server_name sb.mondomaine.ca;
    ssl_certificate /etc/letsencrypt/live/matrix.mondomaine.ca/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/matrix.mondomaine.ca/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    location / {
        proxy_pass http://sb_web_server:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket, au besoin
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

Les certificats doivent exister avant que nginx démarre avec cette configuration, sinon il plante.
Pour les créer d'abord, avec un conteneur certbot :

1. Renommez votre `nginx.conf` et remplacez-le temporairement par celui-ci :

```nginx
server {
    listen 80;
    server_name sb.mondomaine.ca matrix.mondomaine.ca;

    # Validation ACME pour Let's Encrypt
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    # Temporaire : une page simple pour tout le reste
    location / {
        return 200 "Certbot setup in progress\n";
        add_header Content-Type text/plain;
    }
}
```

2. Démarrez les conteneurs, attendez la création des certificats et vérifiez que le proxy ne plante
   pas.
3. Arrêtez tout et remettez votre vrai `nginx.conf`.
4. Redémarrez. Les certificats sont là et le proxy est stable.

<a id="troubleshooting"></a>
## Dépannage

| Symptôme | Quoi faire |
|---|---|
| « Docker n'est pas installé » / « Docker ne répond pas » | Installez Docker pour [votre plateforme](#contents) et démarrez-le (`sudo systemctl start docker`, ou ouvrez Docker Desktop). |
| « permission denied … docker.sock » | Ajoutez-vous au groupe `docker` et reconnectez-vous, ou utilisez `sudo ./up.sh`. |
| « GitHub refuse ce jeton … il est ignoré » | Le jeton de `.env` est inconnu, expiré ou révoqué. Le dépôt étant public, l'installation continue sans lui : retirez-le (`GITHUB_TOKEN=`) ou remplacez-le. |
| « Le dépôt SwitchBoard n'est pas public : un jeton GitHub est nécessaire » | Vous installez depuis une copie privée du dépôt. Créez un jeton (voir [Avant de commencer](#before-you-start)) et mettez-le dans `.env` (`GITHUB_TOKEN=...`) ou dans `SB_GITHUB_TOKEN`. |
| « Impossible de joindre GitHub » | La machine n'a pas accès à Internet, ou un pare-feu bloque github.com. L'installation en a besoin pour télécharger SwitchBoard. |
| « le port 80 (ou 443) est déjà utilisé » | Un autre serveur web tourne. Arrêtez-le, ou utilisez le [mode reverse-proxy](#reverse-proxy). |
| Système 32 bits détecté (Raspberry Pi) | Réinstallez avec Raspberry Pi OS **64 bits** ; voir [Raspberry Pi](#install-raspberry-pi). |
| Windows : virtualisation désactivée | Activez VT-x / AMD-V dans le BIOS, puis faites un arrêt complet ; voir [Windows](#install-windows). |
| Windows : « Switch to Linux containers » | Clic droit sur l'icône Docker → *Switch to Linux containers*. |
| Les autres appareils n'ouvrent pas `https://192.168.x.y` | Vérifiez le pare-feu ; voir [Accès depuis le réseau local](#lan-access). Assurez-vous que les deux appareils sont sur le même réseau. |
| 502 Bad Gateway après une mise à jour | Relancez `./up.sh` (il redémarre le conteneur web), et voir [nginx.conf](#nginx-conf). |
| `sb_nginx-proxy` redémarre sans arrêt | Consultez `docker logs sb_nginx-proxy` et `docker logs sb_letsencrypt`. De mauvais certificats l'empêchent de démarrer. Puis `docker compose down` et `./up.sh`. |
| Windows : erreur 500 en créant le compte admin, ou « COMPOSE_FILE (dans .env) n'inclut pas docker-compose.windows.yml » | `COMPOSE_FILE` dans `.env` ne contient pas `docker-compose.windows.yml` (`.env` écrit à la main ou copié depuis Linux) : la base est alors dans le dossier Windows `db_data`, où MariaDB n'arrive pas à modifier ses tables. `up.cmd` ajoute ce fichier tout seul tant qu'aucune base n'existe dans `db_data`. Si l'installation ne contient encore rien à garder : `docker compose down`, renommez `db_data` (ex. `db_data.ancien`), puis relancez `up.cmd`. |
| « des secrets enregistrés ont été chiffrés avec une ancienne clé » | `APP_ENCRYPTION_KEY` a été retirée de `.env` alors que des secrets étaient déjà chiffrés : la nouvelle clé générée ne peut pas les lire. Remettez l'ancienne clé (sauvegarde de `.env`) et relancez l'installateur, ou ressaisissez ces secrets dans SwitchBoard (voip.ms, SB Chat / MAS, courriel). |
| « APP_ENCRYPTION_KEY dans .env n'est pas une clé valide » | La clé n'est pas 32 octets en base64 (44 caractères). Remettez la clé d'origine, ou videz la ligne (`APP_ENCRYPTION_KEY=`) pour qu'une clé soit générée. |
| Toute autre erreur (erreur 500, page blanche…) | Reproduisez-la, puis lancez le [rapport de diagnostic](#diagnostic) et envoyez-le. |
| Le premier démarrage semble bloqué | Suivez `docker compose logs -f php`. Le premier démarrage télécharge SwitchBoard et ses dépendances (quelques minutes, plus long sur un Pi). Après 15 minutes sans progrès, l'installateur s'arrête et affiche les dernières lignes de ce journal. |
| « Le fichier .env est introuvable, mais une base de données SwitchBoard existe déjà » | `.env` a été supprimé ou déplacé, mais la base est toujours là. Un nouveau `.env` aurait d'autres mots de passe et une autre clé de chiffrement : l'installateur refuse d'en créer un. Remettez votre sauvegarde de `.env`. Pour tout recommencer en **effaçant les données** : `docker compose down -v` (Windows) ou supprimez `db_data` et `web` (Linux). |
| « La base de données refuse les identifiants de .env » | `MYSQL_USER`, `MYSQL_PASSWORD` ou `MYSQL_DATABASE` ont été changés dans `.env` après l'installation. Ils ne servent qu'à la création de la base : remettez les anciennes valeurs. |
| « Une autre installation de SwitchBoard existe déjà » | Une autre copie de SwitchBoard (autre dossier) tourne sur cette machine. Une seule peut fonctionner à la fois : utilisez ce dossier-là, ou arrêtez-la avec `docker compose down` dans ce dossier. |
| « VIRTUAL_HOST … n'est pas un nom de domaine valide » / « est une adresse IP » | Utilisez un nom de domaine (`sb.mondomaine.com`) ou `localhost`. Pour un accès par adresse IP, utilisez `localhost` : SwitchBoard répond aussi à l'adresse IP de la machine. `https://` et un `/` final sont retirés automatiquement. |
| « La connexion sécurisée à GitHub échoue » / erreur de certificat | Vérifiez la date et l'heure de la machine, ou un proxy/antivirus qui intercepte le HTTPS. |
| « Le disque est plein » / « Docker Hub limite le nombre de téléchargements » | Libérez de l'espace (`docker system prune -a`), ou attendez une heure (ou `docker login`) pour la limite de Docker Hub. Relancez ensuite l'installateur. |
| « Docker s'est arrêté pendant l'installation » | Redémarrez Docker et relancez l'installateur : il reprend où il en était. Sous Windows, `up.cmd` redémarre lui-même Docker Desktop. |
| « Le conteneur php manque de mémoire » | Donnez au moins 2 Go de mémoire à Docker (Docker Desktop : *Settings* → *Resources*), ou utilisez un Pi avec 2 Go ou plus. |
| Windows : « Docker Desktop is unable to start » | Souvent causé par une mise à jour automatique de WSL. `up.cmd` attend la fin de la mise à jour et redémarre Docker Desktop. Si l'erreur persiste : quittez Docker Desktop, lancez `wsl --update` puis `wsl --shutdown` dans PowerShell, rouvrez Docker Desktop, ou redémarrez l'ordinateur. |
| `./up.sh: Permission denied` | La permission d'exécution a été perdue (ZIP, copie depuis Windows). Lancez `bash up.sh`, ou `chmod +x up.sh`. |
| `/bin/bash^M: bad interpreter` | `up.sh` a des fins de ligne Windows (copié depuis Windows, ou Git avec `core.autocrlf=true`). Corrigez avec `sed -i 's/\r$//' up.sh`, ou clonez à nouveau depuis Linux. |
| Windows : « Fichiers introuvables : extrayez d'abord le ZIP » | `up.cmd` a été ouvert à l'intérieur du ZIP. Clic droit sur le ZIP → *Extraire tout*, puis lancez `up.cmd` depuis le dossier `Docker` extrait. |
| Windows : « ce dossier est synchronisé par OneDrive » | OneDrive peut verrouiller ou vider des fichiers pendant la construction. Si l'installation échoue, déplacez le dossier SwitchBoard hors de OneDrive (ex. : `C:\SwitchBoard`). |
| « up.sh a besoin de bash » | Installez bash (`apk add bash` sous Alpine). `sh up.sh` fonctionne aussi : il passe lui-même à bash. |
| « … n'est pas modifiable par … » | `.env` a été créé avec `sudo`. Lancez `sudo chown $USER .env nginx/nginx.conf`, ou continuez avec `sudo ./up.sh`. |

<a id="diagnostic"></a>
## Rapport de diagnostic

En cas de problème (page d'erreur, erreur 500, conteneur qui redémarre sans arrêt…), le script de
diagnostic rassemble dans un seul fichier texte tout ce qu'il faut pour trouver la cause. Collez ce
fichier dans une conversation d'aide (Claude, courriel…).

1. **Reproduisez d'abord le problème** (par exemple, réessayez de créer le compte admin), pour que les
   journaux contiennent l'erreur.
2. Lancez le script depuis le dossier `Docker` :
   - **Windows :** double-cliquez sur **`diagnostic.cmd`**. Le rapport est aussi copié dans le
     presse-papiers.
   - **Linux, Raspberry Pi, macOS :** `./diagnostic.sh` (ou `sudo ./diagnostic.sh`).
3. Collez le contenu de `switchboard-diagnostic-AAAAMMJJ-HHMM.txt` dans la conversation.

Ce que contient le rapport :
- le système, les versions de Docker et de WSL ;
- l'état de chaque conteneur (redémarrages, manque de mémoire, code de sortie) ;
- les réglages de `.env`, valeurs secrètes masquées ;
- les vérifications faites dans le conteneur php : version et modules de PHP, droits d'écriture,
  connexion à la base faite comme SwitchBoard la fait, table des comptes ;
- des tests HTTP ;
- les dernières erreurs PHP et les journaux de SwitchBoard, puis les journaux de chaque conteneur.

Les mots de passe, jetons et clés sont masqués ; les tests d'installation vérifient qu'aucun n'apparaît
dans le rapport. Le script ne modifie rien.

<a id="install-tests"></a>
## Tests d'installation

`tests/install/` contient des tests qui installent réellement SwitchBoard sur Debian, Ubuntu, Fedora,
Rocky, Alpine, Arch et Raspberry Pi OS (ARM64), sur un vrai réseau local, et sous Windows. Relancez-les
après tout changement important aux fichiers ou scripts Docker ; voir
[tests/install/README.md](tests/install/README.md).

<a id="development"></a>
## Développement avec Docker

Après une installation réussie, le dossier `web` dans `Docker/` contient le code de SwitchBoard. Pour
le modifier depuis la machine et pousser vos changements avec git (Linux) :

```bash
# Créer un groupe www-data avec le gid 33
sudo groupadd -g 33 www-data
# Si la commande échoue avec « GID '33' already exists », trouvez le nom du groupe existant
# (les caractères avant le premier « : ») et utilisez-le au lieu de www-data ci-dessous :
sudo getent group 33

sudo chown -R www-data:www-data ./web
sudo usermod -aG www-data mon_utilisateur     # ajouter votre utilisateur au groupe
sudo chmod -R g+w ./web
```

Vous pouvez maintenant modifier le dossier `web` et y utiliser git. Ne faites jamais ça sur un serveur
en production ou accessible depuis Internet.
