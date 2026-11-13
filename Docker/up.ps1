<#
Installe et démarre SwitchBoard sous Windows avec Docker Desktop (équivalent de up.sh).
Double-cliquez sur up.cmd, ou dans PowerShell :  .\up.ps1

Sans aucune question (automatisation, tests) :
  .\up.ps1 -NonInteractive [-Mode standalone|reverse-proxy] [-HostName sb.mondomaine.com]
           [-Email moi@exemple.com] [-TimeZone America/Toronto] [-NoWait] [-NoFirewall]
  -PrepareOnly : prépare la configuration (.env, nginx.conf, fins de ligne) sans démarrer Docker.
Les mêmes réglages peuvent venir de l'environnement : SB_MODE, SB_HOST, SB_EMAIL, SB_TZ,
SB_GITHUB_TOKEN (facultatif pour le dépôt public, vérifié auprès de GitHub) et les SB_SMTP_* (copiés tels quels dans .env).

Compatible Windows PowerShell 5.1 (celui de Windows 10/11) et PowerShell 7.
#>
[CmdletBinding()]
param(
    [switch]$NonInteractive,
    [string]$Mode = $env:SB_MODE,
    [string]$HostName = $env:SB_HOST,
    [string]$Email = $env:SB_EMAIL,
    [string]$TimeZone = $env:SB_TZ,
    [switch]$NoWait,
    [switch]$NoFirewall,
    [switch]$PrepareOnly
)
$ErrorActionPreference = 'Stop'
Set-Location -LiteralPath $PSScriptRoot
$EnvFile = Join-Path $PSScriptRoot '.env'
$Utf8 = New-Object System.Text.UTF8Encoding($false)   # sans BOM : Docker Compose lit .env tel quel

$Mode = "$Mode"
switch ($Mode) {
    { $_ -in @('', 'standalone', 'autonome', '1') } { $ModeFile = 'docker-compose.standalone.yml'; break }
    { $_ -in @('reverse-proxy', 'proxy', '2') } { $ModeFile = 'docker-compose.reverse-proxy.yml'; break }
    default { Write-Host "Mode inconnu : $Mode (standalone ou reverse-proxy)"; exit 1 }
}
if (-not [Environment]::UserInteractive -or [Console]::IsInputRedirected) { $NonInteractive = $true }

# Commande externe silencieuse ; renvoie son code de sortie. (Sous PowerShell 5.1, rediriger la
# sortie d'erreur d'un programme avec $ErrorActionPreference = 'Stop' le ferait échouer.)
function Invoke-Quiet {
    param([string]$Exe, [string[]]$Arguments)
    $old = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $Exe @Arguments 2>&1 | Out-Null; return $LASTEXITCODE }
    catch { return 1 }
    finally { $ErrorActionPreference = $old }
}

# --- .env -------------------------------------------------------------------------------------
function Read-EnvLines { if (Test-Path -LiteralPath $EnvFile) { return [IO.File]::ReadAllText($EnvFile, $Utf8) -split "`r?`n" } else { return @() } }
function Write-EnvLines($Lines) {
    $text = (($Lines | Where-Object { $_ -ne $null }) -join "`n").TrimEnd("`n") + "`n"
    [IO.File]::WriteAllText($EnvFile, $text, $Utf8)
}
function Get-EnvValue([string]$Key) {
    $line = Read-EnvLines | Where-Object { $_ -like "$Key=*" } | Select-Object -Last 1
    if ($line) { return $line.Substring($Key.Length + 1) } else { return '' }
}
# Remplace KEY=... (ou ajoute la ligne) ; une valeur vide laisse la valeur existante intacte.
function Set-EnvValue([string]$Key, [string]$Value) {
    if ([string]::IsNullOrEmpty($Value)) { return }
    $lines = @(Read-EnvLines)
    $found = $false
    for ($i = 0; $i -lt $lines.Count; $i++) {
        if ($lines[$i] -like "$Key=*") { $lines[$i] = "$Key=$Value"; $found = $true }
    }
    if (-not $found) { $lines += "$Key=$Value" }
    Write-EnvLines $lines
}
function New-Secret([int]$Bytes) {
    $buffer = New-Object byte[] $Bytes
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    $rng.GetBytes($buffer); $rng.Dispose()
    return [Convert]::ToBase64String($buffer)
}
# Fuseau Windows -> nom IANA (PowerShell 5.1 n'a pas de conversion intégrée).
function Get-IanaTimeZone {
    $map = @{
        'Eastern Standard Time' = 'America/Toronto'; 'Central Standard Time' = 'America/Winnipeg'
        'Canada Central Standard Time' = 'America/Regina'; 'Mountain Standard Time' = 'America/Edmonton'
        'Pacific Standard Time' = 'America/Vancouver'; 'Atlantic Standard Time' = 'America/Halifax'
        'Newfoundland Standard Time' = 'America/St_Johns'; 'UTC' = 'UTC'
        'Romance Standard Time' = 'Europe/Paris'; 'GMT Standard Time' = 'Europe/London'
    }
    $id = [TimeZoneInfo]::Local.Id
    if ($map.ContainsKey($id)) { return $map[$id] } else { return '' }
}
function Get-LanAddresses {
    try {
        Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
            Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and
                           $_.InterfaceAlias -notmatch 'vEthernet|WSL|Docker|Loopback|Tailscale|VPN' } |
            Select-Object -ExpandProperty IPAddress | Sort-Object -Unique
    } catch { @() }
}

# --- Fins de ligne ----------------------------------------------------------------------------
# Un clone Git sous Windows (ou un éditeur) peut convertir les fichiers en CRLF ; les scripts
# copiés dans les images Linux échouent alors (« bad interpreter », cron muet). .gitattributes
# l'évite pour les clones récents ; on corrige quand même ici, sans rien toucher d'autre.
$LfFiles = @('*.sh', 'cronjobs', 'Dockerfile*', 'nginx\Dockerfile', '.env_sample', '*.yml', '*.ini',
             'nginx\nginx.conf.sample', 'utils\logrotate', 'templates\*')
$fixed = 0
foreach ($pattern in $LfFiles) {
    foreach ($file in Get-ChildItem -Path (Join-Path $PSScriptRoot $pattern) -File -Force -ErrorAction SilentlyContinue) {
        $bytes = [IO.File]::ReadAllBytes($file.FullName)
        $out = New-Object System.Collections.Generic.List[byte] ($bytes.Length)
        $changed = $false
        for ($i = 0; $i -lt $bytes.Length; $i++) {
            if ($bytes[$i] -eq 13 -and $i + 1 -lt $bytes.Length -and $bytes[$i + 1] -eq 10) { $changed = $true; continue }
            $out.Add($bytes[$i])
        }
        if ($changed) { [IO.File]::WriteAllBytes($file.FullName, $out.ToArray()); $fixed++ }
    }
}
if ($fixed -gt 0) { Write-Host "Fins de ligne Windows (CRLF) converties en LF dans $fixed fichier(s)." }

