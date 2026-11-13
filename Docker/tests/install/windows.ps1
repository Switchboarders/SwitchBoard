<#
Tests d'installation de SwitchBoard sous Windows (Windows PowerShell 5.1 ou PowerShell 7).
Lancé par tests/install/run-windows.sh depuis Linux, ou directement sur le PC Windows :
    powershell -NoProfile -ExecutionPolicy Bypass -File tests\install\windows.ps1
Travaille sur des copies du dossier Docker\ dans %TEMP% : rien n'est modifié dans le dossier d'origine.

Toujours vérifié : syntaxe de up.ps1, préparation complète sans question (.env, secrets, fins de
ligne, mode, nom de la machine pour le certificat), relance, mode reverse-proxy, message clair
sans Docker. Si Docker Desktop fonctionne (moteur Linux) : installation complète et accès
https://localhost et https://<adresse IP du PC>.
#>
param([switch]$SansInstallComplete)
$ErrorActionPreference = 'Stop'
$Docker = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$script:Pass = 0; $script:Fail = 0; $script:Failures = @()
# Jeton GitHub (tant que le dépôt est privé) déposé par run-windows.sh ; jamais copié dans les dossiers d'installation.
$tokenFile = Join-Path $PSScriptRoot '.sbtest-token'
if (-not $env:SB_GITHUB_TOKEN -and (Test-Path $tokenFile)) { $env:SB_GITHUB_TOKEN = (Get-Content -Raw $tokenFile).Trim() }
Remove-Item -Force -ErrorAction SilentlyContinue $tokenFile
function Title($t) { Write-Host ''; Write-Host "=== $t ===" }
function Check([string]$Name, [scriptblock]$Test) {
    $ok = $false
    try { $ok = [bool](& $Test) } catch { $ok = $false }
    if ($ok) { $script:Pass++; Write-Host "  PASS $Name" } else { $script:Fail++; $script:Failures += $Name; Write-Host "  FAIL $Name" }
}
function Copy-DockerDir([string]$Name) {
    $dest = Join-Path $env:TEMP "sbtest-$Name"
    if (Test-Path $dest) { Remove-Item -Recurse -Force $dest }
    New-Item -ItemType Directory -Path $dest | Out-Null
    Get-ChildItem -LiteralPath $Docker -Force | Where-Object { $_.Name -notin @('.env', 'web', 'db_data', 'logs', 'tests') } |
        Copy-Item -Destination $dest -Recurse -Force
    Remove-Item -Force -ErrorAction SilentlyContinue (Join-Path $dest 'nginx\nginx.conf')
    return $dest
}
# Lance up.ps1 dans un processus séparé (comme up.cmd) ; renvoie code de sortie et sortie.
function Run-Up([string]$Dir, [string[]]$UpArgs) {
    $psExe = (Get-Process -Id $PID).Path
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $out = & $psExe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $Dir 'up.ps1') @UpArgs 2>&1 | Out-String
    $code = $LASTEXITCODE
    $ErrorActionPreference = $old
    return @{ Code = $code; Out = $out }
}
function Env-Value([string]$Dir, [string]$Key) {
    $line = Get-Content -LiteralPath (Join-Path $Dir '.env') | Where-Object { $_ -like "$Key=*" } | Select-Object -Last 1
    if ($line) { $line.Substring($Key.Length + 1) } else { '' }
}
function Has-CR([string]$Path) { return ([IO.File]::ReadAllBytes($Path) -contains 13) }

Write-Host "Windows $([Environment]::OSVersion.Version), PowerShell $($PSVersionTable.PSVersion)"

Title 'Syntaxe'
Check 'up.ps1 : aucune erreur de syntaxe' {
    $errors = $null
    [void][System.Management.Automation.Language.Parser]::ParseFile((Join-Path $Docker 'up.ps1'), [ref]$null, [ref]$errors)
    $errors.Count -eq 0
}

