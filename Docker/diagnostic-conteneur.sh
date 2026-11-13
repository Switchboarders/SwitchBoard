#!/bin/bash
# Partie du diagnostic exécutée DANS le conteneur php (sb_php-fpm) : copiée et lancée par
# diagnostic.ps1 (Windows) et diagnostic.sh (Linux). Lecture seule : ne modifie rien.
# Dans un fichier plutôt qu'en commandes passées à docker exec : Windows PowerShell 5.1 transmet mal
# les guillemets à docker.exe.
SB=/var/www/html/SwitchBoard/SwitchBoard
section() { printf '\n===== %s =====\n' "$*"; }

section "Conteneur php : PHP"
php -v | head -n 1
echo "Extensions : $(php -m | grep -v '^\[' | tr '\n' ' ')"
php -r 'foreach (["error_log", "log_errors", "display_errors", "session.save_path", "memory_limit", "upload_tmp_dir", "date.timezone"] as $k) { echo $k, "=", ini_get($k), PHP_EOL; }'
echo "SB_DEBUG=${SB_DEBUG:-(non défini)}"

section "Conteneur php : installation de SwitchBoard"
if [ -d /var/www/html/SwitchBoard/.git ]; then
    git -c safe.directory='*' -C /var/www/html/SwitchBoard log -1 --format='%h %ad %s' --date=iso 2>&1
    echo "Fichiers modifiés : $(git -c safe.directory='*' -C /var/www/html/SwitchBoard status --short 2>/dev/null | wc -l)"
else
    echo "Pas de clone git dans /var/www/html/SwitchBoard"
fi
ls -la "$SB/config/" 2>&1
[ -f "$SB/config/debugmode.flag" ] && echo "debugmode.flag = $(cat "$SB/config/debugmode.flag")"
[ -d "$SB/vendor" ] && echo "vendor/ : présent ($(ls "$SB/vendor" | wc -l) paquets)" || echo "vendor/ : ABSENT (composer install a échoué ?)"

section "Conteneur php : droits d'écriture de www-data (l'utilisateur de PHP)"
SESSIONS=$(php -r 'echo session_save_path() ?: sys_get_temp_dir();')
for d in "$SB/config" "$SB/logs" "$SB/download" /var/log "$SESSIONS"; do
    if [ ! -e "$d" ]; then echo "$d : ABSENT"
    elif gosu www-data test -w "$d"; then echo "$d : écriture OK ($(stat -c '%U:%G %a' "$d"))"
    else echo "$d : PAS D'ÉCRITURE ($(stat -c '%U:%G %a' "$d"))"; fi
done
df -h /var/www/html /var/log /tmp 2>/dev/null

section "Conteneur php : connexion de PHP à la base (config.php, comme SwitchBoard)"
if [ -f "$SB/config/config.php" ]; then
    cd "$SB/config" && gosu www-data php -r '
        include "config.php";
        mysqli_report(MYSQLI_REPORT_OFF);
        $c = @mysqli_connect($dbservername, $dbusername, $dbpassword, $dbname);
        if (!$c) { echo "ÉCHEC : ", mysqli_connect_error(), PHP_EOL; exit; }
        echo "OK, MariaDB ", mysqli_get_server_info($c), ", base ", $dbname, PHP_EOL;
        $r = mysqli_query($c, "SHOW TABLES");
        echo "Tables : ", $r ? mysqli_num_rows($r) : "?", PHP_EOL;
        foreach (["user", "userinfo", "userservice"] as $t) {
            $q = @mysqli_query($c, "SELECT COUNT(*) FROM `$t`");
            echo "Lignes dans $t : ", $q ? mysqli_fetch_row($q)[0] : "table absente (" . mysqli_error($c) . ")", PHP_EOL;
        }
        $q = @mysqli_query($c, "SHOW CREATE TABLE `user`");
        if ($q) { echo mysqli_fetch_row($q)[1], PHP_EOL; }
    ' 2>&1
else
    echo "config.php ABSENT : la préparation de SwitchBoard n'est pas terminée."
fi

section "Journaux : erreurs PHP (/var/log/php-fpm-error.log, 150 dernières lignes)"
tail -n 150 /var/log/php-fpm-error.log 2>&1

section "Journaux : SwitchBoard (logs/, 40 dernières lignes des 5 plus récents)"
# Pas de boucle « for » : le nom d'un comité peut contenir des espaces
ls -t "$SB"/logs/*log.txt 2>/dev/null | head -n 5 | while IFS= read -r f; do
    echo "--- $f"
    tail -n 40 "$f"
done
