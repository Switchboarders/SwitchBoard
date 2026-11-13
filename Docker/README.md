<a id="english"></a>
# SwitchBoard with Docker

**English** · [Français](#francais)

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

---

<a id="francais"></a>

# SwitchBoard avec Docker

[English](#english) · **Français**

La version Docker de SwitchBoard est un serveur complet et autonome. Elle fonctionne de la même façon
sur un VPS, un serveur maison, un PC Linux, un PC Windows ou un Raspberry Pi. Un seul script installe
et démarre tout : `./up.sh` sous Linux et sur Raspberry Pi, `up.cmd` sous Windows.

<a id="fr-contents"></a>
## Table des matières

- [Avant de commencer](#fr-before-you-start)
- [Démarrage rapide](#fr-quick-start)
- [Installer sous Linux](#fr-install-linux)
  - [Debian, Raspberry Pi OS](#fr-linux-debian)
  - [Ubuntu, Linux Mint](#fr-linux-ubuntu)
  - [Fedora](#fr-linux-fedora)
  - [Rocky Linux, AlmaLinux, RHEL](#fr-linux-rhel)
  - [Arch Linux, Manjaro](#fr-linux-arch)
  - [Alpine Linux](#fr-linux-alpine)
- [Installer sous Windows](#fr-install-windows)
- [Installer sur un Raspberry Pi](#fr-install-raspberry-pi)
- [macOS](#fr-install-macos)
- [Utiliser up.sh / up.ps1](#fr-running-up)
  - [Les deux modes : autonome ou derrière un reverse-proxy](#fr-modes)
  - [Installation entièrement automatique (sans question)](#fr-non-interactive)
  - [Commandes courantes](#fr-everyday-commands)
- [Accès depuis le réseau local](#fr-lan-access)
- [Référence de configuration](#fr-configuration)
  - [Les conteneurs](#fr-containers)
  - [Le fichier .env](#fr-env-file)
  - [Le fichier nginx.conf](#fr-nginx-conf)
  - [Courriel (SMTP)](#fr-mail)
  - [Certificat SBChat auto-signé](#fr-sbchat-certificate)
  - [Surveillance (dead-man's-switch)](#fr-monitoring)
- [Utiliser un reverse-proxy externe](#fr-reverse-proxy)
- [Dépannage](#fr-troubleshooting)
- [Rapport de diagnostic](#fr-diagnostic)
- [Tests d'installation](#fr-install-tests)
- [Développement avec Docker](#fr-development)

<a id="fr-before-you-start"></a>
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
3. **Les ports 80 et 443 libres** sur la machine, pour le [mode autonome](#fr-modes). Si un autre serveur
   web les utilise déjà, prenez plutôt le [mode reverse-proxy](#fr-reverse-proxy).
4. Un système **64 bits** : x86-64 (PC, VPS) ou ARM64 (Raspberry Pi 3/4/5, Zero 2 W).

<a id="fr-quick-start"></a>
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

<a id="fr-install-linux"></a>
## Installer sous Linux

Sur toutes les distributions : installez Docker, démarrez-le, permettez à votre utilisateur de s'en
servir, puis [clonez et lancez `./up.sh`](#fr-quick-start). Les commandes ci-dessous sont celles des
[tests d'installation](#fr-install-tests), qui installent réellement SwitchBoard sur chacune de ces
distributions.

Après vous être ajouté au groupe `docker`, déconnectez-vous puis reconnectez-vous (ou redémarrez) pour
que `docker` fonctionne sans `sudo`. Vous pouvez aussi lancer `sudo ./up.sh`.

<a id="fr-linux-debian"></a>
### Debian, Raspberry Pi OS

```bash
sudo apt-get update && sudo apt-get install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
```

Le script officiel installe Docker Engine et le module Compose, puis démarre le service.

<a id="fr-linux-ubuntu"></a>
### Ubuntu, Linux Mint

Soit le script officiel ([comme pour Debian](#fr-linux-debian)), soit les paquets d'Ubuntu :

```bash
sudo apt-get update && sudo apt-get install -y git docker.io docker-compose-v2
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

<a id="fr-linux-fedora"></a>
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

<a id="fr-linux-rhel"></a>
### Rocky Linux, AlmaLinux, RHEL

```bash
sudo dnf -y install dnf-plugins-core git
sudo dnf config-manager --add-repo https://download.docker.com/linux/centos/docker-ce.repo
sudo dnf -y install docker-ce docker-ce-cli containerd.io docker-compose-plugin
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

N'utilisez pas Podman, proposé par défaut sur ces systèmes : SwitchBoard a besoin de Docker.

<a id="fr-linux-arch"></a>
### Arch Linux, Manjaro

```bash
sudo pacman -Syu --needed docker docker-compose git
sudo systemctl enable --now docker
sudo usermod -aG docker $USER
```

Le paquet `docker` d'Arch n'a pas le module `buildx`. SwitchBoard se construit très bien sans lui ;
Docker affiche un avertissement « legacy builder » que vous pouvez ignorer.

<a id="fr-linux-alpine"></a>
### Alpine Linux

En tant que root (Alpine n'a souvent pas `sudo`), en remplaçant `mon_utilisateur` par votre
utilisateur :

```bash
apk add bash git docker docker-cli-compose
rc-update add docker default && service docker start
addgroup mon_utilisateur docker
```

`up.sh` a besoin de `bash`, qu'Alpine n'installe pas par défaut.

<a id="fr-install-windows"></a>
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
   `git clone` que dans le [démarrage rapide](#fr-quick-start), soit en téléchargeant le ZIP sur GitHub
   (*Code* → *Download ZIP* sur la branche `main`), puis en l'extrayant.
4. **Double-cliquez sur `up.cmd`** dans le dossier `Docker`. Il pose les mêmes questions que `up.sh`.
   Pour ouvrir aussi le pare-feu pour l'[accès depuis le réseau local](#fr-lan-access), faites une fois
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
[Installation entièrement automatique](#fr-non-interactive).

<a id="fr-install-raspberry-pi"></a>
## Installer sur un Raspberry Pi

SwitchBoard fonctionne sur un Raspberry Pi 3, 4, 5 ou Zero 2 W avec un système **64 bits**. Toutes
ses images existent en ARM64, mais MariaDB n'a pas de version ARM 32 bits : un Raspberry Pi OS 32 bits
ne fonctionnera pas, et `./up.sh` le dit. Un Pi 4 ou 5 avec 2 Go de mémoire ou plus est recommandé.

1. Avec [Raspberry Pi Imager](https://www.raspberrypi.com/software/), installez **Raspberry Pi OS Lite
   (64-bit)**. Dans ses réglages, activez SSH, choisissez un utilisateur, le Wi-Fi au besoin, et un nom
   d'hôte comme `switchboard`.
2. Connectez-vous au Pi (`ssh utilisateur@switchboard.local`) et installez Docker
   [comme sous Debian](#fr-linux-debian).
3. Déconnectez-vous puis reconnectez-vous, et suivez le [démarrage rapide](#fr-quick-start).
4. Ouvrez `https://switchboard.local` ou `https://<adresse du Pi>` depuis n'importe quel appareil du
   réseau.

Le premier démarrage construit l'image PHP sur le Pi : comptez de 10 à 20 minutes sur un Pi 4. Une
base de données use une carte SD : préférez un SSD USB (le Pi 4/5 peut démarrer dessus), et
sauvegardez régulièrement `.env` et la base. Donnez au Pi une adresse fixe dans votre routeur pour
qu'elle ne change pas.

<a id="fr-install-macos"></a>
## macOS

`up.sh` fonctionne aussi sous macOS avec [Docker Desktop](https://www.docker.com/products/docker-desktop)
installé et démarré, mais macOS n'est pas couvert par les [tests d'installation](#fr-install-tests).

<a id="fr-running-up"></a>
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

<a id="fr-modes"></a>
### Les deux modes : autonome ou derrière un reverse-proxy

- **Autonome** (par défaut) : SwitchBoard a son propre reverse-proxy (`sb_nginx-proxy`) et obtient ses
  certificats TLS de Let's Encrypt (`sb_letsencrypt`). Il lui faut les ports 80 et 443. Avec
  `VIRTUAL_HOST=localhost`, il utilise un certificat auto-signé et il est
  [joignable depuis le réseau local](#fr-lan-access).
- **Reverse-proxy** : vous avez déjà un reverse-proxy (nginx, Nginx Proxy Manager, Traefik…) sur les
  ports 80/443. SwitchBoard ne démarre que `sb_web_server`, `sb_php-fpm` et `sb_mariadb` ; voir
  [Utiliser un reverse-proxy externe](#fr-reverse-proxy).

La première utilisation demande le mode voulu et le mémorise dans `.env` (`COMPOSE_FILE=...`). Pour
changer de mode, supprimez cette ligne de `.env` et relancez `./up.sh`.

<a id="fr-non-interactive"></a>
### Installation entièrement automatique (sans question)

`./up.sh --non-interactive` (ou `-y`) installe et démarre tout avec des valeurs sûres :
- des mots de passe et une clé `APP_ENCRYPTION_KEY` aléatoires ;
- le fuseau horaire de la machine ;
- le mode autonome sur `localhost`.

Sans terminal (par exemple lancé depuis un autre script), il ne pose jamais de question non plus.

| up.sh (Linux, Pi, macOS) | up.ps1 (Windows) | Variable d'environnement | Rôle |
|---|---|---|---|
| `--mode=standalone\|reverse-proxy` | `-Mode` | `SB_MODE` | [Mode](#fr-modes) |
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

<a id="fr-everyday-commands"></a>
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

<a id="fr-lan-access"></a>
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

<a id="fr-configuration"></a>
## Référence de configuration

<a id="fr-containers"></a>
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

<a id="fr-env-file"></a>
### Le fichier .env

`.env` contient la configuration du serveur. `./up.sh` le crée à la première utilisation : pas besoin
de le modifier pour une première installation. Il est ignoré par git, donc un `git pull` ne l'écrase
jamais. **Sauvegardez-le** : sans sa clé `APP_ENCRYPTION_KEY`, les secrets enregistrés dans la base sont
perdus.

Pour le créer vous-même, copiez `.env_sample` vers `.env` et remplacez au moins `MYSQL_ROOT_PASSWORD`
et `MYSQL_PASSWORD` par vos propres valeurs sûres. N'utilisez jamais celles de l'exemple.

- **COMPOSE_PROJECT_NAME** : nom du projet Docker Compose ; inutile de le changer.
- **COMPOSE_FILE** : le [mode](#fr-modes), écrit par `./up.sh`.
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
  sans lui, et la console d'administration le dit). Voir [Avant de commencer](#fr-before-you-start).
- **APP_ENCRYPTION_KEY** : chiffre les secrets voip.ms, SwitchBoard Chat et MAS enregistrés dans la
  base (et le courriel OAuth). `./up.sh` / `up.cmd` la vérifient à chaque lancement : vide, ils en
  génèrent une ; mal formée (autre chose que 32 octets en base64, 44 caractères), ils s'arrêtent, car
  SwitchBoard l'ignorerait. Les secrets enregistrés en clair avant qu'elle existe sont alors chiffrés.
  Pour en créer une vous-même : `openssl rand -base64 32`. Sauvegardez-la avec vos sauvegardes de la
  base : la perdre rend les secrets chiffrés irrécupérables.
- **CRON_WEB_KEY** : seulement pour déclencher les scripts de traitement par HTTP au lieu du cron
  intégré au conteneur (`cronjobs`). Sinon, laissez vide.
- **SB_SBCHAT_CA_BUNDLE / SB_ALLOW_INSECURE_TLS** : seulement pour un serveur SwitchBoard Chat / MAS
  avec un certificat **auto-signé** ; voir [Certificat SBChat auto-signé](#fr-sbchat-certificate).
- **SB_SMTP_HOST / SB_SMTP_PORT / SB_SMTP_USER / SB_SMTP_PASSWORD / SB_SMTP_FROM / SB_SMTP_TLS** :
  courriel avec un simple mot de passe ; voir [Courriel](#fr-mail).
- **SB_SMTP_OAUTH_PROVIDER / SB_SMTP_OAUTH_CLIENT_ID / SB_SMTP_OAUTH_CLIENT_SECRET /
  SB_SMTP_OAUTH_TENANT** : courriel avec OAuth2 (Microsoft 365 ou Google) ; voir [Courriel](#fr-mail) et
  [MAIL-OAUTH-SETUP.md](../MAIL-OAUTH-SETUP.md).
- **SB_DMS_\*** : adresses de surveillance ; voir [Surveillance](#fr-monitoring).

Après un changement de `.env`, relancez `./up.sh`.

<a id="fr-nginx-conf"></a>
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

<a id="fr-mail"></a>
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

<a id="fr-sbchat-certificate"></a>
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

<a id="fr-monitoring"></a>
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

<a id="fr-reverse-proxy"></a>
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

<a id="fr-troubleshooting"></a>
## Dépannage

| Symptôme | Quoi faire |
|---|---|
| « Docker n'est pas installé » / « Docker ne répond pas » | Installez Docker pour [votre plateforme](#fr-contents) et démarrez-le (`sudo systemctl start docker`, ou ouvrez Docker Desktop). |
| « permission denied … docker.sock » | Ajoutez-vous au groupe `docker` et reconnectez-vous, ou utilisez `sudo ./up.sh`. |
| « GitHub refuse ce jeton … il est ignoré » | Le jeton de `.env` est inconnu, expiré ou révoqué. Le dépôt étant public, l'installation continue sans lui : retirez-le (`GITHUB_TOKEN=`) ou remplacez-le. |
| « Le dépôt SwitchBoard n'est pas public : un jeton GitHub est nécessaire » | Vous installez depuis une copie privée du dépôt. Créez un jeton (voir [Avant de commencer](#fr-before-you-start)) et mettez-le dans `.env` (`GITHUB_TOKEN=...`) ou dans `SB_GITHUB_TOKEN`. |
| « Impossible de joindre GitHub » | La machine n'a pas accès à Internet, ou un pare-feu bloque github.com. L'installation en a besoin pour télécharger SwitchBoard. |
| « le port 80 (ou 443) est déjà utilisé » | Un autre serveur web tourne. Arrêtez-le, ou utilisez le [mode reverse-proxy](#fr-reverse-proxy). |
| Système 32 bits détecté (Raspberry Pi) | Réinstallez avec Raspberry Pi OS **64 bits** ; voir [Raspberry Pi](#fr-install-raspberry-pi). |
| Windows : virtualisation désactivée | Activez VT-x / AMD-V dans le BIOS, puis faites un arrêt complet ; voir [Windows](#fr-install-windows). |
| Windows : « Switch to Linux containers » | Clic droit sur l'icône Docker → *Switch to Linux containers*. |
| Les autres appareils n'ouvrent pas `https://192.168.x.y` | Vérifiez le pare-feu ; voir [Accès depuis le réseau local](#fr-lan-access). Assurez-vous que les deux appareils sont sur le même réseau. |
| 502 Bad Gateway après une mise à jour | Relancez `./up.sh` (il redémarre le conteneur web), et voir [nginx.conf](#fr-nginx-conf). |
| `sb_nginx-proxy` redémarre sans arrêt | Consultez `docker logs sb_nginx-proxy` et `docker logs sb_letsencrypt`. De mauvais certificats l'empêchent de démarrer. Puis `docker compose down` et `./up.sh`. |
| Windows : erreur 500 en créant le compte admin, ou « COMPOSE_FILE (dans .env) n'inclut pas docker-compose.windows.yml » | `COMPOSE_FILE` dans `.env` ne contient pas `docker-compose.windows.yml` (`.env` écrit à la main ou copié depuis Linux) : la base est alors dans le dossier Windows `db_data`, où MariaDB n'arrive pas à modifier ses tables. `up.cmd` ajoute ce fichier tout seul tant qu'aucune base n'existe dans `db_data`. Si l'installation ne contient encore rien à garder : `docker compose down`, renommez `db_data` (ex. `db_data.ancien`), puis relancez `up.cmd`. |
| « des secrets enregistrés ont été chiffrés avec une ancienne clé » | `APP_ENCRYPTION_KEY` a été retirée de `.env` alors que des secrets étaient déjà chiffrés : la nouvelle clé générée ne peut pas les lire. Remettez l'ancienne clé (sauvegarde de `.env`) et relancez l'installateur, ou ressaisissez ces secrets dans SwitchBoard (voip.ms, SB Chat / MAS, courriel). |
| « APP_ENCRYPTION_KEY dans .env n'est pas une clé valide » | La clé n'est pas 32 octets en base64 (44 caractères). Remettez la clé d'origine, ou videz la ligne (`APP_ENCRYPTION_KEY=`) pour qu'une clé soit générée. |
| Toute autre erreur (erreur 500, page blanche…) | Reproduisez-la, puis lancez le [rapport de diagnostic](#fr-diagnostic) et envoyez-le. |
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

<a id="fr-diagnostic"></a>
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

<a id="fr-install-tests"></a>
## Tests d'installation

`tests/install/` contient des tests qui installent réellement SwitchBoard sur Debian, Ubuntu, Fedora,
Rocky, Alpine, Arch et Raspberry Pi OS (ARM64), sur un vrai réseau local, et sous Windows. Relancez-les
après tout changement important aux fichiers ou scripts Docker ; voir
[tests/install/README.md](tests/install/README.md).

<a id="fr-development"></a>
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