# --- Docker -----------------------------------------------------------------------------------
# Docker Desktop s'installe pour tous (Program Files) ou pour l'utilisateur seul (AppData\Local).
$DockerDesktop = ''
foreach ($base in @((Join-Path $env:ProgramFiles 'Docker\Docker'), (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop'))) {
    if (-not $DockerDesktop -and (Test-Path -LiteralPath (Join-Path $base 'Docker Desktop.exe'))) {
        $DockerDesktop = Join-Path $base 'Docker Desktop.exe'
        $bin = Join-Path $base 'resources\bin'
        if (-not (Get-Command docker -ErrorAction SilentlyContinue) -and (Test-Path -LiteralPath (Join-Path $bin 'docker.exe'))) {
            $env:Path = "$bin;$env:Path"
        }
    }
}
# Docker Desktop a besoin de la virtualisation du processeur (VT-x / AMD-V), activée dans le
# BIOS/UEFI. Quand Hyper-V tourne déjà, Windows la rapporte comme désactivée : on vérifie les deux.
function Test-VirtualizationOff {
    try {
        $cpu = @(Get-CimInstance Win32_Processor -ErrorAction Stop)[0]
        $hyper = (Get-CimInstance Win32_ComputerSystem -ErrorAction Stop).HypervisorPresent
        return ($cpu.VirtualizationFirmwareEnabled -eq $false -and -not $hyper)
    } catch { return $false }
}
# WSL répond-il ? (Une mise à jour en cours le bloque quelques minutes.) Attend jusqu'à 5 minutes.
function Wait-WslReady {
    for ($i = 0; $i -lt 30; $i++) {
        if ((Invoke-Quiet wsl.exe @('--status')) -eq 0) { return $true }
        Start-Sleep -Seconds 10
    }
    return $false
}
# Lance Docker Desktop dans la session de l'utilisateur. Depuis SSH (session sans bureau), un
# lancement direct ne démarre pas correctement : on passe par une tâche planifiée « interactive ».
function Start-DockerDesktop {
    if ($env:SSH_CONNECTION -or $env:SSH_CLIENT) {
        try {
            $action = New-ScheduledTaskAction -Execute $DockerDesktop
            $principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive
            Register-ScheduledTask -TaskName 'SwitchBoard-DockerDesktop' -Action $action -Principal $principal -Force | Out-Null
            Start-ScheduledTask -TaskName 'SwitchBoard-DockerDesktop'
            Start-Sleep -Seconds 5
            Unregister-ScheduledTask -TaskName 'SwitchBoard-DockerDesktop' -Confirm:$false
            return
        } catch { }
    }
    Start-Process -FilePath $DockerDesktop | Out-Null
}
if (-not $PrepareOnly) {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
        Write-Host "Docker Desktop n'est pas installé."
        Write-Host "Installez-le : https://www.docker.com/products/docker-desktop  (ou : winget install Docker.DockerDesktop)"
        Write-Host "Il demande WSL 2 et la virtualisation activée dans le BIOS/UEFI. Relancez ensuite up.cmd."
        exit 1
    }
    if ((Invoke-Quiet docker @('compose', 'version')) -ne 0) {
        Write-Host "Le plugin « docker compose » est introuvable. Mettez Docker Desktop à jour."
        exit 1
    }
    if ((Invoke-Quiet docker @('info')) -ne 0 -and (Test-VirtualizationOff)) {
        Write-Host "La virtualisation est désactivée dans le BIOS/UEFI de ce PC : Docker Desktop ne peut pas démarrer."
        Write-Host "Redémarrez, entrez dans le BIOS (souvent F2, F10, Suppr ou Échap au démarrage) et activez"
        Write-Host "« Intel Virtualization Technology (VT-x) » ou « SVM / AMD-V ». Relancez ensuite up.cmd."
        exit 1
    }
    if ((Invoke-Quiet docker @('info')) -ne 0 -and $DockerDesktop) {
        # Une mise à jour de WSL (Windows Update) arrête la machine virtuelle de Docker Desktop, qui
        # n'arrive pas à redémarrer tant qu'elle n'est pas finie (« Docker Desktop is unable to start »).
        if (-not (Wait-WslReady)) {
            Write-Host "WSL (utilisé par Docker Desktop) est en cours de mise à jour ou ne répond pas."
            Write-Host "Attendez quelques minutes (ou redémarrez l'ordinateur), puis relancez up.cmd."
            exit 1
        }
        if (Get-Process 'Docker Desktop' -ErrorAction SilentlyContinue) {
            # Docker Desktop est ouvert mais son moteur ne répond pas : on lui laisse 1 minute, puis on
            # le redémarre (arrêt complet, arrêt de WSL, relance).
            Write-Host "Docker Desktop est ouvert mais son moteur ne répond pas ; attente..."
            for ($i = 0; $i -lt 12 -and (Invoke-Quiet docker @('info')) -ne 0; $i++) { Start-Sleep -Seconds 5 }
            if ((Invoke-Quiet docker @('info')) -ne 0) {
                Write-Host "Redémarrage de Docker Desktop..."
                Get-Process 'Docker Desktop', 'com.docker.backend', 'com.docker.build', 'docker-agent' -ErrorAction SilentlyContinue | Stop-Process -Force -ErrorAction SilentlyContinue
                Start-Sleep -Seconds 5
                Invoke-Quiet wsl.exe @('--shutdown') | Out-Null
                Start-DockerDesktop
            }
        } else {
            Write-Host "Démarrage de Docker Desktop..."
            Start-DockerDesktop
        }
        for ($i = 0; $i -lt 60 -and (Invoke-Quiet docker @('info')) -ne 0; $i++) { Start-Sleep -Seconds 5 }
    }
    if ((Invoke-Quiet docker @('info')) -ne 0) {
        Write-Host "Docker ne répond pas."
        Write-Host "  1. Clic droit sur l'icône Docker (près de l'horloge) > Quit Docker Desktop ;"
        Write-Host "  2. dans PowerShell : wsl --update   puis   wsl --shutdown ;"
        Write-Host "  3. rouvrez Docker Desktop, attendez « Engine running », puis relancez up.cmd."
        Write-Host "  Si cela ne suffit pas, redémarrez l'ordinateur."
        exit 1
    }
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $ostype = (& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    $ErrorActionPreference = $old
    if ($ostype -ne 'linux') {
        Write-Host "Docker Desktop utilise des conteneurs Windows. Clic droit sur l'icône Docker > « Switch to Linux containers », puis relancez."
        exit 1
    }
}

# Dossier synchronisé par OneDrive : la synchronisation (et les fichiers « à la demande ») peut
# bloquer ou corrompre les fichiers pendant la construction des images.
if ($PSScriptRoot -match '\\OneDrive( - [^\\]+)?\\') {
    Write-Host "Attention : ce dossier est synchronisé par OneDrive ($PSScriptRoot)."
    Write-Host "Si l'installation échoue, déplacez le dossier SwitchBoard hors de OneDrive (ex. : C:\SwitchBoard)."
}

# Une autre copie de SwitchBoard (autre dossier) utilise déjà les mêmes conteneurs (sb_*) : la
# démarrer ici remplacerait ses conteneurs par ceux-ci, avec une autre base de données. Vérifié avant
# tout le reste (.env compris) pour donner la vraie raison.
if ((Get-Command docker -ErrorAction SilentlyContinue) -and (Invoke-Quiet docker @('info')) -eq 0) {
    $here = (Resolve-Path -LiteralPath $PSScriptRoot).Path.TrimEnd('\')
    foreach ($c in 'sb_php-fpm', 'sb_mariadb') {
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        # Étiquettes lues en JSON : un format avec des guillemets (index ... "com.docker...") serait mal
        # transmis à docker.exe par Windows PowerShell 5.1.
        $labels = (& docker inspect -f '{{json .Config.Labels}}' $c 2>$null | Out-String).Trim()
        $ErrorActionPreference = $old
        if ($LASTEXITCODE -ne 0 -or -not $labels -or $labels -eq 'null') { continue }
        $other = ($labels | ConvertFrom-Json).'com.docker.compose.project.working_dir'
        if (-not $other) { continue }
        if (-not (Test-Path -LiteralPath $other)) {
            Write-Host "Le conteneur $c vient d'une installation dont le dossier n'existe plus ($other)."
            Write-Host 'Supprimez ses conteneurs :  docker rm -f sb_letsencrypt sb_nginx-proxy sb_web sb_web_server sb_php-fpm sb_mariadb'
            Write-Host 'puis relancez up.cmd.'
            exit 1
        }
        if ((Resolve-Path -LiteralPath $other).Path.TrimEnd('\') -ne $here) {
            Write-Host "Une autre installation de SwitchBoard existe déjà sur cet ordinateur : $other"
            Write-Host 'Une seule peut fonctionner à la fois (mêmes noms de conteneurs). Utilisez ce dossier-là,'
            Write-Host "ou arrêtez-la d'abord (dans ce dossier-là : docker compose down)."
            exit 1
        }
    }
}

# --- .env : format, fichier temporaire, données existantes ---------------------------------------
$EnvCreation = Join-Path $PSScriptRoot '.env.creation'
Remove-Item -LiteralPath $EnvCreation -Force -ErrorAction SilentlyContinue
# .env enregistré par le Bloc-notes (BOM, CRLF) : Docker Compose lirait ces caractères comme faisant
# partie des valeurs (« localhost\r », mot de passe faux...).
if (Test-Path -LiteralPath $EnvFile) {
    $raw = [IO.File]::ReadAllBytes($EnvFile)
    $hasBom = $raw.Length -ge 3 -and $raw[0] -eq 0xEF -and $raw[1] -eq 0xBB -and $raw[2] -eq 0xBF
    if ($hasBom -or ($raw -contains 13)) {
        $text = [IO.File]::ReadAllText($EnvFile, $Utf8).TrimStart([char]0xFEFF).Replace("`r", '')
        [IO.File]::WriteAllText($EnvFile, $text, $Utf8)
        Write-Host '.env : format Windows (BOM / CRLF) corrigé.'
    }
}
# Nom d'hôte tel qu'on le tape souvent (« https://SB.exemple.com/ ») : minuscules, sans schéma,
# chemin, port ni espaces.
function Get-CleanHost([string]$Value) {
    $h = ("$Value" -replace '\s', '').ToLower()
    $h = $h -replace '^[a-z]*://', '' -replace '[/:?#].*$', ''
    return $h.TrimEnd('.')
}
# Problème d'un nom d'hôte (chaîne vide s'il est valide).
function Get-HostProblem([string]$Value) {
    if (-not $Value) { return "le nom d'hôte est vide" }
    if ($Value -match '^[0-9.]+$') { return "« $Value » est une adresse IP : pour un accès par adresse IP, utilisez localhost (SwitchBoard répond aussi à l'adresse IP du PC)" }
    if ($Value -notmatch '^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)*[a-z0-9]([a-z0-9-]*[a-z0-9])?$') { return "« $Value » n'est pas un nom de domaine valide (ex. : sb.mondomaine.com, ou localhost)" }
    return ''
}
if ($HostName) {
    $HostName = Get-CleanHost $HostName
    $problem = Get-HostProblem $HostName
    if ($problem) { Write-Host "-HostName : $problem."; exit 1 }
}
# .env supprimé alors qu'une base de données existe déjà (volume Docker) : en générer un nouveau
# créerait d'autres mots de passe (la base les refuserait) et une autre clé de chiffrement.
if (-not (Test-Path -LiteralPath $EnvFile) -and (Get-Command docker -ErrorAction SilentlyContinue)) {
    $project = ([IO.File]::ReadAllText((Join-Path $PSScriptRoot '.env_sample')) -split "`r?`n" | Where-Object { $_ -like 'COMPOSE_PROJECT_NAME=*' } | Select-Object -First 1) -replace '^COMPOSE_PROJECT_NAME=', ''
    if ($project -and (Invoke-Quiet docker @('volume', 'inspect', "$($project.Trim())_sb_db")) -eq 0) {
        Write-Host "Le fichier .env est introuvable, mais une base de données SwitchBoard existe déjà (volume Docker $($project.Trim())_sb_db)."
        Write-Host 'Un nouveau .env aurait d''autres mots de passe et une autre clé de chiffrement : la base les refuserait'
        Write-Host 'et les secrets enregistrés seraient perdus.'
        Write-Host '  - Remettez votre sauvegarde de .env dans ce dossier, puis relancez up.cmd ;'
        Write-Host '  - si cette base appartient à une autre copie de SwitchBoard (autre dossier), lancez up.cmd depuis ce dossier-là ;'
        Write-Host "  - ou, pour TOUT recommencer en effaçant les données : docker compose down -v  (dans ce dossier,"
        Write-Host "    avec un .env), ou : docker volume rm $($project.Trim())_sb_db $($project.Trim())_sb_web   puis relancez up.cmd."
        exit 1
    }
}

# --- Première configuration ------------------------------------------------------------------
if (-not (Test-Path -LiteralPath $EnvFile)) {
  # Tout est écrit dans un fichier temporaire, renommé en .env seulement à la fin : une
  # interruption (Ctrl+C, fenêtre fermée) ne laisse jamais un .env à moitié rempli.
  $EnvFinal = $EnvFile; $EnvFile = $EnvCreation
  try {
    if (-not (Test-Path -LiteralPath (Join-Path $PSScriptRoot '.env_sample'))) { Write-Host 'Erreur : .env_sample introuvable.'; exit 1 }
    if ($NonInteractive) { Write-Host 'Première utilisation : configuration automatique (sans question).' }
    else { Write-Host 'Première utilisation : quelques questions rapides pour configurer SwitchBoard.' }
    Write-Host "(Vous pourrez toujours modifier ces réglages plus tard directement dans $EnvFinal.)"
    [IO.File]::WriteAllText($EnvFile, [IO.File]::ReadAllText((Join-Path $PSScriptRoot '.env_sample'), $Utf8).Replace("`r`n", "`n"), $Utf8)
    foreach ($key in 'MYSQL_ROOT_PASSWORD', 'MYSQL_PASSWORD') { Set-EnvValue $key ((New-Secret 24) -replace '[=+/]', '') }
    Set-EnvValue 'APP_ENCRYPTION_KEY' (New-Secret 32)
    Write-Host 'Mots de passe de la base de données et clé de chiffrement générés aléatoirement.'
    Write-Host 'Sauvegardez .env avec vos sauvegardes : sans APP_ENCRYPTION_KEY, les secrets enregistrés sont perdus.'
    if (-not $TimeZone) { $TimeZone = Get-IanaTimeZone }
    Set-EnvValue 'TZ' $TimeZone

    if (-not $NonInteractive) {
        while ($true) {
            if (-not $HostName) { $HostName = Read-Host "Nom de domaine (ex.: sb.mondomaine.com), ou Entrée pour 'localhost' (test local ou réseau local)" }
            if (-not $HostName) { $HostName = 'localhost' }
            $HostName = Get-CleanHost $HostName
            $problem = Get-HostProblem $HostName
            if (-not $problem) { break }
            Write-Host "  $problem."
            $HostName = ''
        }
        if (-not $Email) { $Email = Read-Host "Adresse courriel pour les certificats TLS (Let's Encrypt)" }
        Write-Host 'Courriel : configurez-le plus tard dans .env (SB_SMTP_*) ou depuis la console admin (Courriel OAuth).'
    }
    if (-not $HostName) { $HostName = 'localhost' }
    Set-EnvValue 'VIRTUAL_HOST' $HostName
    Set-EnvValue 'DEFAULT_EMAIL' $Email
    foreach ($key in 'SB_SMTP_HOST', 'SB_SMTP_PORT', 'SB_SMTP_USER', 'SB_SMTP_PASSWORD', 'SB_SMTP_FROM', 'SB_SMTP_TLS',
                     'SB_SMTP_OAUTH_PROVIDER', 'SB_SMTP_OAUTH_CLIENT_ID', 'SB_SMTP_OAUTH_CLIENT_SECRET', 'SB_SMTP_OAUTH_TENANT') {
        Set-EnvValue $key ([Environment]::GetEnvironmentVariable($key))
    }
    Move-Item -LiteralPath $EnvFile -Destination $EnvFinal -Force
  } finally {
    $EnvFile = $EnvFinal
    Remove-Item -LiteralPath $EnvCreation -Force -ErrorAction SilentlyContinue
  }
  Write-Host "Configuration initiale terminée ($EnvFile créé)."
}

# --- Nom d'hôte (VIRTUAL_HOST) : nettoyé et vérifié à chaque lancement (il peut venir de -HostName,
# de la question ou d'une modification à la main de .env).
$envHost = Get-EnvValue 'VIRTUAL_HOST'
$cleanHost = Get-CleanHost $envHost
$problem = Get-HostProblem $cleanHost
if ($problem) {
    Write-Host "VIRTUAL_HOST dans .env : $problem."
    Write-Host 'Corrigez-le (VIRTUAL_HOST=sb.mondomaine.com ou VIRTUAL_HOST=localhost), puis relancez up.cmd.'
    exit 1
}
if ($cleanHost -ne $envHost) { Set-EnvValue 'VIRTUAL_HOST' $cleanHost; Write-Host "VIRTUAL_HOST corrigé : « $envHost » devient « $cleanHost »." }

# --- Clé de chiffrement (APP_ENCRYPTION_KEY) : vérifiée à chaque lancement ---------------------
# SwitchBoard ignore sans rien dire une clé absente ou mal formée, et enregistre alors les secrets
# (voip.ms, SB Chat, MAS, courriel) en clair. Une clé vide est générée : rien n'a pu être chiffré
# sans clé. Format attendu : 32 octets en base64 (44 caractères).
$KeyGenerated = $false
$key = (Get-EnvValue 'APP_ENCRYPTION_KEY') -replace '[\s"'']', ''
if (-not $key) {
    Set-EnvValue 'APP_ENCRYPTION_KEY' (New-Secret 32)
    $KeyGenerated = $true
    Write-Host 'Clé de chiffrement (APP_ENCRYPTION_KEY) absente de .env : une clé aléatoire a été générée.'
    Write-Host 'Sauvegardez .env avec vos sauvegardes : sans cette clé, les secrets enregistrés sont perdus.'
} elseif ($key -cnotmatch '^[A-Za-z0-9+/]{42}[AEIMQUYcgkosw048]=?$') {
    Write-Host 'APP_ENCRYPTION_KEY dans .env n''est pas une clé valide (32 octets en base64, 44 caractères) :'
    Write-Host 'SwitchBoard l''ignorerait et enregistrerait les secrets en clair.'
    Write-Host '  - si vous avez une sauvegarde de .env, remettez-y la clé d''origine ;'
    Write-Host '  - sinon, videz la ligne (APP_ENCRYPTION_KEY=) et relancez up.cmd : une clé sera générée.'
    exit 1
}

# --- Jeton GitHub : facultatif pour le dépôt public, vérifié s'il est donné -------------------
# Le dépôt SwitchBoard est public : le conteneur php le télécharge sans jeton. Un jeton ne sert qu'à
# une copie privée du dépôt, ou à lever la limite de GitHub pour les requêtes sans jeton. On vérifie
# l'accès auprès de GitHub avant de construire quoi que ce soit, par la même requête que
# « git clone » (lecture du dépôt), plutôt que de le découvrir dans le journal de php.
$RepoRefs = 'https://github.com/Switchboarders/SwitchBoard.git/info/refs?service=git-upload-pack'
# Lit le dépôt avec ce jeton (vide : sans jeton). Renvoie ok, refuse (401, 403, 404), reseau ou tls.
function Get-RepoRead([string]$Token) {
    try { [Net.ServicePointManager]::SecurityProtocol = [Net.ServicePointManager]::SecurityProtocol -bor [Net.SecurityProtocolType]::Tls12 } catch { }
    $headers = @{}
    if ($Token) {
        $auth = [Convert]::ToBase64String([Text.Encoding]::ASCII.GetBytes("x-access-token:$Token"))
        $headers = @{ Authorization = "Basic $auth" }
    }
    try {
        $r = Invoke-WebRequest -UseBasicParsing -Uri $RepoRefs -Headers $headers -TimeoutSec 20
        if ($r.StatusCode -eq 200) { return 'ok' } else { return 'refuse' }
    } catch {
        $ex = $_.Exception
        while ($ex) {
            if ($ex -is [Net.WebException] -and $ex.Status -in @([Net.WebExceptionStatus]::TrustFailure, [Net.WebExceptionStatus]::SecureChannelFailure)) { return 'tls' }
            if ($ex -is [Security.Authentication.AuthenticationException]) { return 'tls' }
            $ex = $ex.InnerException
        }
        $resp = $_.Exception.Response
        if ($resp) {
            $code = [int]$resp.StatusCode
            if ($code -in 401, 403, 404) { return 'refuse' }
        }
        return 'reseau'
    }
}
# Renvoie ok (le jeton lit le dépôt), public (aucun jeton, dépôt public), vide (aucun jeton, dépôt
# privé), refuse_public (jeton refusé, mais le dépôt est public : on continue sans lui), refuse (jeton
# refusé et dépôt privé), reseau ou tls.
function Get-TokenState([string]$Token) {
    if (-not $Token) {
        $state = Get-RepoRead ''
        if ($state -eq 'ok') { return 'public' }
        if ($state -eq 'refuse') { return 'vide' }
        return $state
    }
    if ($Token -notmatch '^[A-Za-z0-9_]+$') { $state = 'refuse' } else { $state = Get-RepoRead $Token }
    if ($state -eq 'refuse' -and (Get-RepoRead '') -eq 'ok') { return 'refuse_public' }
    return $state
}
function Write-TokenProblem([string]$State) {
    switch ($State) {
        'vide' { Write-Host "Le dépôt SwitchBoard n'est pas public : un jeton GitHub est nécessaire pour le télécharger." }
        'refuse' { Write-Host 'GitHub refuse ce jeton : inconnu, expiré, ou sans accès en lecture au dépôt Switchboarders/SwitchBoard.' }
        'reseau' { Write-Host 'Impossible de joindre GitHub. Vérifiez la connexion Internet de ce PC.' }
        'tls' { Write-Host "La connexion sécurisée à GitHub échoue. Vérifiez la date et l'heure de ce PC (actuellement : $(Get-Date -Format 'yyyy-MM-dd HH:mm')), ou un antivirus/proxy qui intercepterait le trafic." }
    }
}
function Write-TokenHelp {
    Write-Host 'Créez un jeton sur GitHub : photo de profil > Settings > Developer settings > Personal access tokens'
    Write-Host '> Fine-grained tokens, avec l''accès en lecture à « Contents » et « Metadata » sur le dépôt'
    Write-Host '(ou un jeton classique avec le droit « repo »). Voir README.md#fr-before-you-start.'
}
# SwitchBoard déjà téléchargé (installation terminée) : le jeton ne sert plus qu'aux mises à jour.
function Test-AlreadyInstalled {
    if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { return $false }
    return ((Invoke-Quiet docker @('exec', 'sb_php-fpm', 'test', '-f', '/var/www/html/SwitchBoard/SwitchBoard/config/configempty.php')) -eq 0)
}
function Read-Token([string]$Prompt) {
    $secure = Read-Host $Prompt -AsSecureString
    return ([System.Net.NetworkCredential]::new('', $secure).Password -replace '[\s"'']', '')
}

# Un jeton fourni pour ce lancement (SB_GITHUB_TOKEN) remplace celui de .env.
$Token = "$env:SB_GITHUB_TOKEN"
if (-not $Token) { $Token = Get-EnvValue 'GITHUB_TOKEN' }
$Token = $Token -replace '[\s"'']', ''
Write-Host 'Vérification de l''accès au dépôt GitHub...'
$TokenState = Get-TokenState $Token
while ($TokenState -notin @('ok', 'public', 'refuse_public')) {
    $installed = Test-AlreadyInstalled
    Write-Host ''
    Write-TokenProblem $TokenState
    if ($NonInteractive) {
        if ($installed) {
            Write-Host 'SwitchBoard est déjà installé : on continue, mais les mises à jour automatiques échoueront'
            Write-Host 'tant que GITHUB_TOKEN (dans .env) n''est pas valide.'
            break
        }
        Write-TokenHelp
        Write-Host 'Mettez-le dans .env (GITHUB_TOKEN=...) ou dans la variable SB_GITHUB_TOKEN, puis relancez up.cmd.'
        exit 1
    }
    Write-TokenHelp
    if ($installed) {
        $answer = Read-Token 'Jeton GitHub (Entrée pour continuer sans le changer)'
        if (-not $answer) { Write-Host 'Jeton inchangé : les mises à jour automatiques échoueront tant qu''il n''est pas valide.'; break }
    } else {
        $answer = Read-Token 'Jeton GitHub (Ctrl+C pour quitter)'
    }
    $Token = $answer
    $TokenState = Get-TokenState $Token
}
switch ($TokenState) {
    'ok' {
        Write-Host 'Jeton GitHub valide.'
        if ($Token -ne (Get-EnvValue 'GITHUB_TOKEN')) { Set-EnvValue 'GITHUB_TOKEN' $Token }
    }
    'public' { Write-Host "Dépôt public : aucun jeton GitHub n'est nécessaire." }
    'refuse_public' {
        # Jamais enregistré : un jeton refusé ne sert à rien
        Write-Host 'GitHub refuse ce jeton (inconnu, expiré ou révoqué) : il est ignoré, le dépôt est public.'
        Write-Host 'Retirez-le de .env (GITHUB_TOKEN=) ou remplacez-le.'
    }
}

$NginxConf = Join-Path $PSScriptRoot 'nginx\nginx.conf'
if (-not (Test-Path -LiteralPath $NginxConf)) {
    Copy-Item -LiteralPath (Join-Path $PSScriptRoot 'nginx\nginx.conf.sample') -Destination $NginxConf
    Write-Host 'nginx\nginx.conf créé à partir de nginx.conf.sample.'
}

if (-not (Get-EnvValue 'COMPOSE_FILE')) {
    if (-not $NonInteractive -and -not $Mode) {
        Write-Host 'Premier démarrage : quel mode utiliser ?'
        Write-Host '  1) Autonome - SwitchBoard gère son propre reverse-proxy + TLS, accessible sur le réseau local'
        Write-Host '  2) Reverse-proxy externe - un autre service gère déjà les ports 80/443'
        if ((Read-Host 'Choix [1/2]') -eq '2') { $ModeFile = 'docker-compose.reverse-proxy.yml' }
    }
    # Le séparateur de COMPOSE_FILE est « ; » sous Windows.
    Set-EnvValue 'COMPOSE_FILE' "$ModeFile;docker-compose.windows.yml"
    Write-Host "Mode enregistré dans .env (COMPOSE_FILE=$ModeFile;docker-compose.windows.yml)."
}
# Sans docker-compose.windows.yml (.env écrit à la main ou venu de Linux), la base irait dans le
# dossier Windows db_data, où MariaDB échoue à modifier ses tables (erreur 500 à la création du
# compte admin). On l'ajoute, sauf si une base existe déjà dans db_data : elle serait abandonnée.
if ((Get-EnvValue 'COMPOSE_FILE') -notmatch 'docker-compose\.windows\.yml') {
    if (Test-Path -LiteralPath (Join-Path $PSScriptRoot 'db_data\mysql')) {
        Write-Host ''
        Write-Host "COMPOSE_FILE (dans $EnvFile) n'inclut pas docker-compose.windows.yml : la base de données est"
        Write-Host 'dans le dossier Windows db_data, où MariaDB ne fonctionne pas correctement (par exemple :'
        Write-Host 'erreur 500 à la création du compte admin).'
        Write-Host 'Si cette installation ne contient encore rien à garder :'
        Write-Host '  1) docker compose down'
        Write-Host '  2) renommez le dossier db_data (par exemple en db_data.ancien)'
        Write-Host '  3) relancez up.cmd'
        Write-Host 'Sinon, ne supprimez rien et demandez de l''aide avec le rapport de diagnostic.cmd.'
        exit 1
    }
    Set-EnvValue 'COMPOSE_FILE' "$((Get-EnvValue 'COMPOSE_FILE').TrimEnd(';'));docker-compose.windows.yml"
    Write-Host 'docker-compose.windows.yml ajouté à COMPOSE_FILE dans .env (base et code dans des volumes Docker).'
}
$ActiveMode = Get-EnvValue 'COMPOSE_FILE'
$Standalone = $ActiveMode -like '*standalone*'
$WebContainer = if ($Standalone) { 'sb_web' } else { 'sb_web_server' }
$Lan = @(Get-LanAddresses)
if ($Standalone) { Set-EnvValue 'SB_CERT_SANS' ((@($env:COMPUTERNAME.ToLower(), "$($env:COMPUTERNAME.ToLower()).local") + $Lan) -join ',') }
Write-Host "Mode actif : $ActiveMode"
if ($PrepareOnly) { Write-Host 'Configuration prête (-PrepareOnly : Docker non démarré).'; exit 0 }

if ((Invoke-Quiet docker @('network', 'inspect', 'web')) -ne 0) {
    & docker network create web | Out-Null
    Write-Host 'Réseau Docker « web » créé.'
}

if ($Standalone) {
    $running = (& docker ps --format '{{.Names}}') -contains 'sb_nginx-proxy'
    if (-not $running) {
        foreach ($port in 80, 443) {
            $client = New-Object Net.Sockets.TcpClient
            try {
                if ($client.ConnectAsync('127.0.0.1', $port).Wait(500)) {
                    Write-Host "Le port $port est déjà utilisé sur cet ordinateur (IIS, Skype, un autre serveur web ?)."
                    Write-Host 'Libérez-le, ou utilisez le mode reverse-proxy externe (retirez COMPOSE_FILE de .env puis : .\up.ps1 -Mode reverse-proxy).'
                    exit 1
                }
            } catch { } finally { $client.Dispose() }
        }
    }
    # Accès depuis le réseau local : le pare-feu Windows doit laisser entrer 80/443.
    if (-not $NoFirewall) {
        $isAdmin = ([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
        $rule = Get-NetFirewallRule -DisplayName 'SwitchBoard HTTP/HTTPS' -ErrorAction SilentlyContinue
        if (-not $rule -and $isAdmin) {
            New-NetFirewallRule -DisplayName 'SwitchBoard HTTP/HTTPS' -Direction Inbound -Protocol TCP -LocalPort 80, 443 -Action Allow -Profile Private, Domain | Out-Null
            Write-Host 'Pare-feu Windows : ports 80 et 443 ouverts pour les réseaux privés.'
        } elseif (-not $rule) {
            Write-Host "Pour l'accès depuis le réseau local, relancez une fois up.cmd en tant qu'administrateur (ouvre les ports 80/443 du pare-feu)."
        }
        $public = Get-NetConnectionProfile -ErrorAction SilentlyContinue | Where-Object { $_.NetworkCategory -eq 'Public' }
        if ($public) { Write-Host "Votre réseau « $($public[0].Name) » est classé Public : réglez-le à Privé (Paramètres > Réseau) pour l'accès depuis le réseau local." }
    }
}

# Lancé par SSH, Docker ne peut pas lire le Gestionnaire d'identification Windows (« error getting
# credentials ... ouverture de session ... n'existe pas »), même pour les images publiques : sans
# magasin configuré, il utilise docker-credential-wincred dès qu'il le trouve dans le PATH. Les images
# de SwitchBoard sont publiques : on utilise alors une configuration sans magasin d'identifiants et
# une copie de docker.exe hors du dossier qui contient docker-credential-wincred.
$userDockerDir = Join-Path $env:USERPROFILE '.docker'
if (($env:SSH_CONNECTION -or $env:SSH_CLIENT) -and -not $env:DOCKER_CONFIG) {
    $savedPath = $env:Path
    try {
        $cfg = New-Object PSObject
        $userDockerConfig = Join-Path $userDockerDir 'config.json'
        if (Test-Path -LiteralPath $userDockerConfig) { $cfg = [IO.File]::ReadAllText($userDockerConfig) | ConvertFrom-Json }
        $cfg.PSObject.Properties.Remove('credsStore'); $cfg.PSObject.Properties.Remove('credHelpers')
        $plugins = Join-Path $userDockerDir 'cli-plugins'
        if (Test-Path -LiteralPath $plugins) {
            $extra = @($plugins); if ($cfg.PSObject.Properties['cliPluginsExtraDirs']) { $extra += $cfg.cliPluginsExtraDirs }
            $cfg | Add-Member -Force -NotePropertyName cliPluginsExtraDirs -NotePropertyValue $extra
        }
        $sshDir = Join-Path $env:LOCALAPPDATA 'SwitchBoard\docker-ssh'
        $sshConfig = Join-Path $sshDir 'config'
        $sshBin = Join-Path $sshDir 'bin'
        New-Item -ItemType Directory -Force -Path $sshConfig, $sshBin | Out-Null
        [IO.File]::WriteAllText((Join-Path $sshConfig 'config.json'), ($cfg | ConvertTo-Json -Depth 20), $Utf8)
        $contexts = Join-Path $userDockerDir 'contexts'
        if (Test-Path -LiteralPath $contexts) { Copy-Item -Recurse -Force -LiteralPath $contexts -Destination $sshConfig }
        $helperDirs = @(Get-Command docker-credential-wincred -All -ErrorAction SilentlyContinue | ForEach-Object { Split-Path $_.Source })
        if ($helperDirs) {
            Copy-Item -Force -LiteralPath (Get-Command docker).Source -Destination $sshBin
            $env:Path = (@($sshBin) + @($env:Path -split ';' | Where-Object { $_ -and ($helperDirs -notcontains $_.TrimEnd('\')) })) -join ';'
        }
        $env:DOCKER_CONFIG = $sshConfig
        if ((Invoke-Quiet docker @('compose', 'version')) -ne 0 -or (Invoke-Quiet docker @('info')) -ne 0) { throw 'docker' }
        Write-Host 'Session SSH : Docker utilisé sans le Gestionnaire d''identification Windows (images publiques).'
    } catch {
        $env:Path = $savedPath
        Remove-Item Env:DOCKER_CONFIG -ErrorAction SilentlyContinue
    }
}

# Construction d'abord, avec un second essai (certaines versions de Docker échouent parfois en
# construisant deux images en même temps, alors qu'un nouvel essai réussit).
# La sortie de Docker est gardée pour expliquer un échec en mots simples.
$DockerLog = [IO.Path]::GetTempFileName()
function Invoke-DockerLogged([string[]]$Arguments) {
    Set-Content -LiteralPath $DockerLog -Value '' -Encoding UTF8
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & docker @Arguments 2>&1 | ForEach-Object { $line = "$_"; Write-Host $line; Add-Content -LiteralPath $DockerLog -Value $line -Encoding UTF8 }
    $code = $LASTEXITCODE
    $ErrorActionPreference = $old
    return $code
}
function Write-DockerFailure {
    $log = Get-Content -Raw -LiteralPath $DockerLog -ErrorAction SilentlyContinue
    Write-Host ''
    if ((Invoke-Quiet docker @('info')) -ne 0) {
        Write-Host "Docker s'est arrêté pendant l'installation (mise à jour de WSL ou de Windows ?). Relancez up.cmd :"
        Write-Host "il redémarre Docker Desktop au besoin, et l'installation reprend où elle en était."
    } elseif ($log -match 'no space left on device') {
        Write-Host "Le disque est plein. Libérez de l'espace (Docker Desktop > Troubleshoot > Clean / Purge data, ou"
        Write-Host '« docker system prune -a » pour les images inutilisées), puis relancez up.cmd.'
    } elseif ($log -match 'toomanyrequests|rate limit') {
        Write-Host 'Docker Hub limite le nombre de téléchargements depuis votre adresse. Attendez une heure, ou'
        Write-Host 'connectez-vous dans Docker Desktop avec un compte Docker Hub gratuit, puis relancez up.cmd.'
    } elseif ($log -match 'x509|certificate (has expired|is not yet valid)') {
        Write-Host "Erreur de certificat pendant un téléchargement. Vérifiez la date et l'heure de ce PC"
        Write-Host "(actuellement : $(Get-Date -Format 'yyyy-MM-dd HH:mm')) ou un antivirus/proxy, puis relancez up.cmd."
    } elseif ($log -match 'no such host|i/o timeout|TLS handshake timeout|network is unreachable|temporary failure in name resolution|could not resolve|connection reset|unexpected EOF') {
        Write-Host 'Un téléchargement a échoué (réseau). Vérifiez la connexion Internet de ce PC, puis relancez up.cmd :'
        Write-Host 'ce qui est déjà téléchargé est gardé.'
    } elseif ($log -match 'port is already allocated|address already in use|ports are not available') {
        Write-Host 'Le port 80 ou 443 est déjà utilisé par un autre programme (IIS, Skype...). Libérez-le, ou utilisez'
        Write-Host 'le mode reverse-proxy externe (retirez COMPOSE_FILE de .env puis : .\up.ps1 -Mode reverse-proxy).'
    } elseif ($log -match 'is already in use by container|Conflict\. The container name') {
        Write-Host "Des conteneurs SwitchBoard (sb_*) d'une autre installation existent déjà. Supprimez-les :"
        Write-Host '  docker rm -f sb_letsencrypt sb_nginx-proxy sb_web sb_web_server sb_php-fpm sb_mariadb'
        Write-Host 'puis relancez up.cmd (les données de cette installation-ci ne sont pas touchées).'
    } else {
        Write-Host "Voyez les messages ci-dessus. Relancez up.cmd ; si l'erreur revient, joignez ces messages à votre"
        Write-Host "demande d'aide."
    }
}
$code = Invoke-DockerLogged @('compose', 'build')
if ($code -ne 0) {
    # Pas de second essai quand il ne peut pas réussir.
    $log = Get-Content -Raw -LiteralPath $DockerLog -ErrorAction SilentlyContinue
    if ((Invoke-Quiet docker @('info')) -eq 0 -and $log -notmatch 'no space left on device|toomanyrequests') {
        Write-Host 'La construction des images a échoué ; nouvel essai...'
        $code = Invoke-DockerLogged @('compose', 'build')
    }
    if ($code -ne 0) { Write-Host ''; Write-Host 'La construction des images de SwitchBoard a échoué.'; Write-DockerFailure; exit 1 }
}
$code = Invoke-DockerLogged @('compose', 'up', '-d')
if ($code -ne 0) { Write-Host ''; Write-Host 'Le démarrage des conteneurs de SwitchBoard a échoué.'; Write-DockerFailure; exit 1 }
Remove-Item -LiteralPath $DockerLog -Force -ErrorAction SilentlyContinue

if (-not $NoWait) {
    Write-Host 'Préparation de SwitchBoard (le premier démarrage peut prendre quelques minutes)...'
    $ready = $false
    # On attend tant que le conteneur php avance ; abandon après 15 minutes sans progrès ou 60 au total.
    $lastState = ''; $idle = 0; $dbRefusals = 0
    # La base accepte-t-elle les identifiants de .env ? ok, refus ou attente (pas encore prête). Ils
    # ne servent qu'à la création de la base : les changer ensuite (ou recréer .env) les rend faux.
    # Vérifié seulement une fois le vrai serveur prêt (pendant la création, un serveur temporaire
    # sans réseau, « port: 0 », peut refuser une connexion).
    function Get-DbState {
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        try {
            $dbLogs = (& docker logs sb_mariadb 2>&1 | Out-String)
            if ($dbLogs -notmatch '(?m)^Version: .*port: 3306') { return 'attente' }
            # Sans guillemets dans la commande : Windows PowerShell 5.1 les transmet mal à docker.exe
            # (« SELECT 1 » devenait « SELECT »). set -f et IFS vide : pas de découpage des valeurs.
            $out = (& docker exec sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -e status $MYSQL_DATABASE' 2>&1 | Out-String)
            if ($out -match 'Access denied|Unknown database') { return 'refus' }
            if ($out -match 'Current database') { return 'ok' }
            return 'attente'
        } finally { $ErrorActionPreference = $old }
    }
    function Write-DbRefused {
        Write-Host ''
        Write-Host 'La base de données refuse les identifiants de .env (MYSQL_USER, MYSQL_PASSWORD ou MYSQL_DATABASE).'
        Write-Host 'Ils ne servent qu''à la création de la base : s''ils ont été changés depuis, ou si .env a été recréé,'
        Write-Host 'remettez les anciennes valeurs (sauvegarde de .env), puis relancez up.cmd.'
    }
    for ($i = 0; $i -lt 720; $i++) {
        if ((Invoke-Quiet docker @('exec', 'sb_php-fpm', 'test', '-f', '/var/www/html/SwitchBoard/SwitchBoard/config/config.php')) -eq 0 -and
            (Invoke-Quiet docker @('exec', $WebContainer, 'wget', '-q', '-O', '/dev/null', '--header', 'X-Forwarded-Proto: https',
                                   'http://127.0.0.1/SwitchBoard/SwitchBoard/src/managementportal.php')) -eq 0) {
            # La page répond ; la base doit aussi accepter les identifiants.
            $db = Get-DbState
            if ($db -eq 'ok') { $ready = $true; break }
            if ($db -eq 'refus') { Write-DbRefused; exit 1 }
        }
        if ((Invoke-Quiet docker @('info')) -ne 0) {
            Write-Host ''
            Write-Host "Docker s'est arrêté pendant le premier démarrage (mise à jour de WSL ou de Windows ?). Relancez up.cmd :"
            Write-Host "il redémarre Docker Desktop au besoin, et l'installation reprend."
            exit 1
        }
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $oom = (& docker inspect -f '{{.State.OOMKilled}}' sb_php-fpm 2>$null | Out-String).Trim()
        $tail20 = (& docker logs --tail 20 sb_php-fpm 2>&1 | Out-String)
        $ErrorActionPreference = $old
        if ($oom -eq 'true' -or $tail20 -match '(?m)(^|[^a-z])Killed\s*$|Allowed memory size') {
            Write-Host ''
            Write-Host 'Le conteneur php manque de mémoire. Dans Docker Desktop : Settings > Resources (ou fichier .wslconfig),'
            Write-Host 'donnez au moins 2 Go de mémoire, fermez d''autres programmes, puis relancez up.cmd.'
            exit 1
        }
        if ($i % 6 -eq 5) {
            # Deux refus de suite (30 s d'écart) avant de conclure.
            if ((Get-DbState) -eq 'refus') { $dbRefusals++ } else { $dbRefusals = 0 }
            if ($dbRefusals -ge 2) { Write-DbRefused; exit 1 }
            $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
            $dbRestarts = (& docker inspect -f '{{.RestartCount}}' sb_mariadb 2>$null | Out-String).Trim()
            $ErrorActionPreference = $old
            if ($dbRestarts -and [int]$dbRestarts -ge 3) {
                Write-Host ''
                Write-Host 'La base de données (sb_mariadb) redémarre sans arrêt. Ses derniers messages :'
                $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
                & docker logs --tail 15 sb_mariadb 2>&1 | ForEach-Object { Write-Host "  | $_" }
                $ErrorActionPreference = $old
                exit 1
            }
        }
        # Dépôt privé sans jeton (ou jeton refusé) : inutile d'attendre 15 minutes.
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $logs = (& docker logs --tail 40 sb_php-fpm 2>&1 | Out-String)
        $ErrorActionPreference = $old
        if ($logs -match 'could not read Username|could not read Password|Authentication failed|Invalid username or (password|token)|Repository not found') {
            Write-Host 'Le téléchargement de SwitchBoard depuis GitHub est refusé : le dépôt n''est pas public.'
            Write-Host 'Mettez un jeton GitHub valide (droit de lecture « Contents » sur le dépôt) dans .env (GITHUB_TOKEN=...), puis relancez up.cmd. Voir README.md#fr-before-you-start.'
            exit 1
        }
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $state = (& docker logs --tail 1 sb_php-fpm 2>&1 | Out-String) + (& docker inspect -f '{{.RestartCount}}' sb_php-fpm 2>$null | Out-String)
        $ErrorActionPreference = $old
        if ($state -ne $lastState) { $lastState = $state; $idle = 0 } else { $idle += 5; if ($idle -ge 900) { break } }
        Start-Sleep -Seconds 5
    }
    if (-not $ready) {
        Write-Host "SwitchBoard ne répond pas (plus aucun progrès depuis 15 minutes, ou plus d'une heure d'attente)."
        Write-Host 'Derniers messages du conteneur php :'
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & docker logs --tail 20 sb_php-fpm 2>&1 | ForEach-Object { Write-Host "  | $_" }
        $ErrorActionPreference = $old
        Write-Host 'Journal complet : docker compose logs php. Relancez up.cmd une fois le problème corrigé.'
        exit 1
    }
    Write-Host 'SwitchBoard est prêt.'
}

# --- Secrets enregistrés : chiffrés avec la clé de .env ---------------------------------------
# Nombre de secrets déjà chiffrés (base et fichiers de config/). La requête passe par l'entrée
# standard : Windows PowerShell 5.1 transmet mal les guillemets dans les arguments de docker.exe.
function Get-EncryptedSecretCount {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    try {
        $sql = "SELECT (SELECT COUNT(*) FROM userinfo WHERE voipmsapipassword LIKE 'enc:v1:%' OR calendarpassword LIKE 'enc:v1:%') + (SELECT COUNT(*) FROM sbchat WHERE sbchatapitoken LIKE 'enc:v1:%' OR masclientid LIKE 'enc:v1:%' OR massecret LIKE 'enc:v1:%');"
        $n = ($sql | & docker exec -i sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -N $MYSQL_DATABASE' 2>$null | Select-Object -Last 1)
        $f = (& docker exec sb_php-fpm sh -c 'grep -l enc:v1: /var/www/html/SwitchBoard/SwitchBoard/config/* 2>/dev/null | wc -l' 2>$null | Select-Object -Last 1)
        $total = 0
        foreach ($v in @("$n".Trim(), "$f".Trim())) { if ($v -match '^\d+$') { $total += [int]$v } }
        return $total
    } finally { $ErrorActionPreference = $old }
}
if ((Invoke-Quiet docker @('exec', 'sb_php-fpm', 'test', '-f', '/var/www/html/SwitchBoard/SwitchBoard/config/config.php')) -eq 0) {
    if ($KeyGenerated -and (Get-EncryptedSecretCount) -gt 0) {
        # Chiffrés avec une ancienne clé, retirée de .env : la nouvelle ne peut pas les lire. On ne
        # chiffre rien de plus avec elle, pour que remettre l'ancienne clé suffise.
        Write-Host ''
        Write-Host 'ATTENTION : des secrets enregistrés ont été chiffrés avec une ancienne clé, qui n''est plus dans'
        Write-Host '.env. La nouvelle clé générée ne peut pas les lire. Remettez l''ancienne APP_ENCRYPTION_KEY'
        Write-Host '(sauvegarde de .env) puis relancez up.cmd, ou ressaisissez ces secrets dans SwitchBoard'
        Write-Host '(voip.ms, SB Chat / MAS, courriel).'
    } else {
        # Secrets enregistrés en clair (avant que la clé existe) : chiffrés maintenant.
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $out = (& docker exec -u www-data sb_php-fpm php /var/www/html/SwitchBoard/SwitchBoard/src/reencryptsecrets.php 2>&1 | Out-String)
        $rc = $LASTEXITCODE
        $ErrorActionPreference = $old
        if ($rc -eq 0 -and $out -match '(?m)^Terminé : (\d+) ' -and [int]$Matches[1] -gt 0) {
            Write-Host "Secrets enregistrés en clair : $($Matches[1]) ligne(s) maintenant chiffrée(s) avec APP_ENCRYPTION_KEY."
        } elseif ($out -match 'aucune clé de chiffrement') {
            Write-Host 'Avertissement : SwitchBoard ne voit pas APP_ENCRYPTION_KEY ; les secrets restent en clair.'
            Write-Host 'Relancez up.cmd ; si le message revient, envoyez le rapport de diagnostic.cmd.'
        }
    }
}

$ActiveHost = Get-EnvValue 'VIRTUAL_HOST'
if ($Standalone) {
    Write-Host "Ouvrez : https://$ActiveHost"
    if ($ActiveHost -eq 'localhost') {
        foreach ($ip in $Lan) { Write-Host "     ou, depuis un autre appareil du réseau local : https://$ip" }
        Write-Host "(Certificat auto-signé : le navigateur affichera un avertissement à accepter une fois.)"
    }
} else {
    Write-Host 'Mode reverse-proxy externe : faites pointer votre reverse-proxy vers http://sb_web_server:80 (réseau Docker « web »).'
}
