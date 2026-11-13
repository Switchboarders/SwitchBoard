<?php
ini_set('memory_limit', '128M');
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';
// Script batch : exécutable en CLI (cron) sans restriction ; en HTTP, exige la clé partagée
// CRON_WEB_KEY (voir authhelpers.php) pour empêcher un déclenchement public.
requireCliOrCronKey();

// Vérification volontairement étroite (BD + disque) plutôt qu'exhaustive - voir
// DEADMANSWITCH-SETUP.md. Aucun check "cron est-il vivant" séparé : si cron lui-même meurt, les
// 4 checks de surveillance deviennent silencieux EN MÊME TEMPS, ce qui signale déjà sans
// ambiguïté un problème d'infrastructure/conteneur, distinct d'un seul sous-système en panne.
$dms = new SignalDeVie('infra');
$dms->armerDetectionEchec();

if (!$dms->estConfigure()) {
	$dms->finirAvecSucces();
	exit(0);
}

// 1) Base de données : construire DatabaseManager() valide déjà la connexion (die() explicite
// sinon, capté par le repli générique de armerDetectionEchec() ci-dessus).
$databasemanager = new DatabaseManager();

// 2) Espace disque restant (même disque hôte que la BD/journaux dans ce déploiement mono-hôte).
$libre = @disk_free_space(BASE_DIRECTORY);
$total = @disk_total_space(BASE_DIRECTORY);
$messages = [];
$echec = false;
if ($libre === false || $total === false || $total <= 0) {
	$messages[] = "Impossible de lire l'espace disque pour ".BASE_DIRECTORY;
} else {
	$pourcentLibre = round(($libre / $total) * 100, 1);
	$giboLibre = round($libre / (1024*1024*1024), 2);
	$messages[] = "Disque : {$giboLibre} Go libres ({$pourcentLibre}%)";
	$seuilPourcent = floatval(getenv('SB_DMS_INFRA_DISK_MIN_PERCENT') ?: 10);
	$seuilGo = floatval(getenv('SB_DMS_INFRA_DISK_MIN_GB') ?: 1);
	if ($pourcentLibre < $seuilPourcent || $giboLibre < $seuilGo) {
		$echec = true;
		$messages[] = "SOUS LE SEUIL (min {$seuilPourcent}% ou {$seuilGo} Go)";
	}
}

$resume = implode(' | ', $messages);
if ($echec) {
	$dms->finirAvecEchec($resume);
	echo "checkinfra: échec - $resume\n";
} else {
	$dms->finirAvecSucces($resume);
	echo "checkinfra: succès - $resume\n";
}
