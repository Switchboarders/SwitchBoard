<#
Diagnostic complet d'une installation Docker de SwitchBoard sous Windows.
Double-cliquez sur diagnostic.cmd (ou : powershell -ExecutionPolicy Bypass -File .\diagnostic.ps1).

Produit un rapport texte (switchboard-diagnostic-AAAAMMJJ-HHMM.txt dans ce dossier, aussi copié dans
le presse-papiers) à coller tel quel dans une conversation d'aide (Claude, courriel...). Les mots de
passe, jetons et clés sont masqués : le rapport ne contient aucun secret.

Pour une erreur précise (ex. : erreur 500 en créant le compte admin), reproduisez-la d'abord dans le
navigateur, puis lancez le diagnostic tout de suite après : les journaux contiendront l'erreur.
Ne modifie rien (lecture seule), sauf le fichier du rapport.
#>
param([switch]$NoClipboard)
$ErrorActionPreference = 'Continue'
Set-Location -LiteralPath $PSScriptRoot
# Sortie de docker.exe (UTF-8) lue correctement, accents compris.
try { [Console]::OutputEncoding = [Text.Encoding]::UTF8 } catch { }
$Utf8 = New-Object System.Text.UTF8Encoding($false)
$Report = New-Object System.Collections.Generic.List[string]
$EnvFile = Join-Path $PSScriptRoot '.env'