Title 'Préparation sans question (mode autonome)'
$dir = Copy-DockerDir 'autonome'
# Simule un clone Git Windows qui aurait converti les scripts en CRLF.
foreach ($f in 'entrypoint.sh', 'prepare_switchboard.sh', 'cronjobs') {
    $p = Join-Path $dir $f
    [IO.File]::WriteAllText($p, ([IO.File]::ReadAllText($p) -replace "`r?`n", "`r`n"))
}
$r = Run-Up $dir @('-NonInteractive', '-PrepareOnly')
Write-Host ($r.Out.Trim() -replace '(?m)^', '    | ')
Check 'up.ps1 -PrepareOnly se termine sans erreur' { $r.Code -eq 0 }
Check 'scripts reconvertis en LF (entrypoint.sh, prepare_switchboard.sh, cronjobs)' {
    -not (Has-CR (Join-Path $dir 'entrypoint.sh')) -and -not (Has-CR (Join-Path $dir 'prepare_switchboard.sh')) -and -not (Has-CR (Join-Path $dir 'cronjobs'))
}
$envPath = Join-Path $dir '.env'
Check '.env créé' { Test-Path $envPath }
Check '.env sans BOM et en LF (lisible par Docker Compose)' {
    $b = [IO.File]::ReadAllBytes($envPath); -not ($b[0] -eq 0xEF -and $b[1] -eq 0xBB) -and -not ($b -contains 13)
}
$root = Env-Value $dir 'MYSQL_ROOT_PASSWORD'
Check '.env : mots de passe de la base aléatoires' {
    $root.Length -ge 24 -and $root -ne 'complexrootpassword' -and $root -ne (Env-Value $dir 'MYSQL_PASSWORD')
}
Check '.env : APP_ENCRYPTION_KEY de 32 octets' { [Convert]::FromBase64String((Env-Value $dir 'APP_ENCRYPTION_KEY')).Length -eq 32 }
Check '.env : mode autonome + complément Windows' { (Env-Value $dir 'COMPOSE_FILE') -eq 'docker-compose.standalone.yml;docker-compose.windows.yml' }
Check '.env : VIRTUAL_HOST=localhost' { (Env-Value $dir 'VIRTUAL_HOST') -eq 'localhost' }
Check '.env : fuseau horaire au format IANA' { (Env-Value $dir 'TZ') -match '^[A-Za-z_]+(/[A-Za-z_]+)*$' }
Check '.env : le certificat couvre le nom du PC' { (Env-Value $dir 'SB_CERT_SANS') -split ',' -contains $env:COMPUTERNAME.ToLower() }
Check 'nginx\nginx.conf créé' { Test-Path (Join-Path $dir 'nginx\nginx.conf') }

$r2 = Run-Up $dir @('-NonInteractive', '-PrepareOnly')
Check 'relance : sans erreur' { $r2.Code -eq 0 }
Check 'relance : mêmes mots de passe' { (Env-Value $dir 'MYSQL_ROOT_PASSWORD') -eq $root }
Check 'relance : une seule ligne COMPOSE_FILE' { @(Get-Content $envPath | Where-Object { $_ -like 'COMPOSE_FILE=*' }).Count -eq 1 }

Title 'Jeton GitHub : facultatif pour le dépôt public, vérifié s''il est donné'
# Dépôt privé : sans jeton ou avec un jeton refusé, up.ps1 refuse. Dépôt public : il continue sans jeton.
$depotPublic = $false
try { $depotPublic = (Invoke-WebRequest -UseBasicParsing -TimeoutSec 20 -Uri 'https://github.com/Switchboarders/SwitchBoard.git/info/refs?service=git-upload-pack').StatusCode -eq 200 } catch { }
$savedToken = $env:SB_GITHUB_TOKEN
$env:SB_GITHUB_TOKEN = ''
$r6 = Run-Up (Copy-DockerDir 'sansjeton') @('-NonInteractive', '-PrepareOnly')
if ($depotPublic) {
    Check 'sans jeton, dépôt public : up.ps1 l''accepte' { $r6.Code -eq 0 -and $r6.Out -match 'aucun jeton GitHub' }
} else {
    Check 'sans jeton, dépôt privé : up.ps1 refuse et explique qu''il en faut un' { $r6.Code -ne 0 -and $r6.Out -match 'un jeton GitHub est nécessaire' }
}
$fake = 'ghp_sbtestREFUSE' + [guid]::NewGuid().ToString('N').Substring(0, 12)
$env:SB_GITHUB_TOKEN = $fake
$dirBad = Copy-DockerDir 'jetonrefuse'
$r7 = Run-Up $dirBad @('-NonInteractive', '-PrepareOnly')
if ($depotPublic) {
    Check 'jeton refusé, dépôt public : up.ps1 le dit et continue sans lui' { $r7.Code -eq 0 -and $r7.Out -match 'GitHub refuse ce jeton' -and $r7.Out -match 'il est ignoré' }
} else {
    Check 'jeton refusé, dépôt privé : up.ps1 refuse et explique pourquoi' { $r7.Code -ne 0 -and $r7.Out -match 'GitHub refuse ce jeton' }
}
Check 'jeton refusé : ni affiché ni enregistré dans .env' { -not $r7.Out.Contains($fake) -and -not (Get-Content -Raw (Join-Path $dirBad '.env')).Contains($fake) }
$env:SB_GITHUB_TOKEN = $savedToken
Check 'jeton valide : enregistré dans .env' { $savedToken -and (Env-Value $dir 'GITHUB_TOKEN') -eq $savedToken }

