#!/bin/bash
# Lance tous les tests (surveillance dead-man's-switch, verrou des crons, reprise voip.ms, liste
# conservée du groupe de sonnerie, repères de l'agenda, rapport de coûts d'un comité, téléchargement des agendas, agenda illisible,
# numéro lu dans l'agenda, rappels SMS et chat). Pas de framework (aucun n'existe dans ce dépôt) - orchestration
# simple en bash, cohérente avec le reste de l'outillage du projet.
set -e
cd "$(dirname "$0")"

echo "=== Démarrage du serveur factice (stubheartbeatserver.php) sur 127.0.0.1:8999 ==="
php -S 127.0.0.1:8999 stubheartbeatserver.php >/tmp/stubserver.log 2>&1 &
STUB_PID=$!
echo "=== Démarrage du serveur voip.ms factice (stubvoipmsserver.php) sur 127.0.0.1:8998 ==="
php -S 127.0.0.1:8998 stubvoipmsserver.php >/tmp/stubvoipmsserver.log 2>&1 &
STUB_VOIPMS_PID=$!
echo "=== Démarrage du faux compte voip.ms (stubvoipmscompte.php) sur 127.0.0.1:8996 ==="
php -S 127.0.0.1:8996 stubvoipmscompte.php >/tmp/stubvoipmscompte.log 2>&1 &
STUB_COMPTE_PID=$!
echo "=== Démarrage du faux serveur d'agendas (stubagendaserver.php) sur 127.0.0.1:8995 ==="
php -S 127.0.0.1:8995 stubagendaserver.php >/tmp/stubagendaserver.log 2>&1 &
STUB_AGENDA_PID=$!
sleep 1

STATUT=0

echo ""
echo "=== deadmanswitchmanager_test.php ==="
if ! php deadmanswitchmanager_test.php; then
	STATUT=1
fi

echo ""
echo "=== verrouexclusif_test.php ==="
if ! php verrouexclusif_test.php; then
	STATUT=1
fi

echo ""
echo "=== voipmsreprise_test.php ==="
if ! php voipmsreprise_test.php; then
	STATUT=1
fi

echo ""
echo "=== listeconservee_test.php ==="
if ! php listeconservee_test.php; then
	STATUT=1
fi

echo ""
echo "=== reperes_test.php ==="
if ! php reperes_test.php; then
	STATUT=1
fi

echo ""
echo "=== accesutilisateur_test.php ==="
if ! php accesutilisateur_test.php; then
	STATUT=1
fi

echo ""
echo "=== rapportcomite_test.php ==="
if ! php rapportcomite_test.php; then
	STATUT=1
fi

echo ""
echo "=== telechargementagenda_test.php ==="
if ! php telechargementagenda_test.php; then
	STATUT=1
fi

echo ""
echo "=== agendaillisible_test.php ==="
if ! php agendaillisible_test.php; then
	STATUT=1
fi

echo ""
echo "=== numeroagenda_test.php ==="
if ! php numeroagenda_test.php; then
	STATUT=1
fi

echo ""
echo "=== rappels_test.php ==="
if ! php rappels_test.php; then
	STATUT=1
fi

echo ""
echo "=== elementscommuns_test.php ==="
if ! php elementscommuns_test.php; then
	STATUT=1
fi

echo ""
echo "=== Arrêt des serveurs factices ==="
kill "$STUB_PID" "$STUB_VOIPMS_PID" "$STUB_COMPTE_PID" "$STUB_AGENDA_PID" 2>/dev/null || true
rm -f stub-requests.log stub-voipms-*.compteur stub-compte.json

if [ "$STATUT" -eq 0 ]; then
	echo ""
	echo "TOUS LES TESTS ONT RÉUSSI"
else
	echo ""
	echo "AU MOINS UN TEST A ÉCHOUÉ"
fi
exit $STATUT
