#!/bin/bash
# Lance tests/install/windows.ps1 sur un PC Windows joignable par SSH (OpenSSH pour Windows,
# PowerShell comme shell par défaut), au besoin en passant par une machine relais.
#
# Usage : tests/install/run-windows.sh [--sans-installation] [hôte-windows] [relais]
#   par défaut : vieuxpcwindows, joint depuis arch-desktop (ssh arch-desktop puis ssh vieuxpcwindows)
#   relais vide ("") : connexion directe.
set -u
ICI=$(cd "$(dirname "$0")" && pwd)
DOCKER_DIR=$(cd "$ICI/../.." && pwd)
# --sans-installation : seulement les vérifications rapides (pas d'installation complète).
OPTION_PS=""
if [ "${1:-}" = "--sans-installation" ]; then OPTION_PS="-SansInstallComplete"; shift; fi
WIN="${1:-vieuxpcwindows}"
RELAIS="${2-arch-desktop}"
ARCHIVE=sbtest-docker.tar

# Commande sur le PC Windows (PowerShell).
win() {
    if [ -n "$RELAIS" ]; then
        ssh -o BatchMode=yes "$RELAIS" "ssh -o BatchMode=yes $WIN $(printf '%q' "$1")"
    else
        ssh -o BatchMode=yes "$WIN" "$1"
    fi
}

# Le dossier Docker/ tel qu'un clone neuf le donnerait, plus le jeton GitHub (dépôt privé) pour
# l'installation complète ; windows.ps1 le lit puis l'efface. Archive lisible par nous seuls.
umask 077
TMP=$(mktemp -d)
(cd "$DOCKER_DIR/.." && git ls-files -co --exclude-standard -- Docker | grep -v '^Docker/tests/install/results/' \
    | tar -cf "$TMP/$ARCHIVE" -T -)
TOKEN="${SB_GITHUB_TOKEN:-$(gh auth token 2>/dev/null)}"
if [ -n "$TOKEN" ]; then
    mkdir -p "$TMP/Docker/tests/install" && printf '%s' "$TOKEN" > "$TMP/Docker/tests/install/.sbtest-token"
    tar -rf "$TMP/$ARCHIVE" -C "$TMP" Docker/tests/install/.sbtest-token
fi
if [ -n "$RELAIS" ]; then
    scp -q -o BatchMode=yes "$TMP/$ARCHIVE" "$RELAIS:/tmp/$ARCHIVE" \
        && ssh -o BatchMode=yes "$RELAIS" "scp -q -o BatchMode=yes /tmp/$ARCHIVE $WIN:$ARCHIVE && rm -f /tmp/$ARCHIVE"
else
    scp -q -o BatchMode=yes "$TMP/$ARCHIVE" "$WIN:$ARCHIVE"
fi
rm -rf "$TMP"

# tar.exe est inclus dans Windows 10 (1803+) et 11. La sortie est convertie en UTF-8.
# Dossier propre aux tests, marqué : on ne supprime jamais un dossier que les tests n'ont pas créé
# (par exemple une vraie installation qui porterait le même nom).
win "\$ErrorActionPreference='Stop'; [Console]::OutputEncoding=[Text.Encoding]::UTF8; \
\$d=Join-Path \$env:USERPROFILE 'sbtest-install-auto'; \$m=Join-Path \$d '.sbtest-auto'; \
if((Test-Path \$d) -and -not (Test-Path \$m)){Write-Host \"\$d existe et n'a pas été créé par les tests : arrêt.\"; exit 2}; \
if(Test-Path \$d){Remove-Item -Recurse -Force \$d}; \
New-Item -ItemType Directory \$d | Out-Null; New-Item -ItemType File \$m | Out-Null; tar.exe -xf (Join-Path \$env:USERPROFILE '$ARCHIVE') -C \$d; \
powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path \$d 'Docker\\tests\\install\\windows.ps1') $OPTION_PS; \$code=\$LASTEXITCODE; \
Remove-Item -Recurse -Force \$d, (Join-Path \$env:USERPROFILE '$ARCHIVE'); exit \$code" 2>&1 \
    | grep -v -e 'post-quantum' -e 'store now, decrypt later' -e 'may need to be upgraded' -e 'openssh.com/pq'
exit "${PIPESTATUS[0]}"