Title 'Erreurs d''utilisation'
# .env enregistré par le Bloc-notes (BOM + CRLF), jeton entre guillemets, nom de domaine copié du navigateur.
$dirWin = Copy-DockerDir 'envwindows'
$sample = [IO.File]::ReadAllText((Join-Path $dirWin '.env_sample')) -replace "`r", ''
$sample = $sample -replace '(?m)^GITHUB_TOKEN=.*$', "GITHUB_TOKEN=`"$($env:SB_GITHUB_TOKEN)`"" -replace '(?m)^VIRTUAL_HOST=.*$', 'VIRTUAL_HOST=HTTPS://Sb.Exemple.org/'
[IO.File]::WriteAllText((Join-Path $dirWin '.env'), ($sample -replace "`n", "`r`n"), (New-Object Text.UTF8Encoding($true)))
$savedToken = $env:SB_GITHUB_TOKEN; $env:SB_GITHUB_TOKEN = ''
$r8 = Run-Up $dirWin @('-NonInteractive', '-PrepareOnly')
$env:SB_GITHUB_TOKEN = $savedToken
Check '.env du Bloc-notes (BOM, CRLF) + jeton entre guillemets : accepté' { $r8.Code -eq 0 -and $r8.Out -match 'Jeton GitHub valide' }
Check '.env : BOM et CRLF retirés' { $bytes = [IO.File]::ReadAllBytes((Join-Path $dirWin '.env')); -not ($bytes[0] -eq 0xEF) -and -not ($bytes -contains 13) }
Check 'VIRTUAL_HOST « HTTPS://Sb.Exemple.org/ » corrigé en sb.exemple.org' { (Env-Value $dirWin 'VIRTUAL_HOST') -eq 'sb.exemple.org' }
# .env venu de Linux ou écrit à la main : COMPOSE_FILE sans le complément Windows (base dans db_data).
$dirCf = Copy-DockerDir 'composefile'
$cfEnv = ([IO.File]::ReadAllText((Join-Path $dirCf '.env_sample')) -replace "`r", '') + "`nCOMPOSE_FILE=docker-compose.standalone.yml`n"
[IO.File]::WriteAllText((Join-Path $dirCf '.env'), $cfEnv)
$r12 = Run-Up $dirCf @('-NonInteractive', '-PrepareOnly')
Check 'COMPOSE_FILE sans docker-compose.windows.yml : complété' { $r12.Code -eq 0 -and (Env-Value $dirCf 'COMPOSE_FILE') -eq 'docker-compose.standalone.yml;docker-compose.windows.yml' }
[IO.File]::WriteAllText((Join-Path $dirCf '.env'), $cfEnv)
New-Item -ItemType Directory -Force (Join-Path $dirCf 'db_data\mysql') | Out-Null
$r13 = Run-Up $dirCf @('-NonInteractive', '-PrepareOnly')
Check 'COMPOSE_FILE sans complément Windows et base dans db_data : refusé avec la marche à suivre' { $r13.Code -ne 0 -and $r13.Out -match 'db_data.ancien' -and (Env-Value $dirCf 'COMPOSE_FILE') -eq 'docker-compose.standalone.yml' }
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dirCf
# Clé de chiffrement vide (.env écrit à la main) : générée ; clé mal formée : refusée sans rien changer.
$dirKey = Copy-DockerDir 'cle'
$keyEnv = [IO.File]::ReadAllText((Join-Path $dirKey '.env_sample')) -replace "`r", ''
[IO.File]::WriteAllText((Join-Path $dirKey '.env'), ($keyEnv -replace '(?m)^APP_ENCRYPTION_KEY=.*$', 'APP_ENCRYPTION_KEY='))
$r20 = Run-Up $dirKey @('-NonInteractive', '-PrepareOnly')
Check 'APP_ENCRYPTION_KEY vide : une clé est générée' { $r20.Code -eq 0 -and $r20.Out -match 'clé aléatoire a été générée' -and [Convert]::FromBase64String((Env-Value $dirKey 'APP_ENCRYPTION_KEY')).Length -eq 32 }
[IO.File]::WriteAllText((Join-Path $dirKey '.env'), ($keyEnv -replace '(?m)^APP_ENCRYPTION_KEY=.*$', 'APP_ENCRYPTION_KEY=ma-cle-maison'))
$r21 = Run-Up $dirKey @('-NonInteractive', '-PrepareOnly')
Check 'APP_ENCRYPTION_KEY mal formée : refusée, .env inchangé' { $r21.Code -ne 0 -and $r21.Out -match 'pas une clé valide' -and (Env-Value $dirKey 'APP_ENCRYPTION_KEY') -eq 'ma-cle-maison' }
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dirKey
$dirHost = Copy-DockerDir 'hote'
$r9 = Run-Up $dirHost @('-NonInteractive', '-PrepareOnly', '-HostName', '192.168.1.20')
Check '-HostName adresse IP : refusé, avec le conseil d''utiliser localhost' { $r9.Code -ne 0 -and $r9.Out -match 'localhost' }
$r10 = Run-Up $dirHost @('-NonInteractive', '-PrepareOnly', '-HostName', 'sb_exemple.org')
Check '-HostName invalide : refusé' { $r10.Code -ne 0 -and $r10.Out -match 'pas un nom de domaine valide' }
Check '-HostName refusé : aucun .env créé' { -not (Test-Path (Join-Path $dirHost '.env')) -and -not (Test-Path (Join-Path $dirHost '.env.creation')) }
if (Get-Command docker -ErrorAction SilentlyContinue) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $dockerUp = ((& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim() -eq 'linux')
    $ErrorActionPreference = $old
    if ($dockerUp) {
        # .env supprimé alors que la base existe (volume Docker) : pas de nouveau .env.
        $dirLost = Copy-DockerDir 'envperdu'
        $s = [IO.File]::ReadAllText((Join-Path $dirLost '.env_sample')) -replace '(?m)^COMPOSE_PROJECT_NAME=.*$', 'COMPOSE_PROJECT_NAME=sbtestenvperdu'
        [IO.File]::WriteAllText((Join-Path $dirLost '.env_sample'), $s)
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & docker volume create sbtestenvperdu_sb_db 2>&1 | Out-Null
        $ErrorActionPreference = $old
        $r11 = Run-Up $dirLost @('-NonInteractive', '-PrepareOnly')
        Check '.env perdu mais base existante : refusé avec explication' { $r11.Code -ne 0 -and $r11.Out -match 'existe déjà' }
        Check '.env perdu : aucun nouveau .env créé' { -not (Test-Path (Join-Path $dirLost '.env')) }
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & docker volume rm sbtestenvperdu_sb_db 2>&1 | Out-Null
        $ErrorActionPreference = $old
        Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dirLost
    }
}
# up.cmd ouvert depuis l'intérieur d'un ZIP : Windows n'extrait que ce fichier.
$dirZip = Join-Path $env:TEMP 'sbtest-zip'
if (Test-Path $dirZip) { Remove-Item -Recurse -Force $dirZip }
New-Item -ItemType Directory $dirZip | Out-Null
Copy-Item (Join-Path $Docker 'up.cmd') $dirZip
$old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
$zipOut = (cmd.exe /c "echo.| `"$(Join-Path $dirZip 'up.cmd')`"" 2>&1 | Out-String)
$zipCode = $LASTEXITCODE
$ErrorActionPreference = $old
Check 'up.cmd ouvert dans un ZIP : explique qu''il faut extraire' { $zipCode -ne 0 -and $zipOut -match 'extrayez' }
Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dirWin, $dirHost, $dirZip

Title 'Préparation sans question (mode reverse-proxy)'
$dirProxy = Copy-DockerDir 'proxy'
$r3 = Run-Up $dirProxy @('-NonInteractive', '-PrepareOnly', '-Mode', 'reverse-proxy', '-HostName', 'sb.exemple.org')
Check 'up.ps1 -Mode reverse-proxy -PrepareOnly' { $r3.Code -eq 0 }
Check '.env : mode reverse-proxy + complément Windows' { (Env-Value $dirProxy 'COMPOSE_FILE') -eq 'docker-compose.reverse-proxy.yml;docker-compose.windows.yml' }
Check '.env : VIRTUAL_HOST=sb.exemple.org' { (Env-Value $dirProxy 'VIRTUAL_HOST') -eq 'sb.exemple.org' }

$dockerOk = $false
$dockerCmd = Get-Command docker -ErrorAction SilentlyContinue
$desktopExe = ''
foreach ($base in @((Join-Path $env:ProgramFiles 'Docker\Docker'), (Join-Path $env:LOCALAPPDATA 'Programs\DockerDesktop'))) {
    if (-not $desktopExe -and (Test-Path (Join-Path $base 'Docker Desktop.exe'))) {
        $desktopExe = Join-Path $base 'Docker Desktop.exe'
        if (-not $dockerCmd -and (Test-Path (Join-Path $base 'resources\bin\docker.exe'))) {
            $env:Path = (Join-Path $base 'resources\bin') + ";$env:Path"; $dockerCmd = Get-Command docker
        }
    }
}
function Get-DockerOsType {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $t = (& docker info --format '{{.OSType}}' 2>$null | Out-String).Trim()
    $ErrorActionPreference = $old
    return $t
}
$vtOff = $false
try {
    $vtOff = (@(Get-CimInstance Win32_Processor)[0].VirtualizationFirmwareEnabled -eq $false -and
              -not (Get-CimInstance Win32_ComputerSystem).HypervisorPresent)
} catch { }
if ($dockerCmd) {
    $dockerOk = ((Get-DockerOsType) -eq 'linux')
    # Moteur arrêté : Docker Desktop est une application de bureau ; lancé depuis SSH, il ne
    # démarre pas correctement. Une tâche planifiée l'ouvre dans la session de l'utilisateur connecté.
    if (-not $dockerOk -and -not $vtOff -and $desktopExe) {
        Write-Host ''
        Write-Host 'Démarrage de Docker Desktop dans la session de l''utilisateur (jusqu''à 5 min)...'
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & schtasks.exe /Create /F /TN sbtest-docker-desktop /SC ONCE /ST 23:59 /IT /TR "`"$desktopExe`"" 2>&1 | Out-Null
        & schtasks.exe /Run /TN sbtest-docker-desktop 2>&1 | Out-Null
        $ErrorActionPreference = $old
        for ($i = 0; $i -lt 60 -and -not $dockerOk; $i++) { Start-Sleep -Seconds 5; $dockerOk = ((Get-DockerOsType) -eq 'linux') }
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        & schtasks.exe /Delete /F /TN sbtest-docker-desktop 2>&1 | Out-Null
        $ErrorActionPreference = $old
    }
}

