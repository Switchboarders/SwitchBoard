#!/bin/bash
cd /var/www/html
#chown --recursive www-data:www-data /var/www/html/SwitchBoard/SwitchBoard

# Marqueur d'installation complète (pas seulement "le dossier existe") : configempty.php
# n'apparaît qu'après un clone + composer install réussis. Vérifier seulement l'existence du
# dossier /var/www/html/SwitchBoard était fragile - un clone interrompu (réseau instable au
# démarrage du conteneur, dépôt privé sans jeton, etc.) laisse un dossier partiel, et comme le
# dossier existe déjà, chaque redémarrage suivant du conteneur sautait le clone en pensant
# l'installation terminée, laissant le déploiement cassé en permanence sans jamais réessayer.
MARQUEUR_INSTALLATION_COMPLETE="/var/www/html/SwitchBoard/SwitchBoard/config/configempty.php"

if [ ! -f "$MARQUEUR_INSTALLATION_COMPLETE" ]; then
    # Un échec à n'importe quelle étape ci-dessous doit nettoyer le dossier partiel et
    # s'arrêter (conteneur redémarré par Docker), pour qu'un vrai nouvel essai ait lieu au
    # prochain démarrage plutôt que de continuer avec un dossier à moitié installé.
    set -e
    trap 'echo "Échec de la préparation de SwitchBoard - nettoyage pour réessayer au prochain démarrage."; rm -rf /var/www/html/SwitchBoard; exit 1' ERR

    rm -rf /var/www/html/SwitchBoard
    # Le jeton n'est nécessaire que pour un dépôt privé ; un dépôt public se clone sans
    # authentification. On l'utilise pour l'authentification puis on le retire
    # immédiatement de l'URL du remote pour qu'il ne reste pas lisible dans .git/config,
    # sous le docroot.
    # La branche principale du dépôt public.
    # Le jeton est passé comme mot de passe (x-access-token:...), jamais comme nom d'utilisateur :
    # git écrivait alors « could not read Password for 'https://<jeton>@github.com' » dans le
    # journal du conteneur. Un jeton refusé (révoqué, expiré) n'arrête rien : le clone se refait
    # sans jeton, ce qui suffit pour le dépôt public. Le premier essai se tait : son « Authentication
    # failed » ferait croire à up.sh (qui lit ce journal) que le dépôt est privé. Si le second échoue
    # aussi (dépôt privé), git le dit, et up.sh l'explique.
    BRANCHE=main
    DEPOT=https://github.com/Switchboarders/SwitchBoard.git
    if [ -n "${GITHUB_TOKEN:-}" ] && git clone -q -b "$BRANCHE" "https://x-access-token:${GITHUB_TOKEN}@github.com/Switchboarders/SwitchBoard.git" 2>/dev/null; then
        git -C /var/www/html/SwitchBoard remote set-url origin "$DEPOT"
    else
        [ -z "${GITHUB_TOKEN:-}" ] || echo "Jeton GitHub refusé : nouvel essai sans jeton (dépôt public)."
        rm -rf /var/www/html/SwitchBoard
        git clone -b "$BRANCHE" "$DEPOT"
    fi
    echo "Switchboard cloned, now installing dependencies"
    cd /var/www/html/SwitchBoard/SwitchBoard/
    composer install
    # Chemins absolus (pas de "cd" intermédiaire) : un cd manquant avait déjà fait créer
    # download/ au mauvais endroit (dans src/) au lieu de la racine de l'installation, là où
    # RepoManager (utils/repomanager.php, DOWNLOAD_DIRECTORY = BASE_DIRECTORY."download/")
    # s'attend réellement à l'écrire. Le dossier réel n'existant jamais, la mise à jour
    # automatique échouait dès la première tentative sur tout déploiement Docker, avec
    # "Save path does not exist". Les journaux (logs/) viennent du dépôt : rien à créer ici.
    mkdir -p /var/www/html/SwitchBoard/SwitchBoard/download
    chmod -R 775 /var/www/html/SwitchBoard/SwitchBoard/download
    chown --recursive www-data:www-data /var/www/html/SwitchBoard/SwitchBoard
    chmod -R 775 /var/www/html/SwitchBoard/SwitchBoard
    # testmail.php reste hors du docroot (/testmail.php, copié par DockerfilePHP) : ce n'est
    # qu'un outil de diagnostic CLI, pas une page destinée à être servie sur le web.
    echo '<meta http-equiv="refresh" content="0; url=../SwitchBoard/SwitchBoard/src/managementportal.php" />' > /var/www/html/index.php

    trap - ERR
    set +e
else
    echo "Switchboard already exists"
fi
