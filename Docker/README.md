# SwitchBoard with Docker

**English** · [Français](README.fr.md)

The Docker version of SwitchBoard is a complete, self-contained server. It runs the same way on a VPS,
a home server, a Linux PC, a Windows PC or a Raspberry Pi. One script installs and starts everything:
`./up.sh` on Linux and Raspberry Pi, `up.cmd` on Windows.

<a id="contents"></a>
## Contents

- [Before you start](#before-you-start)
- [Quick start](#quick-start)
- [Install on Linux](#install-linux)
  - [Debian, Raspberry Pi OS](#linux-debian)
  - [Ubuntu, Linux Mint](#linux-ubuntu)
  - [Fedora](#linux-fedora)
  - [Rocky Linux, AlmaLinux, RHEL](#linux-rhel)
  - [Arch Linux, Manjaro](#linux-arch)
  - [Alpine Linux](#linux-alpine)
- [Install on Windows](#install-windows)
- [Install on a Raspberry Pi](#install-raspberry-pi)
- [macOS](#install-macos)
- [Running up.sh / up.ps1](#running-up)
  - [The two modes: standalone or behind a reverse proxy](#modes)
  - [Fully automatic install (no questions)](#non-interactive)
  - [Everyday commands](#everyday-commands)
- [Access from your local network](#lan-access)
- [Configuration reference](#configuration)
  - [The containers](#containers)
  - [The .env file](#env-file)
  - [The nginx.conf file](#nginx-conf)
  - [Mail (SMTP)](#mail)
  - [Self-signed SBChat certificate](#sbchat-certificate)
  - [Monitoring (dead-man's-switch)](#monitoring)
- [Using an external reverse proxy](#reverse-proxy)
- [Troubleshooting](#troubleshooting)
- [Diagnostic report](#diagnostic)
- [Install tests](#install-tests)
- [Development with Docker](#development)

<a id="before-you-start"></a>
## Before you start

You need:

1. **Docker** with the **Compose** plugin (Docker Desktop on Windows). The section for your platform
   below shows how to install it. The SwitchBoard scripts never install Docker themselves. They check
   for it and explain what to do if it's missing.
2. **Internet access to GitHub.** The SwitchBoard repository is public: the server downloads it
   without a token. A GitHub token is **optional**. It is only needed to follow a private copy of the
   repository, or to lift GitHub's limit on requests without a token (60 per hour per IP address) for
   the update check. To create one: profile picture → *Settings* → *Developer settings* →
   *Personal access tokens* → *Fine-grained tokens*, with read access to **Contents** and **Metadata**.
   The installer checks access with GitHub before building anything.
3. **Ports 80 and 443 free** on the machine, for the [standalone mode](#modes). If another web server
   already uses them, use the [reverse-proxy mode](#reverse-proxy) instead.
4. A **64-bit** system. x86-64 (PC, VPS) or ARM64 (Raspberry Pi 3/4/5, Zero 2 W) both work.

<a id="quick-start"></a>
## Quick start

After installing Docker for your platform:

```bash
git clone https://github.com/Switchboarders/SwitchBoard.git
cd SwitchBoard/Docker
./up.sh
```

On Windows, double-click **`up.cmd`** in the `Docker` folder instead. When it's done, the script prints
the addresses to open, for example `https://localhost` and `https://192.168.1.20`. Create the first
admin account on the page that opens.

The first start takes a few minutes, or 10 to 20 minutes on a Raspberry Pi. During that time
SwitchBoard is downloaded and its dependencies installed. The script waits and tells you when it's
ready.

<a id="install-linux"></a>
## Install on Linux

On every distribution: install Docker, start it, let your user run it, then
[clone and run `./up.sh`](#quick-start). The commands below are the ones used by the
[install tests](#install-tests), which install SwitchBoard for real on each of these distributions.

After adding yourself to the `docker` group, log out and back in (or reboot) so that `docker` works
without `sudo`. You can also run `sudo ./up.sh`.

<a id="linux-debian"></a>
### Debian, Raspberry Pi OS

```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```

The official script installs Docker Engine and the Compose plugin, then starts the service.

<a id="linux-ubuntu"></a>
### Ubuntu, Linux Mint

Either the official script ([as for Debian](#linux-debian)), or Ubuntu's own packages:

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

On Fedora 40 and older, the repository command is
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

Do not use Podman, which these systems offer by default; SwitchBoard needs Docker.

<a id="linux-arch"></a>
### Arch Linux, Manjaro

```bash
sudo pacman -Syu --needed docker docker-compose git
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Arch's `docker` package has no `buildx` plugin. SwitchBoard builds fine without it, and Docker shows a
"legacy builder" warning that you can ignore.

<a id="linux-alpine"></a>
### Alpine Linux

As root (Alpine often has no `sudo`), replacing `my_user` with your user:

```bash
apk add bash git docker docker-cli-compose
rc-update add docker default && service docker start
addgroup my_user docker
```

`up.sh` needs `bash`, which Alpine doesn't install by default.

<a id="install-windows"></a>
## Install on Windows

Windows 10 or 11, 64-bit. Docker Desktop runs SwitchBoard's Linux containers in WSL 2.

1. **Turn on virtualization** (Intel VT-x or AMD-V) in the BIOS/UEFI if it isn't already. To check:
   *Task Manager* → *Performance* → *CPU*, look for "Virtualization: Enabled". To turn it on, restart,
   enter the BIOS (often F2, F10, Del or Esc at startup), enable "Virtualization Technology" and save.
   Some laptops (HP among them) only apply this change after a **full shutdown**, not a restart.
2. **Install [Docker Desktop](https://www.docker.com/products/docker-desktop)**, or run
   `winget install Docker.DockerDesktop`. Open it once, accept the terms (no account needed) and wait
   for "Engine running". Accept if it asks to install or update WSL.
3. **Get SwitchBoard.** Either with [Git for Windows](https://git-scm.com/download/win), using the same
   `git clone` as in the [quick start](#quick-start), or by downloading the ZIP from GitHub (*Code* →
   *Download ZIP* on the `main` branch) and extracting it.
4. **Double-click `up.cmd`** in the `Docker` folder. It asks the same questions as `up.sh`. To also open
   the firewall for [LAN access](#lan-access), right-click `up.cmd` → *Run as administrator* once.

What `up.cmd` / `up.ps1` does for you on Windows:

- It finds Docker Desktop whether it was installed for all users or only for yours, and starts it if
  needed.
- It stops right away with instructions if virtualization is off in the BIOS, or if Docker Desktop is
  set to Windows containers.
- It converts the Linux scripts back to LF line endings if Git or an editor changed them to CRLF.
- It keeps the database and SwitchBoard's code in Docker volumes rather than in the `db_data` and `web`
  folders, through `docker-compose.windows.yml`, added automatically. NTFS is case-insensitive and
  very slow from inside containers.
- It adds a firewall rule "SwitchBoard HTTP/HTTPS" for ports 80/443 on **private** networks when run as
  administrator.
- It works over SSH too, for remote installs. There it uses Docker without the Windows Credential
  Manager, which an SSH session can't open.

In PowerShell, `.\up.ps1 -NonInteractive` installs without any question. The options are `-Mode`,
`-HostName`, `-Email`, `-TimeZone`, `-NoWait` and `-NoFirewall`; see
[Fully automatic install](#non-interactive).

<a id="install-raspberry-pi"></a>
## Install on a Raspberry Pi

SwitchBoard runs on a Raspberry Pi 3, 4, 5 or Zero 2 W with a **64-bit** system. All its images exist
for ARM64, but MariaDB has no 32-bit ARM version, so a 32-bit Raspberry Pi OS won't work, and `./up.sh`
says so. A Pi 4 or 5 with 2 GB of RAM or more is recommended.

1. With [Raspberry Pi Imager](https://www.raspberrypi.com/software/), install **Raspberry Pi OS Lite
   (64-bit)**. In its settings, enable SSH, set a user, Wi-Fi if needed, and a host name such as
   `switchboard`.
2. Connect to the Pi (`ssh user@switchboard.local`) and install Docker [as on Debian](#linux-debian).
3. Log out and back in, then follow the [quick start](#quick-start).
4. Open `https://switchboard.local` or `https://<the Pi's address>` from any device on the network.

The first start builds the PHP image on the Pi, which takes 10 to 20 minutes on a Pi 4. A database
wears out an SD card: prefer a USB SSD (the Pi 4/5 can boot from it), and back up `.env` and the
database regularly. Give the Pi a fixed address in your router so its address doesn't change.

<a id="install-macos"></a>
## macOS

`up.sh` also runs on macOS with [Docker Desktop](https://www.docker.com/products/docker-desktop)
installed and started, but macOS is not covered by the [install tests](#install-tests).

<a id="running-up"></a>
## Running up.sh / up.ps1

`./up.sh` (or `up.cmd` on Windows) is the only entry point you need, for the first install and
afterwards. On every run it:

1. checks that Docker and Compose are installed and running, and explains what to do otherwise;
2. on the first run, creates `.env` from `.env_sample`:
   - generates random database passwords and an `APP_ENCRYPTION_KEY`, and takes the host's time zone;
   - asks a few questions: mode, domain, certificate email, mail settings.
   Every question can be skipped with Enter and filled in later by editing `.env`.
3. checks access to the repository with GitHub, as `git clone` would:
   - **No token:** the repository is public, nothing to do. If it isn't (a private copy), the
     installer says so and asks for a token, or stops with the reason when run without questions.
   - **A token that GitHub refuses** (unknown, expired, revoked): the repository is public, so the
     installer says so, ignores it and goes on. Remove or replace it in `.env`.
   - **SwitchBoard already installed:** a token problem only gives a warning, so a restart is never
     blocked.
   - A token given in `SB_GITHUB_TOKEN` replaces the one in `.env`. A valid token is saved in
     `.env`; a refused one never is.
4. creates `nginx/nginx.conf` from `nginx.conf.sample` if it doesn't exist;
5. creates the `web` Docker network if needed, and checks that ports 80/443 are free (standalone mode);
6. builds the images (with one automatic retry) and starts the containers;
7. waits until SwitchBoard answers, and prints the addresses to open. The wait follows the progress of
   the first start, so a slow machine isn't cut off.

Running `./up.sh` again is safe. It keeps your passwords and settings, and it's also how you apply a
change to `.env` or update to new Docker files after a `git pull`.

<a id="modes"></a>
### The two modes: standalone or behind a reverse proxy

- **Standalone** (default): SwitchBoard runs its own reverse proxy (`sb_nginx-proxy`) and gets its TLS
  certificates from Let's Encrypt (`sb_letsencrypt`). It needs ports 80 and 443. With
  `VIRTUAL_HOST=localhost`, it uses a self-signed certificate and is [reachable from the LAN](#lan-access).
- **Reverse proxy**: you already have a reverse proxy (nginx, Nginx Proxy Manager, Traefik…) on ports
  80/443. SwitchBoard only starts `sb_web_server`, `sb_php-fpm` and `sb_mariadb`; see
  [Using an external reverse proxy](#reverse-proxy).

The first run asks which mode you want and remembers it in `.env` (`COMPOSE_FILE=...`). To change
mode, delete that line from `.env` and run `./up.sh` again.

<a id="non-interactive"></a>
### Fully automatic install (no questions)

`./up.sh --non-interactive` (or `-y`) installs and starts everything with safe defaults:
- random database passwords and `APP_ENCRYPTION_KEY`;
- the host's time zone;
- standalone mode on `localhost`.

When there is no terminal (for example, run from another script), it never asks a question either.

| up.sh (Linux, Pi, macOS) | up.ps1 (Windows) | Environment variable | Meaning |
|---|---|---|---|
| `--mode=standalone\|reverse-proxy` | `-Mode` | `SB_MODE` | [Mode](#modes) |
| `--host=sb.mydomain.com` | `-HostName` | `SB_HOST` | Domain (`VIRTUAL_HOST`), default `localhost` |
| `--email=me@example.com` | `-Email` | `SB_EMAIL` | Email for Let's Encrypt |
| `--tz=America/Toronto` | `-TimeZone` | `SB_TZ` | Time zone, default the host's |
| `--no-wait` | `-NoWait` | | Don't wait for SwitchBoard to be ready |
| `--prepare-only` | `-PrepareOnly` | | Prepare and check the configuration (`.env`, token, domain, mode) without starting anything |
| | `-NoFirewall` | | Don't add the Windows firewall rule |
| | | `SB_GITHUB_TOKEN` | GitHub token (optional: private copy of the repository, or higher request limit) |
| | | `SB_SMTP_*` | Mail settings, copied into `.env` |

Example: `./up.sh -y --host=sb.mydomain.com --email=me@mydomain.com`.
`./up.sh --help` lists everything.

<a id="everyday-commands"></a>
### Everyday commands

Because `COMPOSE_FILE` is saved in `.env`, plain `docker compose` commands work from the `Docker`
folder once `./up.sh` has run once:

```bash
docker compose ps                # state of the containers
docker compose logs -f php       # follow SwitchBoard's logs (first start, errors)
docker logs sb_letsencrypt       # certificate requests
docker compose restart           # restart everything
docker compose down              # stop (data is kept)
./up.sh                          # start again / apply a .env change / after a git pull
```

Plain `docker compose up` before the first `./up.sh` uses `docker-compose.yml`. That file is a
deliberate placeholder that refuses to start and points you to `./up.sh`, which prevents starting
the wrong stack by mistake. If you prefer to run Compose yourself from the start, always use
`--build`:
- standalone mode: `docker compose -f docker-compose.standalone.yml up -d --build`;
- reverse-proxy mode: `docker compose -f docker-compose.reverse-proxy.yml up -d --build`.

<a id="lan-access"></a>
## Access from your local network

In standalone mode with `VIRTUAL_HOST=localhost`, SwitchBoard also answers on the machine's LAN address
and name. From any phone or computer on the same network you can open:
- `https://192.168.x.y`;
- `https://<machine-name>.local`, which works on most networks.

`./up.sh` prints these addresses when it finishes. The self-signed certificate covers them, and it is
recomputed on every `./up.sh` (for example after the address changes), so browsers show their warning
once.

Firewall:

- **Linux, Raspberry Pi**: nothing to do. Docker opens the published ports 80/443 itself; it even
  bypasses `ufw` rules. A firewall on your router or outside the machine can still block them.
- **Windows**: run `up.cmd` once as administrator; it adds the rule for **private** networks. If
  Windows lists your network as *Public*, `up.ps1` says so: set it to *Private* in *Settings* →
  *Network & Internet* → your network.
  - If you once clicked "Allow" on the Windows firewall prompt for Docker Desktop on a public network,
    Windows added a rule that lets all Docker ports in on any public network (café, hotel…).
  - You can remove it in *Windows Defender Firewall* → *Inbound rules* → `com.docker.backend.exe`
    (Public).

To reach SwitchBoard from the Internet, use a real domain (`--host=sb.mydomain.com`) with ports 80/443
forwarded to this machine. Let's Encrypt then issues a valid certificate.

<a id="configuration"></a>
## Configuration reference

<a id="containers"></a>
### The containers

- **sb_letsencrypt** (standalone mode): gets and renews the TLS certificates.
- **sb_nginx-proxy** (standalone mode): reverse proxy on ports 80 and 443. It generates the self-signed
  certificate for `localhost` and the LAN addresses.
- **sb_web** (`sb_web_server` in reverse-proxy mode): the nginx web server.
- **sb_php-fpm**: where SwitchBoard runs. On first start it downloads SwitchBoard from GitHub
  (`prepare_switchboard.sh`), installs its dependencies, configures the database and mail, and runs
  the scheduled tasks (cron).
- **sb_mariadb**: the MariaDB database.

All of them are on the external Docker network `web`, which `./up.sh` creates
(`docker network create web`).

<a id="env-file"></a>
### The .env file

`.env` holds the server's configuration. `./up.sh` creates it on the first run, so you don't have to
edit it for a first install. It's ignored by git, so a `git pull` never overwrites it. **Back it up**:
without its `APP_ENCRYPTION_KEY`, the secrets stored in the database can't be recovered.

To create it yourself instead, copy `.env_sample` to `.env` and replace at least `MYSQL_ROOT_PASSWORD`
and `MYSQL_PASSWORD` with your own secure values. Never use the sample ones.

- **COMPOSE_PROJECT_NAME**: name of the Docker Compose project; no need to change it.
- **COMPOSE_FILE**: the [mode](#modes), written by `./up.sh`.
- **DEFAULT_EMAIL**: used for the certificates. It is visible in your domain's certificates.
- **VIRTUAL_HOST**: `localhost` for a local or LAN install, or your domain or sub-domain in production
  (`VIRTUAL_HOST=sb.mydomain.com`). Use one or the other, not both. With a domain, the letsencrypt
  container creates the certificates.
- **TZ**: time zone, from the second column of the
  [tz database list](https://en.wikipedia.org/wiki/List_of_tz_database_time_zones)
  (e.g. `America/Toronto`).
- **MYSQL_ROOT_PASSWORD, MYSQL_DATABASE, MYSQL_USER, MYSQL_PASSWORD**: database settings. Use secure
  passwords (`./up.sh` generates random ones). You can also change `MYSQL_DATABASE` and `MYSQL_USER`
  to your own values.
- **GITHUB_TOKEN**: optional. Leave it empty for the public repository. A read-only token lifts
  GitHub's limit on requests without a token for the update check, or lets you follow a private copy
  of the repository. A token that GitHub refuses is ignored (the update check goes on without it, and
  the admin console says so). See [Before you start](#before-you-start).
- **APP_ENCRYPTION_KEY**: encrypts the voip.ms, SwitchBoard Chat and MAS secrets stored in the
  database (and the OAuth mail settings). `./up.sh` / `up.cmd` check it on every run: if it's empty
  they generate one; if it's malformed (anything but 32 bytes in base64, 44 characters) they stop,
  because SwitchBoard would ignore it. Secrets stored in plaintext before the key existed then get
  encrypted. To make one yourself: `openssl rand -base64 32`. Back it up with your database backups:
  losing it makes the encrypted secrets unrecoverable.
- **CRON_WEB_KEY**: only needed to trigger the batch scripts over HTTP instead of the container's
  built-in cron (`cronjobs`). Leave it empty otherwise.
- **SB_SBCHAT_CA_BUNDLE / SB_ALLOW_INSECURE_TLS**: only for a SwitchBoard Chat / MAS server with a
  **self-signed** certificate; see [Self-signed SBChat certificate](#sbchat-certificate).
- **SB_SMTP_HOST / SB_SMTP_PORT / SB_SMTP_USER / SB_SMTP_PASSWORD / SB_SMTP_FROM / SB_SMTP_TLS**: mail
  with a simple password; see [Mail](#mail).
- **SB_SMTP_OAUTH_PROVIDER / SB_SMTP_OAUTH_CLIENT_ID / SB_SMTP_OAUTH_CLIENT_SECRET /
  SB_SMTP_OAUTH_TENANT**: mail with OAuth2 (Microsoft 365 or Google); see [Mail](#mail) and
  [MAIL-OAUTH-SETUP.md](../MAIL-OAUTH-SETUP.md).
- **SB_DMS_\***: monitoring URLs; see [Monitoring](#monitoring).

After changing `.env`, run `./up.sh` again.

<a id="nginx-conf"></a>
### The nginx.conf file

`nginx/nginx.conf` is the configuration of the `sb_web` web server. It protects the php-fpm container,
in both modes. Like `.env`, it's ignored by git and created by `./up.sh` from `nginx/nginx.conf.sample`
on the first run (or create it yourself: `cp nginx/nginx.conf.sample nginx/nginx.conf`). Its
`server_name` must match `VIRTUAL_HOST`.

Existing installs: `nginx.conf` is never replaced, so it doesn't get the newer `resolver` lines of the
sample. Those lines make nginx re-read the php container's address, which avoids 502 errors after a
php-only update. `./up.sh` already restarts the web container whenever php is recreated. If you also
recreate php by hand, copy the `resolver` and `set $php_fpm` lines from `nginx.conf.sample`.

<a id="mail"></a>
### Mail (SMTP)

SwitchBoard uses mail to report errors and warnings affecting call routing, so it's worth getting this
working. If mail is broken, problems can go unnoticed.

The `./up.sh` wizard asks for your SMTP relay's host, port, user and password, and writes them into
`.env` (`SB_SMTP_*`). The php container configures msmtp from these variables **at startup**
(`generate_mail_config.sh`). To change a mail password, edit `.env` and run `./up.sh` again; no
rebuild is needed. You can skip mail during the wizard and configure it later. SwitchBoard still
starts, but error notifications won't go out.

**Simple password** (the wizard's default):
- A Google account with an [app password](https://support.google.com/accounts/answer/185833) is
  recommended, not your regular password.
- Use an account with some prior activity: a brand-new one is more likely to be flagged as spam.
- Google allows 500 emails a day, which is plenty for notifications.
- Other providers can work, but expect friction. Yandex has been observed flagging fresh accounts as
  spam.
- **Microsoft 365 is phasing out SMTP passwords**: use OAuth for it.

**OAuth2** (Microsoft 365 or Google):
1. Skip mail in the wizard (option 3).
2. In SwitchBoard, go to *Console d'administration → Courriel OAuth* and choose a provider.
3. Follow the steps shown on the page to register an app in the provider's console. This is a one-time
   step outside SwitchBoard: Azure AD for Microsoft, Google Cloud Console for Google. See
   [MAIL-OAUTH-SETUP.md](../MAIL-OAUTH-SETUP.md) for a fuller walkthrough.
4. Paste the Client ID (plus the secret for Google), then click *Authorize*.
5. Run `docker compose restart php` once afterwards so mail switches to OAuth.

The wizard's option 2 lets you set the `SB_SMTP_OAUTH_*` variables in `.env` directly instead, which
is useful for scripted installs.

**Test it** from *Console d'administration → Courriel de test*. It sends a real email and shows the
actual SMTP error if it fails (wrong password, wrong port…).

**Mail for MAS (password recovery) without new settings.** `./up.sh` also starts `sb_mail_relay`, a
small internal SMTP relay that sends through the same `SB_SMTP_*` account (password or OAuth2) as
SwitchBoard. MAS can't use OAuth2 itself, so on the server where MAS runs, once, do:
`./utils/connect-mas-mail.sh /path/to/mas-compose-folder`. It backs up MAS's `config.yaml`, points its
`email:` block at `sb_mail_relay:2525` with SwitchBoard's sender address, joins MAS to the `web` network
and recreates the MAS container. If you set up OAuth from the admin console after the first start, run
`docker compose restart php mail-relay` once, as for php.

<a id="sbchat-certificate"></a>
### Self-signed SBChat certificate

Skip this section if your SwitchBoard Chat server uses a normal certificate (Let's Encrypt, etc.).
For the full SBChat/MAS setup (bootstrap token, agents, voip.ms SMS hook…), see
[SBCHAT-SETUP.md](../SBCHAT-SETUP.md). Without Docker, see [../README.md](../README.md) instead.

With a self-signed certificate, SwitchBoard refuses to connect (cURL error #60) unless you tell it to
trust that certificate. The recommended way keeps TLS verification on:

1. Save the certificate into the `certs/` folder. It is mounted read-only in the php container at
   `/etc/ssl/sbchat`. From the `Docker/` folder:

       echo | openssl s_client -connect sbchat.mydomain.ca:3443 2>/dev/null | openssl x509 > certs/sbchat-cert.pem

2. In `.env`, give the path **inside the container**, not the host path. A host path gives cURL error
   #77:

       SB_SBCHAT_CA_BUNDLE=/etc/ssl/sbchat/sbchat-cert.pem

3. `docker compose down && ./up.sh`

As a last resort, `SB_ALLOW_INSECURE_TLS=1` disables TLS verification for the SBChat/MAS connection
entirely. SBChat/MAS tokens travel over that connection, so this exposes them to interception. Prefer
the certificate.

<a id="monitoring"></a>
### Monitoring (dead-man's-switch)

SwitchBoard's own error emails depend on SwitchBoard being alive. They don't help if the container
hangs or the host goes down.

1. In SwitchBoard, go to *Console d'administration → Surveillance (dead-man's-switch)*.
2. Set ping URLs from a free heartbeat service such as [healthchecks.io](https://healthchecks.io).

SwitchBoard pings each URL after checking a critical subsystem: ring group refresh, SBChat refresh,
mail delivery, and database/disk. The **external** service alerts you when a ping doesn't arrive.

See [DEADMANSWITCH-SETUP.md](../DEADMANSWITCH-SETUP.md) for the full walkthrough, including the
notification channels (SMS, Telegram, push…).

<a id="reverse-proxy"></a>
## Using an external reverse proxy

Run `./up.sh` and choose option 2 (external reverse proxy), or `./up.sh -y --mode=reverse-proxy`. From
then on it uses `docker-compose.reverse-proxy.yml`.

- The `web` network must exist (`./up.sh` creates it) and your reverse proxy must be on it.
- Point your reverse proxy to **port 80 of the container `sb_web_server`**.
- Leave `nginx/nginx.conf` in place: it protects the php-fpm container even in this mode.

**Nginx Proxy Manager**: example `docker-compose.yml`. The last four lines put it on the `web`
network so it can reach SwitchBoard.

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

**Plain nginx**: add the sub-domain to the port 80 `server` block, then add a 443 block. In this
example both domains share one certificate:

```nginx
server {
    listen 80;
    server_name matrix.mydomain.ca sb.mydomain.ca;
    ...
}
server {
    listen 443 ssl;
    server_name sb.mydomain.ca;
    ssl_certificate /etc/letsencrypt/live/matrix.mydomain.ca/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/matrix.mydomain.ca/privkey.pem;
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;

    location / {
        proxy_pass http://sb_web_server:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;

        # WebSocket support if needed
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
    }
}
```

The certificates must exist before nginx starts with this configuration; otherwise nginx crashes. To
create them first, with a certbot container:

1. Rename your `nginx.conf` and replace it temporarily with this one:

```nginx
server {
    listen 80;
    server_name sb.mydomain.ca matrix.mydomain.ca;

    # ACME challenge location for Let's Encrypt
    location /.well-known/acme-challenge/ {
        root /var/www/certbot;
    }

    # Temporary: serve a simple page for everything else
    location / {
        return 200 "Certbot setup in progress\n";
        add_header Content-Type text/plain;
    }
}
```

2. Start the containers, wait until the certificates are created, and check that the proxy isn't
   crashing.
3. Stop everything and put your real `nginx.conf` back.
4. Start again. The certificates are there and the proxy is stable.

<a id="troubleshooting"></a>
## Troubleshooting

| Symptom | What to do |
|---|---|
| "Docker n'est pas installé" / "Docker ne répond pas" | Install Docker for [your platform](#contents) and start it (`sudo systemctl start docker`, or open Docker Desktop). |
| "permission denied … docker.sock" | Add yourself to the `docker` group and log in again, or use `sudo ./up.sh`. |
| "GitHub refuse ce jeton … il est ignoré" | The token in `.env` is unknown, expired or revoked. The repository is public, so the install goes on without it: remove it (`GITHUB_TOKEN=`) or replace it. |
| "Le dépôt SwitchBoard n'est pas public : un jeton GitHub est nécessaire" | You install from a private copy of the repository. Create a token (see [Before you start](#before-you-start)) and put it in `.env` (`GITHUB_TOKEN=...`) or `SB_GITHUB_TOKEN`. |
| "Impossible de joindre GitHub" | The machine has no Internet access, or a firewall blocks github.com. The install needs it to download SwitchBoard. |
| "port 80 (or 443) is already in use" | Another web server is running. Stop it, or use the [reverse-proxy mode](#reverse-proxy). |
| 32-bit system detected (Raspberry Pi) | Reinstall with Raspberry Pi OS **64-bit**; see [Raspberry Pi](#install-raspberry-pi). |
| Windows: virtualization is disabled | Enable VT-x / AMD-V in the BIOS, then do a full shutdown; see [Windows](#install-windows). |
| Windows: "Switch to Linux containers" | Right-click the Docker icon → *Switch to Linux containers*. |
| Other devices can't reach `https://192.168.x.y` | Check the firewall; see [LAN access](#lan-access). Make sure both devices are on the same network. |
| 502 Bad Gateway after an update | Run `./up.sh` again (it restarts the web container), and see [nginx.conf](#nginx-conf). |
| `sb_nginx-proxy` keeps restarting | Check `docker logs sb_nginx-proxy` and `docker logs sb_letsencrypt`. Bad certificates stop it from starting. Then `docker compose down` and `./up.sh`. |
| Windows: error 500 when creating the admin account, or "COMPOSE_FILE (dans .env) n'inclut pas docker-compose.windows.yml" | `COMPOSE_FILE` in `.env` doesn't include `docker-compose.windows.yml` (`.env` written by hand or copied from Linux), so the database lives in the Windows folder `db_data`, where MariaDB can't alter its tables. `up.cmd` adds the file by itself as long as no database exists in `db_data`. If the install holds nothing worth keeping yet: `docker compose down`, rename `db_data` (e.g. `db_data.old`), then run `up.cmd` again. |
| "des secrets enregistrés ont été chiffrés avec une ancienne clé" | `APP_ENCRYPTION_KEY` was removed from `.env` while some secrets were already encrypted, so the newly generated key can't read them. Put the old key back (from your `.env` backup) and run the installer again, or re-enter those secrets in SwitchBoard (voip.ms, SB Chat / MAS, mail). |
| "APP_ENCRYPTION_KEY dans .env n'est pas une clé valide" | The key isn't 32 bytes in base64 (44 characters). Put the original key back, or empty the line (`APP_ENCRYPTION_KEY=`) so that a key gets generated. |
| Any other error (error 500, blank page…) | Reproduce it, then run the [diagnostic report](#diagnostic) and send it. |
| The first start seems stuck | Follow `docker compose logs -f php`. The first start downloads SwitchBoard and its dependencies (a few minutes, longer on a Pi). After 15 minutes without progress, the installer stops and shows the last lines of that log. |
| "Le fichier .env est introuvable, mais une base de données SwitchBoard existe déjà" | `.env` was deleted or moved, but the database is still there. A new `.env` would have other passwords and another encryption key, so the installer refuses to create one. Put your backup of `.env` back. To start over and **erase all data**: `docker compose down -v` (Windows) or delete `db_data` and `web` (Linux). |
| "La base de données refuse les identifiants de .env" | `MYSQL_USER`, `MYSQL_PASSWORD` or `MYSQL_DATABASE` were changed in `.env` after the install. They're only used when the database is created: put the old values back. |
| "Une autre installation de SwitchBoard existe déjà" | Another copy of SwitchBoard (another folder) is running on this machine. Only one can run at a time: use that folder, or stop it there with `docker compose down`. |
| "VIRTUAL_HOST … n'est pas un nom de domaine valide" / "est une adresse IP" | Use a domain name (`sb.mydomain.com`) or `localhost`. For access by IP address, use `localhost`: SwitchBoard also answers on the machine's IP. `https://` and a trailing `/` are removed automatically. |
| "La connexion sécurisée à GitHub échoue" / certificate error | Check the machine's date and time, or a proxy/antivirus that intercepts HTTPS. |
| "Le disque est plein" / "Docker Hub limite le nombre de téléchargements" | Free some disk space (`docker system prune -a`), or wait an hour (or `docker login`) for the Docker Hub limit. Then run the installer again. |
| "Docker s'est arrêté pendant l'installation" | Restart Docker and run the installer again; it picks up where it stopped. On Windows, `up.cmd` restarts Docker Desktop itself. |
| "Le conteneur php manque de mémoire" | Give Docker at least 2 GB of memory (Docker Desktop: *Settings* → *Resources*), or use a Pi with 2 GB or more. |
| Windows: "Docker Desktop is unable to start" | Often caused by an automatic WSL update. `up.cmd` waits for the update to finish and restarts Docker Desktop. If it still fails: quit Docker Desktop, run `wsl --update` then `wsl --shutdown` in PowerShell, reopen Docker Desktop, or restart the computer. |
| `./up.sh: Permission denied` | The execute permission was lost (ZIP, copy from Windows). Run `bash up.sh`, or `chmod +x up.sh`. |
| `/bin/bash^M: bad interpreter` | `up.sh` has Windows line endings (copied from Windows, or Git with `core.autocrlf=true`). Fix it with `sed -i 's/\r$//' up.sh`, or clone again from Linux. |
| Windows: "Fichiers introuvables : extrayez d'abord le ZIP" | `up.cmd` was opened inside the ZIP. Right-click the ZIP → *Extract All*, then run `up.cmd` from the extracted `Docker` folder. |
| Windows: "ce dossier est synchronisé par OneDrive" | OneDrive can lock or empty files during the build. If the install fails, move the SwitchBoard folder out of OneDrive (e.g. `C:\SwitchBoard`). |
| "up.sh a besoin de bash" | Install bash (`apk add bash` on Alpine). `sh up.sh` works too: it switches to bash itself. |
| "… n'est pas modifiable par …" | `.env` was created with `sudo`. Run `sudo chown $USER .env nginx/nginx.conf`, or keep using `sudo ./up.sh`. |

<a id="diagnostic"></a>
## Diagnostic report

When something goes wrong (an error page, an error 500, a container that keeps restarting…), the
diagnostic script gathers everything needed to find the cause into one text file. Paste that file into
a help conversation (Claude, email…).

1. **Reproduce the problem first** (for example, try again to create the admin account), so the logs
   contain the error.
2. Run the script from the `Docker` folder:
   - **Windows:** double-click **`diagnostic.cmd`**. The report is also copied to the clipboard.
   - **Linux, Raspberry Pi, macOS:** `./diagnostic.sh` (or `sudo ./diagnostic.sh`).
3. Paste the content of `switchboard-diagnostic-YYYYMMDD-HHMM.txt` into the conversation.

What the report contains:
- the system, Docker and WSL versions;
- the state of each container (restarts, out-of-memory, exit code);
- the `.env` settings, with secret values masked;
- the checks done inside the php container: PHP version and modules, write permissions, the
  database connection as SwitchBoard makes it, the accounts table;
- HTTP tests;
- the latest PHP errors and SwitchBoard logs, plus the logs of every container.

Passwords, tokens and keys are masked; the install tests check that none of them appears in the
report. The script changes nothing.

<a id="install-tests"></a>
## Install tests

`tests/install/` holds tests that install SwitchBoard for real on Debian, Ubuntu, Fedora, Rocky,
Alpine, Arch and Raspberry Pi OS (ARM64), on a real local network, and on Windows. Run them after any
significant change to the Docker files or scripts; see [tests/install/README.md](tests/install/README.md).

<a id="development"></a>
## Development with Docker

After a successful install, the `web` folder inside `Docker/` holds SwitchBoard's code. To edit it
from the host and push your changes with git (Linux):

```bash
# Create a www-data group with gid 33
sudo groupadd -g 33 www-data
# If that fails with "GID '33' already exists", find the existing group's name
# (the part before the first ":") and use it instead of www-data below:
sudo getent group 33

sudo chown -R www-data:www-data ./web
sudo usermod -aG www-data my_user     # add your user to the group
sudo chmod -R g+w ./web
```

You can now edit the `web` folder and use git there. Never do this on a production server or one
reachable from the Internet.