if (-not $dockerCmd) {
    Title 'Sans Docker'
    $r4 = Run-Up (Copy-DockerDir 'sansdocker') @('-NonInteractive')
    Check 'sans Docker Desktop : up.ps1 explique quoi installer' { $r4.Code -ne 0 -and $r4.Out -match 'Docker Desktop' }
} elseif ($vtOff -and -not $dockerOk) {
    Title 'Docker Desktop installé, virtualisation désactivée dans le BIOS'
    $sw = [Diagnostics.Stopwatch]::StartNew()
    $r4 = Run-Up (Copy-DockerDir 'sansvt') @('-NonInteractive', '-NoFirewall')
    Check 'up.ps1 explique d''activer la virtualisation dans le BIOS' { $r4.Code -ne 0 -and $r4.Out -match 'BIOS' -and $r4.Out -match 'VT-x' }
    Check 'sans attendre Docker Desktop pour rien (< 60 s)' { $sw.Elapsed.TotalSeconds -lt 60 }
}
# Sans Docker Desktop, un client Docker portable (aucun droit admin, rien d'installé dans le
# système, gardé en cache dans %LOCALAPPDATA%\sbtest-tools) suffit à vérifier que Docker Compose
# lit correctement les fichiers et le séparateur « ; » de COMPOSE_FILE sous Windows.
if (-not $dockerCmd) {
    $tools = Join-Path $env:LOCALAPPDATA 'sbtest-tools'
    $cli = Join-Path $tools 'docker\docker.exe'
    $plugins = Join-Path $tools 'cli-plugins'
    $compose = Join-Path $plugins 'docker-compose.exe'
    try {
        New-Item -ItemType Directory -Force -Path $plugins | Out-Null
        if (-not (Test-Path $cli)) {
            $zip = Join-Path $tools 'docker.zip'
            & curl.exe -sfL -o $zip 'https://download.docker.com/win/static/stable/x86_64/docker-29.9.1.zip'
            Expand-Archive -Path $zip -DestinationPath $tools -Force
            Remove-Item $zip
        }
        if (-not (Test-Path $compose)) {
            & curl.exe -sfL -o $compose 'https://github.com/docker/compose/releases/download/v5.6.0/docker-compose-windows-x86_64.exe'
        }
        $env:DOCKER_CONFIG = Join-Path $env:TEMP 'sbtest-docker-config'
        New-Item -ItemType Directory -Force -Path (Join-Path $env:DOCKER_CONFIG 'cli-plugins') | Out-Null
        Copy-Item $compose (Join-Path $env:DOCKER_CONFIG 'cli-plugins\docker-compose.exe') -Force
        $env:Path = (Split-Path $cli) + ";$env:Path"
        $dockerCmd = Get-Command docker -ErrorAction SilentlyContinue
    } catch { Write-Host "  (client Docker portable indisponible : $($_.Exception.Message))" }
}
if ($dockerCmd) {
    Title 'Fichiers compose (séparateur Windows « ; »)'
    Push-Location $dir
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & docker compose config -q 2>&1 | Out-Null; $code = $LASTEXITCODE
    $ErrorActionPreference = $old
    Pop-Location
    Check 'docker compose config avec COMPOSE_FILE du .env' { $code -eq 0 }
    Push-Location $dir
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $config = & docker compose config 2>$null | Out-String
    $ErrorActionPreference = $old
    Pop-Location
    Check 'le complément Windows est appliqué (base de données dans un volume Docker)' { $config -match 'source: sb_db' }
    Check 'mode autonome : DEFAULT_HOST pour l''accès réseau local' { $config -match 'DEFAULT_HOST: localhost' }
    Push-Location $dirProxy
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & docker compose config -q 2>&1 | Out-Null; $code = $LASTEXITCODE
    $ErrorActionPreference = $old
    Pop-Location
    Check 'docker compose config (mode reverse-proxy)' { $code -eq 0 }
}

