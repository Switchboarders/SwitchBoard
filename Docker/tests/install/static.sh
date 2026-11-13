#!/bin/bash
# Vérifications rapides de l'installation, sans rien démarrer (quelques secondes) :
# syntaxe des scripts, fichiers compose valides dans toutes les combinaisons, fins de ligne
# protégées pour les clones Windows, scripts Windows bien encodés.
# Usage : tests/install/static.sh
set -u
ICI=$(cd "$(dirname "$0")" && pwd)
DOCKER_DIR=$(cd "$ICI/../.." && pwd)
DEPOT=$(cd "$DOCKER_DIR/.." && pwd)
. "$ICI/lib.sh"
cd "$DOCKER_DIR"

titre "Syntaxe des scripts"
for f in up.sh entrypoint.sh prepare_switchboard.sh generate_db_config.sh generate_mail_config.sh \
         generate_self_signed_certificates.sh tests/install/*.sh; do
    verifier "bash -n $f" bash -n "$f"
done
if command -v shellcheck >/dev/null 2>&1; then
    verifier "shellcheck up.sh (erreurs)" shellcheck -S error up.sh
fi
verifier "up.sh --help" bash -c "./up.sh --help | grep -q non-interactive"
if command -v pwsh >/dev/null 2>&1; then
    verifier "up.ps1 : syntaxe PowerShell" pwsh -NoProfile -Command \
        "\$e=\$null; [void][System.Management.Automation.Language.Parser]::ParseFile('$DOCKER_DIR/up.ps1',[ref]\$null,[ref]\$e); if(\$e){\$e; exit 1}"
fi

titre "Fichiers compose"
if docker compose version >/dev/null 2>&1; then
    for combo in docker-compose.standalone.yml docker-compose.reverse-proxy.yml \
                 docker-compose.standalone.yml:docker-compose.windows.yml \
                 docker-compose.reverse-proxy.yml:docker-compose.windows.yml; do
        verifier "docker compose config ($combo)" env COMPOSE_FILE="$combo" docker compose --env-file .env_sample config -q
    done
    verifier "mode autonome : DEFAULT_HOST suit VIRTUAL_HOST (accès réseau local)" bash -c \
        "COMPOSE_FILE=docker-compose.standalone.yml docker compose --env-file .env_sample config | grep -q 'DEFAULT_HOST: localhost'"
    verifier "complément Windows : volumes Docker pour la base et le code" bash -c \
        "COMPOSE_FILE=docker-compose.standalone.yml:docker-compose.windows.yml docker compose --env-file .env_sample config --format json \
         | grep -q '\"source\": \"sb_db\"'"
else
    echo "  (docker compose absent : vérifications compose sautées)"
fi

titre "Fins de ligne (clones Windows)"
for f in $(cd "$DEPOT" && git ls-files Docker | grep -Ev '\.(md|php|ps1|cmd)$|/\.git(ignore|keep)$|^Docker/tests/'); do
    attr=$(cd "$DEPOT" && git check-attr eol -- "$f" | awk '{print $NF}')
    verifier ".gitattributes force LF : $f" test "$attr" = lf
done
for f in up.ps1 up.cmd tests/install/windows.ps1; do
    verifier "$f en CRLF" bash -c "[ \"\$(grep -c \$'\\r\$' '$f')\" = \"\$(wc -l < '$f')\" ]"
done
verifier "up.ps1 en UTF-8 avec BOM (accents lisibles par Windows PowerShell 5.1)" \
    bash -c "[ \"\$(head -c 3 up.ps1 | od -An -tx1 | tr -d ' ')\" = efbbbf ]"
verifier "up.cmd en ASCII (cmd.exe)" bash -c "! LC_ALL=C grep -q '[^[:print:][:space:]]' up.cmd"
# Clone réel avec la conversion Windows activée : les scripts doivent rester en LF.
TMP=$(mktemp -d)
if git clone -q -c core.autocrlf=true --no-hardlinks "$DEPOT" "$TMP/clone" 2>/dev/null; then
    verifier "clone avec core.autocrlf=true : up.sh reste en LF" bash -c "! grep -q \$'\\r' '$TMP/clone/Docker/up.sh'"
    verifier "clone avec core.autocrlf=true : entrypoint.sh reste en LF" bash -c "! grep -q \$'\\r' '$TMP/clone/Docker/entrypoint.sh'"
    verifier "clone avec core.autocrlf=true : cronjobs reste en LF" bash -c "! grep -q \$'\\r' '$TMP/clone/Docker/cronjobs'"
fi
rm -rf "$TMP"

bilan
