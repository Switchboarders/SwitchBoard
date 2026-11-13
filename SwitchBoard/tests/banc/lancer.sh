#!/bin/bash
# Construit le banc d'essai, lance chaque scénario de scenarios/ dans le conteneur, affiche le résultat
# et efface tout. Retourne 0 seulement si tous les scénarios réussissent.
#
# Usage : ./lancer.sh [--mariadb VERSION] [--garder] [--nouvelles-references] [--seulement MOTIF]
#   --mariadb VERSION      version de MariaDB (défaut : 12, celle du serveur principal)
#   --garder               laisse les conteneurs en marche à la fin, pour aller voir dedans :
#                          docker compose -p sbbanc -f SwitchBoard/tests/banc/compose.yml exec banc bash
#   --nouvelles-references remplace reference/ par les courriels produits : à relire avant de commiter
#   --seulement MOTIF      n'exécute que les scénarios dont le nom contient MOTIF (ex. 49_) : pour mettre un scénario au
#                          point ; jamais pour valider une version (le banc vert est celui qui les exécute tous)
set -euo pipefail

DOSSIER_BANC="$(cd "$(dirname "$0")" && pwd)"
RACINE_DEPOT="$(git -C "$DOSSIER_BANC" rev-parse --show-toplevel)"
PROJET=sbbanc

export VERSION_MARIADB=12
GARDER=non
NOUVELLES_REFERENCES=0
SEULEMENT=""
while [ $# -gt 0 ]; do
	case "$1" in
		--mariadb) VERSION_MARIADB="${2:?--mariadb demande une version}"; shift 2 ;;
		--garder) GARDER=oui; shift ;;
		--nouvelles-references) NOUVELLES_REFERENCES=1; shift ;;
		--seulement) SEULEMENT="${2:?--seulement demande un motif}"; shift 2 ;;
		*) echo "Option inconnue : $1" >&2; exit 2 ;;
	esac
done

# Fichiers suivis par Git et nouveaux fichiers non ignorés, tels qu'ils sont sur le disque. Passer par
# Git garde hors des images ce qui ne doit jamais y entrer, surtout Docker/.env et ses vrais secrets.
# Les fichiers appartiennent à root dans l'archive, comme avec un contexte de construction ordinaire : logrotate
# refuse une configuration qui appartient à quelqu'un d'autre.
archive_du_dossier() {
	local dossier="$1"
	(
		cd "$dossier"
		git ls-files -z --cached --others --exclude-standard \
			| while IFS= read -r -d '' fichier; do
				[ -e "$fichier" ] && printf '%s\0' "$fichier"
			done \
			| tar --null --files-from=- --create --file=- --owner=0 --group=0 --numeric-owner
	)
}

composer() {
	docker compose -p "$PROJET" -f "$DOSSIER_BANC/compose.yml" "$@"
}

nettoyer() {
	if [ "$GARDER" = oui ]; then
		echo "Conteneurs gardés. Pour les effacer : docker compose -p $PROJET -f $DOSSIER_BANC/compose.yml down -v"
	else
		composer down -v --remove-orphans >/dev/null 2>&1 || true
	fi
}

echo "=== Construction des images (la première fois prend plusieurs minutes) ==="
archive_du_dossier "$RACINE_DEPOT/Docker" \
	| docker build --quiet --build-arg TZ=America/New_York -f DockerfilePHP -t sb-banc-base - >/dev/null
archive_du_dossier "$RACINE_DEPOT" \
	| docker build --quiet -f SwitchBoard/tests/banc/Dockerfile -t sb-banc - >/dev/null

echo "=== Démarrage (MariaDB $VERSION_MARIADB) ==="
composer down -v --remove-orphans >/dev/null 2>&1 || true
trap nettoyer EXIT
composer up -d --wait >/dev/null

ECHECS=0
DEBUT_TOTAL=$SECONDS
DUREES=""
for scenario in "$DOSSIER_BANC"/scenarios/*; do
	nom="$(basename "$scenario")"
	[ -z "$SEULEMENT" ] || [[ "$nom" == *"$SEULEMENT"* ]] || continue
	echo ""
	echo "=== $nom ==="
	debut=$SECONDS
	if ! composer exec -T -e "BANC_NOUVELLES_REFERENCES=$NOUVELLES_REFERENCES" banc "/banc/scenarios/$nom"; then
		echo "ECHEC du scénario : $nom"
		ECHECS=$((ECHECS + 1))
	fi
	DUREES+="$(printf '%5d s  %s' "$((SECONDS - debut))" "$nom")"$'\n'
done

echo ""
echo "=== Durée de chaque scénario ==="
printf '%s' "$DUREES"
echo "$(printf '%5d s  %s' "$((SECONDS - DEBUT_TOTAL))" "total")"

if [ "$NOUVELLES_REFERENCES" = 1 ]; then
	mkdir -p "$DOSSIER_BANC/reference"
	composer cp banc:/var/banc-sortie/reference/. "$DOSSIER_BANC/reference/"
	echo "Références remplacées : relire git diff $DOSSIER_BANC/reference avant de commiter."
fi

echo ""
if [ "$ECHECS" -eq 0 ]; then
	echo "BANC VERT : tous les scénarios ont réussi."
else
	echo "BANC ROUGE : $ECHECS scénario(s) en échec."
fi
exit "$((ECHECS > 0))"