# Une vraie installation SwitchBoard sur ce PC (mêmes noms de conteneurs et de volumes) ne doit
# jamais être touchée : l'installation complète du test finit par « docker compose down -v ».
$existing = ''
if ($dockerOk) {
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    $existing = ((& docker ps -a --filter 'name=^sb_' --format '{{.Names}}' 2>$null) + (& docker volume ls -q --filter 'name=switchboarddocker_' 2>$null) | Out-String).Trim()
    $ErrorActionPreference = $old
}
if ($dockerOk -and $SansInstallComplete) {
    Write-Host ''
    Write-Host 'Installation complète non testée (-SansInstallComplete).'
} elseif ($dockerOk -and $existing) {
    Write-Host ''
    Write-Host 'Une installation SwitchBoard existe déjà sur ce PC (conteneurs sb_* ou volumes switchboarddocker_*) :'
    Write-Host 'installation complète non testée, pour ne pas la toucher.'
} elseif ($dockerOk) {
    Title 'Installation complète (Docker Desktop)'
    $r5 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    Write-Host ($r5.Out.Trim() -replace '(?m)^', '    | ')
    Check 'up.ps1 installe et démarre SwitchBoard' { $r5.Code -eq 0 }
    $ips = @(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' -and $_.InterfaceAlias -notmatch 'vEthernet|WSL|Docker|Loopback' } | Select-Object -ExpandProperty IPAddress)
    $portal = '/SwitchBoard/SwitchBoard/src/managementportal.php'
    foreach ($h in @('localhost') + $ips) {
        Check "https://$h répond (page SwitchBoard)" {
            $page = & curl.exe -sk --max-time 30 "https://$h$portal" | Out-String
            $page -match 'createormodifyusername|name="username"'
        }
    }
    # Création du premier compte admin par le formulaire (comme dans le navigateur), puis connexion.
    $jar = Join-Path $env:TEMP 'sbtest-cookies.txt'; Remove-Item $jar -ErrorAction SilentlyContinue
    $form = & curl.exe -sk --max-time 30 -c $jar -b $jar "https://localhost$portal" | Out-String
    $csrf = if ($form -match "name='csrf_token' value='([^']*)'") { $Matches[1] } else { '' }
    $after = & curl.exe -sk --max-time 60 -c $jar -b $jar -w "`nHTTP=%{http_code}" `
        --data-urlencode 'createormodifyusername=admintest' --data-urlencode 'createormodifypassword=Mdp-Test-2026!' `
        --data-urlencode 'createormodifypasswordverification=Mdp-Test-2026!' --data-urlencode "csrf_token=$csrf" "https://localhost$portal" | Out-String
    Check 'création du premier compte admin : HTTP 200, connecté' { $after -match 'HTTP=200' -and $after -match 'page=logout' }
    if ($after -notmatch 'HTTP=200') { Write-Host ('    ' + (($after -split "`n") | Where-Object { $_ -match 'HTTP=' })) }
    Remove-Item $jar -ErrorAction SilentlyContinue
    # Diagnostic : rapport complet, sans aucun secret de .env ni le jeton GitHub.
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $dir 'diagnostic.ps1') -NoClipboard 2>&1 | Out-Null
    $ErrorActionPreference = $old
    $diag = Get-ChildItem $dir -Filter 'switchboard-diagnostic-*.txt' | Select-Object -First 1
    $diagText = if ($diag) { [IO.File]::ReadAllText($diag.FullName) } else { '' }
    Check 'diagnostic.ps1 : rapport avec les sections principales' { $diagText -match 'Conteneur php : connexion de PHP' -and $diagText -match 'OK, MariaDB' -and $diagText -match 'docker logs sb_php-fpm' }
    Check 'diagnostic.ps1 : accents lisibles' { $diagText -match "droits d'écriture" }
    Check 'diagnostic.ps1 : base et code dans des volumes Docker (pas de dossier Windows)' { $diagText -match 'volume \S+ -> /var/lib/mysql' -and $diagText -notmatch 'utilise un dossier Windows' }
    Check 'diagnostic.ps1 : version de WSL lisible et Git lu dans le conteneur' { $diagText -match 'WSL[^\r\n]*: \d' -and $diagText -notmatch 'dubious ownership' }
    Check 'diagnostic.ps1 : aucun mot de passe, clé ni jeton dans le rapport' {
        $leak = $false
        foreach ($k in 'MYSQL_PASSWORD', 'MYSQL_ROOT_PASSWORD', 'APP_ENCRYPTION_KEY', 'GITHUB_TOKEN') { $v = Env-Value $dir $k; if ($v -and $diagText.Contains($v)) { $leak = $true } }
        -not $leak -and $diagText.Length -gt 1000
    }
    if ($diag) { Remove-Item -LiteralPath $diag.FullName -Force }
    # Deuxième copie dans un autre dossier pendant que la première tourne : refusée.
    $dir2 = Copy-DockerDir 'deuxieme'
    $r12 = Run-Up $dir2 @('-NonInteractive', '-NoFirewall')
    Check 'deuxième installation dans un autre dossier : refusée' { $r12.Code -ne 0 -and $r12.Out -match 'autre installation de SwitchBoard' }
    if (-not ($r12.Code -ne 0 -and $r12.Out -match 'autre installation de SwitchBoard')) { Write-Host "    code $($r12.Code)"; Write-Host (($r12.Out.Trim() -split "`n" | Select-Object -Last 25) -join "`n" -replace '(?m)^', '    | ') }
    Check 'deuxième installation : la première n''est pas touchée' {
        $page = & curl.exe -sk --max-time 30 "https://localhost$portal" | Out-String
        $page -match 'createormodifyusername|name="username"'
    }
    Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dir2
    # Mot de passe de la base changé dans .env après l'installation : expliqué ; remettre l'ancien répare.
    $envPath2 = Join-Path $dir '.env'
    $pwd0 = Env-Value $dir 'MYSQL_PASSWORD'
    $content = [IO.File]::ReadAllText($envPath2)
    [IO.File]::WriteAllText($envPath2, ($content -replace '(?m)^MYSQL_PASSWORD=.*$', 'MYSQL_PASSWORD=change-apres-coup'), (New-Object Text.UTF8Encoding($false)))
    $r13 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    Check 'mot de passe de la base changé dans .env : up.ps1 l''explique' { $r13.Code -ne 0 -and $r13.Out -match 'refuse les identifiants' }
    [IO.File]::WriteAllText($envPath2, $content, (New-Object Text.UTF8Encoding($false)))
    $r14 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    Check 'ancien mot de passe remis : SwitchBoard redémarre' { $r14.Code -eq 0 -and $r14.Out -match 'SwitchBoard est prêt' }
    # Secret enregistré en clair (avant que la clé existe) : chiffré par up.ps1. Clé ensuite retirée de
    # .env : avertissement, sans rien rechiffrer ; ancienne clé remise : tout redevient normal.
    function Invoke-Sql([string]$Sql) {
        $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
        $o = ($Sql | & docker exec -i sb_mariadb sh -c 'set -f; IFS=; MYSQL_PWD=$MYSQL_PASSWORD mariadb -u$MYSQL_USER -N $MYSQL_DATABASE' 2>&1 | Out-String).Trim()
        $ErrorActionPreference = $old
        return $o
    }
    $selectSecret = 'SELECT sbchatapitoken FROM sbchat WHERE iduser=999;'
    Invoke-Sql "INSERT INTO sbchat (iduser, sbchatapitoken) VALUES (999, 'jeton-en-clair-test');" | Out-Null
    $r15 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    $cipher = Invoke-Sql $selectSecret
    Check 'secret enregistré en clair : chiffré par up.ps1' { $r15.Code -eq 0 -and $r15.Out -match '1 ligne\(s\) maintenant chiffrée' -and $cipher -like 'enc:v1:*' }
    if ($cipher -notlike 'enc:v1:*') { Write-Host "    sbchat 999 : $cipher"; Write-Host (($r15.Out.Trim() -split "`n" | Select-Object -Last 8) -join "`n" -replace '(?m)^', '    | ') }
    $content = [IO.File]::ReadAllText($envPath2)
    $key0 = Env-Value $dir 'APP_ENCRYPTION_KEY'
    [IO.File]::WriteAllText($envPath2, ($content -replace '(?m)^APP_ENCRYPTION_KEY=.*$', 'APP_ENCRYPTION_KEY='), (New-Object Text.UTF8Encoding($false)))
    $r16 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    Check 'clé retirée de .env alors que des secrets sont chiffrés : avertissement, rien de rechiffré' {
        $r16.Out -match 'ancienne clé' -and (Env-Value $dir 'APP_ENCRYPTION_KEY') -ne $key0 -and (Invoke-Sql $selectSecret) -eq $cipher
    }
    [IO.File]::WriteAllText($envPath2, $content, (New-Object Text.UTF8Encoding($false)))
    $r17 = Run-Up $dir @('-NonInteractive', '-NoFirewall')
    Check 'ancienne clé remise : plus d''avertissement' { $r17.Code -eq 0 -and $r17.Out -notmatch 'ancienne clé' }
    Invoke-Sql 'DELETE FROM sbchat WHERE iduser=999;' | Out-Null
    Push-Location $dir
    $old = $ErrorActionPreference; $ErrorActionPreference = 'Continue'
    & docker compose down -v 2>&1 | Out-Null
    $ErrorActionPreference = $old
    Pop-Location
} else {
    Write-Host ''
    if ($vtOff) { Write-Host 'Virtualisation désactivée dans le BIOS : installation complète non testée sur ce PC.' }
    else { Write-Host 'Docker Desktop absent ou arrêté : installation complète non testée sur ce PC.' }
}

Remove-Item -Recurse -Force -ErrorAction SilentlyContinue $dir, $dirProxy, (Join-Path $env:TEMP 'sbtest-sansdocker'), (Join-Path $env:TEMP 'sbtest-sansvt'), (Join-Path $env:TEMP 'sbtest-sansjeton'), (Join-Path $env:TEMP 'sbtest-jetonrefuse')
Write-Host ''
Write-Host "$($script:Pass) passed, $($script:Fail) failed"
if ($script:Fail -gt 0) { $script:Failures | ForEach-Object { Write-Host "  - $_" }; exit 1 }
exit 0