# --- Masquage des secrets ----------------------------------------------------------------------
# Toute valeur secrète de .env est remplacée partout dans le rapport, ainsi que les motifs de jetons.
$SecretKeys = 'PASSWORD|TOKEN|SECRET|KEY|CLIENT_ID|_URL$'
$Secrets = New-Object System.Collections.Generic.List[string]
$EnvPairs = [ordered]@{}
if (Test-Path -LiteralPath $EnvFile) {
    foreach ($line in ([IO.File]::ReadAllText($EnvFile) -split "`r?`n")) {
        if ($line -match '^\s*([A-Za-z_][A-Za-z0-9_]*)=(.*)$') {
            $EnvPairs[$Matches[1]] = $Matches[2]
            $v = $Matches[2].Trim('"', "'", ' ')
            if ($Matches[1] -match $SecretKeys -and $v.Length -ge 4) { $Secrets.Add($v) }
        }
    }
}
function Hide-Secrets([string]$Text) {
    if (-not $Text) { return $Text }
    foreach ($s in $Secrets) { $Text = $Text.Replace($s, '***') }
    $Text = $Text -replace '(ghp|gho|ghs|github_pat)_[A-Za-z0-9_]{10,}', '***jeton***'
    $Text = $Text -replace '(?i)(password|passwd|pwd|secret|token)(\s*[=:]\s*)(?![(*])\S+', '$1$2***'
    $Text = $Text -replace '(?i)x-access-token:[^@\s]+@', 'x-access-token:***@'
    $Text = $Text -replace '[A-Za-z0-9._%+-]+@(?=[A-Za-z0-9-]+\.[A-Za-z])', '***@'
    return $Text
}
function Add-Line([string]$Text) { $Report.Add((Hide-Secrets $Text)) }
function Add-Section([string]$Title) { $Report.Add(''); $Report.Add("===== $Title =====") }
# Commande externe : sortie (stdout + stderr) en texte, sans jamais arrêter le script.
function Get-Output([string]$Exe, [string[]]$Arguments) {
    try { return ((& $Exe @Arguments 2>&1 | ForEach-Object { "$_" }) -join "`n") } catch { return "(erreur : $($_.Exception.Message))" }
}
function Add-Command([string]$Label, [string]$Exe, [string[]]$Arguments) {
    Add-Line "--- $Label"
    Add-Line (Get-Output $Exe $Arguments)
}
$HasDocker = [bool](Get-Command docker -ErrorAction SilentlyContinue)
if (-not $HasDocker) {
    foreach ($b in @((Join-Path $env:ProgramFiles 'Docker\Docker\resources\bin'), (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop\resources\bin'))) {
        if (Test-Path -LiteralPath (Join-Path $b 'docker.exe')) { $env:Path = "$b;$env:Path"; $HasDocker = $true; break }
    }
}
$DockerOk = $HasDocker -and ((Get-Output docker @('info', '--format', '{{.OSType}}')) -eq 'linux')

Write-Host 'Diagnostic de SwitchBoard en cours (1 à 2 minutes)...'

# --- Système -----------------------------------------------------------------------------------
Add-Section 'Système'
Add-Line "Rapport : $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss zzz')  (diagnostic.ps1)"
try {
    $os = Get-CimInstance Win32_OperatingSystem
    $cs = Get-CimInstance Win32_ComputerSystem
    $cpu = @(Get-CimInstance Win32_Processor)[0]
    Add-Line "Windows : $($os.Caption) $($os.Version) (build $($os.BuildNumber)), $($os.OSArchitecture)"
    Add-Line "Machine : $($cs.Manufacturer) $($cs.Model) ; processeur : $($cpu.Name)"
    Add-Line ("Mémoire : {0:N1} Go au total, {1:N1} Go libres" -f ($cs.TotalPhysicalMemory / 1GB), ($os.FreePhysicalMemory * 1KB / 1GB))
    Add-Line "Virtualisation (BIOS) : $($cpu.VirtualizationFirmwareEnabled) ; hyperviseur actif : $($cs.HypervisorPresent)"
    Add-Line "Démarré le : $($os.LastBootUpTime)"
} catch { Add-Line "(informations système indisponibles : $($_.Exception.Message))" }
Add-Line "PowerShell : $($PSVersionTable.PSVersion) ; fuseau : $([TimeZoneInfo]::Local.Id)"
Add-Line "Administrateur : $(([Security.Principal.WindowsPrincipal][Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator))"
try {
    $drive = Get-PSDrive -Name ($PSScriptRoot.Substring(0, 1))
    Add-Line ("Disque {0}: {1:N1} Go libres" -f $drive.Name, ($drive.Free / 1GB))
} catch { }
try {
    Get-NetConnectionProfile | ForEach-Object { Add-Line "Réseau : $($_.Name) ($($_.NetworkCategory))" }
    Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } |
        ForEach-Object { Add-Line "Adresse IP : $($_.IPAddress) ($($_.InterfaceAlias))" }
    $rule = Get-NetFirewallRule -DisplayName 'SwitchBoard HTTP/HTTPS' -ErrorAction SilentlyContinue
    Add-Line "Règle de pare-feu SwitchBoard : $(if ($rule) { "présente ($($rule.Profile))" } else { 'absente' })"
} catch { }
$env:WSL_UTF8 = '1'
Add-Command 'WSL' 'wsl.exe' @('--version')

# --- Dossier SwitchBoard -----------------------------------------------------------------------
Add-Section 'Dossier SwitchBoard'
Add-Line "Dossier : $PSScriptRoot"
if ($PSScriptRoot -match 'OneDrive') { Add-Line 'ATTENTION : dossier synchronisé par OneDrive.' }
$gitDir = Split-Path $PSScriptRoot
if ((Get-Command git -ErrorAction SilentlyContinue) -and (Test-Path -LiteralPath (Join-Path $gitDir '.git'))) {
    Add-Line ("Git : " + (Get-Output git @('-C', $gitDir, 'log', '-1', '--format=%h %ad %s', '--date=iso')))
    Add-Line ("Branche : " + (Get-Output git @('-C', $gitDir, 'rev-parse', '--abbrev-ref', 'HEAD')))
    $changes = Get-Output git @('-C', $gitDir, 'status', '--short')
    Add-Line ("Fichiers modifiés localement : " + $(if ($changes) { "`n$changes" } else { 'aucun' }))
} else { Add-Line 'Git : pas un clone git (ZIP ?)' }
foreach ($f in 'up.ps1', 'up.cmd', 'entrypoint.sh', 'prepare_switchboard.sh', 'nginx\nginx.conf') {
    $p = Join-Path $PSScriptRoot $f
    if (Test-Path -LiteralPath $p) {
        $bytes = [IO.File]::ReadAllBytes($p)
        $crlf = ($bytes -contains 13)
        Add-Line ("{0} : {1} octets, modifié {2}{3}" -f $f, $bytes.Length, (Get-Item -LiteralPath $p).LastWriteTime.ToString('yyyy-MM-dd HH:mm'), $(if ($crlf -and $f -like '*.sh') { ', ATTENTION fins de ligne CRLF' } else { '' }))
    } else { Add-Line "$f : ABSENT" }
}

# --- .env (valeurs secrètes masquées) ----------------------------------------------------------
Add-Section '.env (secrets masqués)'
if (Test-Path -LiteralPath $EnvFile) {
    $raw = [IO.File]::ReadAllBytes($EnvFile)
    if ($raw.Length -ge 3 -and $raw[0] -eq 0xEF -and $raw[1] -eq 0xBB) { Add-Line 'ATTENTION : .env commence par une marque BOM' }
    if ($raw -contains 13) { Add-Line 'ATTENTION : .env a des fins de ligne CRLF' }
    foreach ($k in $EnvPairs.Keys) {
        $v = $EnvPairs[$k]
        if ($k -match $SecretKeys) { $shown = if ($v) { "(défini, $($v.Length) caractères)" } else { '(vide)' } }
        elseif ($k -eq 'DEFAULT_EMAIL' -or $k -eq 'SB_SMTP_USER' -or $k -eq 'SB_SMTP_FROM') { $shown = if ($v) { $v -replace '^[^@]*', '***' } else { '(vide)' } }
        else { $shown = if ($v) { $v } else { '(vide)' } }
        Add-Line "$k=$shown"
    }
} else { Add-Line '.env : ABSENT' }

# --- Docker ------------------------------------------------------------------------------------
Add-Section 'Docker'
if (-not $HasDocker) { Add-Line 'Docker : introuvable (Docker Desktop installé ?)' }
else {
    Add-Command 'docker version' docker @('version')
    Add-Command 'docker compose version' docker @('compose', 'version')
    Add-Command 'docker info (résumé)' docker @('info', '--format', 'OS={{.OSType}} CPU={{.NCPU}} RAM={{.MemTotal}} Root={{.DockerRootDir}} Server={{.ServerVersion}}')
    if (-not $DockerOk) { Add-Line 'ATTENTION : le moteur Docker ne répond pas (ou n''est pas en mode conteneurs Linux).' }
}
if ($DockerOk) {
    Add-Command 'conteneurs SwitchBoard' docker @('ps', '-a', '--filter', 'name=^sb_', '--format', 'table {{.Names}}\t{{.Status}}\t{{.Image}}\t{{.Ports}}')
    foreach ($c in 'sb_nginx-proxy', 'sb_letsencrypt', 'sb_web', 'sb_web_server', 'sb_php-fpm', 'sb_mariadb') {
        $state = Get-Output docker @('inspect', '-f', '{{.State.Status}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}} started={{.State.StartedAt}}', $c)
        if ($state -notmatch 'No such') { Add-Line "$c : $state" }
    }
    Add-Line '--- stockage (volume Docker attendu ; bind = dossier Windows)'
    foreach ($c in 'sb_mariadb', 'sb_php-fpm') {
        $mounts = Get-Output docker @('inspect', '-f', '{{range .Mounts}}{{.Type}} {{.Source}} -> {{.Destination}}{{println}}{{end}}', $c)
        if ($mounts -match 'No such') { continue }
        Add-Line "$c :`n$($mounts.Trim())"
        if ($mounts -match 'bind \S.* -> /var/(lib/mysql|www/html)') {
            Add-Line "ATTENTION : $c utilise un dossier Windows (docker-compose.windows.yml absent de COMPOSE_FILE ?)."
        }
    }
    Add-Command 'volumes' docker @('volume', 'ls', '--filter', 'name=switchboard')
    Add-Command 'images' docker @('images', '--format', '{{.Repository}}:{{.Tag}} {{.CreatedSince}} {{.Size}}')
    Add-Command 'docker compose ps' docker @('compose', 'ps', '-a')
    Add-Command 'espace disque Docker' docker @('system', 'df')

    # --- Dans le conteneur php : diagnostic-conteneur.sh, copié puis lancé (lecture seule) -------
    $probe = Join-Path $PSScriptRoot 'diagnostic-conteneur.sh'
    if ((Get-Output docker @('inspect', '-f', '{{.State.Status}}', 'sb_php-fpm')) -eq 'running' -and (Test-Path -LiteralPath $probe)) {
        # Fins de ligne LF, quoi qu'ait fait Git ou un éditeur sous Windows.
        $tmp = [IO.Path]::GetTempFileName()
        [IO.File]::WriteAllText($tmp, ([IO.File]::ReadAllText($probe) -replace "`r", ''), $Utf8)
        Get-Output docker @('cp', $tmp, 'sb_php-fpm:/tmp/sb-diagnostic.sh') | Out-Null
        Remove-Item -LiteralPath $tmp -Force
        Add-Line (Get-Output docker @('exec', 'sb_php-fpm', 'bash', '/tmp/sb-diagnostic.sh'))
        Get-Output docker @('exec', 'sb_php-fpm', 'rm', '-f', '/tmp/sb-diagnostic.sh') | Out-Null
    } else {
        Add-Section 'Conteneur php'
        Add-Line 'sb_php-fpm ne tourne pas : vérifications internes impossibles (voir son journal plus bas).'
    }
    Add-Section 'Base de données (identifiants de .env, depuis sb_mariadb)'
    Add-Line (Get-Output docker @('exec', 'sb_mariadb', 'sh', '-c', 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -e status $MYSQL_DATABASE 2>&1 | grep -E ''Current database|Server version|Uptime|ERROR'''))

    # --- Tests HTTP -------------------------------------------------------------------------------
    Add-Section 'Tests HTTP'
    $portal = '/SwitchBoard/SwitchBoard/src/managementportal.php'
    foreach ($u in @("https://localhost$portal", 'http://localhost/')) {
        Add-Line ("$u -> " + (Get-Output curl.exe @('-sk', '-o', 'NUL', '-w', 'HTTP %{http_code}, %{time_total} s, redirection : %{redirect_url}', '--max-time', '30', $u)))
    }
    $web = if ((Get-Output docker @('inspect', '-f', '{{.State.Status}}', 'sb_web')) -eq 'running') { 'sb_web' } else { 'sb_web_server' }
    Add-Line ("depuis $web (sans le reverse-proxy) : " + (Get-Output docker @('exec', $web, 'sh', '-c', "wget -S -q -O /dev/null --header X-Forwarded-Proto:https http://127.0.0.1$portal 2>&1 | head -n 1")))

    # --- Journaux des conteneurs ------------------------------------------------------------------
    Add-Section 'Journaux : erreurs de MariaDB (tout le journal)'
    $dbErrors = (Get-Output docker @('logs', 'sb_mariadb')) -split "`n" | Where-Object { $_ -match '\[ERROR\]|lower_case_table_names' } | Select-Object -Last 40
    Add-Line $(if ($dbErrors) { $dbErrors -join "`n" } else { 'aucune' })
    foreach ($c in 'sb_php-fpm', 'sb_web', 'sb_web_server', 'sb_nginx-proxy', 'sb_mariadb', 'sb_letsencrypt') {
        $exists = Get-Output docker @('inspect', '-f', '{{.Name}}', $c)
        if ($exists -match 'No such') { continue }
        Add-Section "Journaux : docker logs $c (80 dernières lignes)"
        Add-Line (Get-Output docker @('logs', '--tail', '80', $c))
    }
}

# --- Rapport -----------------------------------------------------------------------------------
$name = "switchboard-diagnostic-$(Get-Date -Format 'yyyyMMdd-HHmm').txt"
$path = Join-Path $PSScriptRoot $name
$text = ($Report -join "`r`n").Trim()
[IO.File]::WriteAllText($path, $text, $Utf8)
Write-Host ''
Write-Host "Rapport enregistré : $path"
if (-not $NoClipboard) {
    try { Set-Clipboard -Value $text; Write-Host 'Il est aussi copié dans le presse-papiers : collez-le (Ctrl+V) dans la conversation d''aide.' }
    catch { Write-Host 'Ouvrez ce fichier, copiez tout son contenu et collez-le dans la conversation d''aide.' }
}
Write-Host 'Les mots de passe, jetons et clés y sont masqués.'
