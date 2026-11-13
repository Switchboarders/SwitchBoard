<?php
// Pilote le calcul de ComparaisonCouts une petite unité de travail à la fois (voir
// buildWorkPlan()/processInitItem()/processMonthItem()), au lieu de tout faire en un seul appel
// synchrone - pour une période longue ou plusieurs comptes, ce dernier dépasserait facilement le
// délai d'exécution par défaut de PHP. Chaque étape ne fait que 1 à 3 appels API, donc ne peut
// jamais dépasser ce délai, peu importe la taille de la période ou le nombre de comptes choisis.
//
// L'état de la tâche ("job") est persisté entre les appels dans un simple fichier JSON, lié à
// l'utilisateur qui l'a créée (voir chargerJob()) : personne d'autre ne peut lire ni faire
// avancer le job d'un autre admin, même en devinant le jeton.
class TacheComparaisonCouts {

	private function cheminJob(string $token): string {
		return SRC_DIRECTORY.'files/costreportjob_'.$token.'.json';
	}

	private function chargerJob(string $token): ?array {
		// Le jeton vient du client (POST) : on valide sa forme AVANT de s'en servir dans un
		// chemin de fichier, même si SRC_DIRECTORY.'files/' n'est de toute façon jamais traversable
		// par un jeton de cette forme.
		if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
			return null;
		}
		$chemin = $this->cheminJob($token);
		if (!is_readable($chemin)) {
			return null;
		}
		$job = json_decode((string)file_get_contents($chemin), true);
		if (!is_array($job)) {
			return null;
		}
		if (($job['proprietaire'] ?? null) !== ($_SESSION['iduser'] ?? null)) {
			return null;
		}
		return $job;
	}

	private function sauvegarderJob(string $token, array $job): void {
		// src/files/ n'existe pas forcément (rien ne le crée au déploiement - les autres
		// fonctionnalités qui y écrivent, ex. Fichiers::createCsvFile(), le créent elles-
		// mêmes à la volée) : sans ce mkdir, file_put_contents() échoue silencieusement (aucune
		// exception), donnant l'impression que la tâche a démarré alors qu'elle n'a jamais été
		// écrite sur disque - exactement le bug "Tâche introuvable ou expirée" rapporté.
		$dossier = dirname($this->cheminJob($token));
		if (!is_dir($dossier)) {
			mkdir($dossier, 0775, true);
		}
		file_put_contents($this->cheminJob($token), json_encode($job));
	}

	/**
	 * Crée une nouvelle tâche pour $idusers sur [$dateFrom,$dateTo] et retourne son jeton ainsi
	 * que le nombre total d'étapes à traiter (pour la barre de progression côté navigateur).
	 *
	 * @param int[] $idusers
	 */
	public function demarrerJob(array $idusers, string $dateFrom, string $dateTo): array {
		$dateFromValide = DateTime::createFromFormat('Y-m-d', $dateFrom);
		$dateToValide = DateTime::createFromFormat('Y-m-d', $dateTo);
		if (!$dateFromValide || $dateFromValide->format('Y-m-d') !== $dateFrom) {
			return ['erreur' => _("Date de début invalide.")];
		}
		if (!$dateToValide || $dateToValide->format('Y-m-d') !== $dateTo) {
			return ['erreur' => _("Date de fin invalide.")];
		}
		if ($dateFromValide > $dateToValide) {
			return ['erreur' => _("La date de début doit précéder la date de fin.")];
		}
		if (empty($idusers)) {
			return ['erreur' => _("Sélectionnez au moins un compte.")];
		}

		$manager = new ComparaisonCouts();
		$plan = $manager->buildWorkPlan($idusers, $dateFrom, $dateTo);

		$comptes = [];
		foreach ($idusers as $iduser) {
			$comptes[(string)(int)$iduser] = [
				'username' => null,
				'balance' => null,
				'didsInfo' => [],
				'monthlyCosts' => [],
				'errors' => [],
			];
		}

		$token = bin2hex(random_bytes(16));
		$job = [
			'proprietaire' => $_SESSION['iduser'] ?? null,
			'dateFrom' => $dateFrom,
			'dateTo' => $dateTo,
			'monthSlices' => $plan['monthSlices'],
			'items' => $plan['items'],
			'nextIndex' => 0,
			'accounts' => $comptes,
		];
		$this->sauvegarderJob($token, $job);

		return ['token' => $token, 'total' => count($plan['items'])];
	}

	/** Traite la prochaine unité de travail de la tâche $token (une seule, puis retourne). */
	public function traiterEtape(DatabaseManager $databasemanager, string $token): array {
		$job = $this->chargerJob($token);
		if ($job === null) {
			return ['erreur' => _("Tâche introuvable ou expirée.")];
		}
		$total = count($job['items']);
		if ($job['nextIndex'] >= $total) {
			return ['done' => true, 'progress' => $total, 'total' => $total];
		}

		$item = $job['items'][$job['nextIndex']];
		$manager = new ComparaisonCouts();
		$iduserCle = (string)$item['iduser'];

		if ($item['type'] === 'init') {
			$resultat = $manager->processInitItem($databasemanager, (int)$item['iduser']);
			$job['accounts'][$iduserCle]['username'] = $resultat['username'];
			$job['accounts'][$iduserCle]['balance'] = $resultat['balance'];
			$job['accounts'][$iduserCle]['didsInfo'] = $resultat['didsInfo'];
			array_push($job['accounts'][$iduserCle]['errors'], ...$resultat['errors']);
		} else {
			$didsInfo = $job['accounts'][$iduserCle]['didsInfo'] ?? [];
			$resultat = $manager->processMonthItem((int)$item['iduser'], $didsInfo, $item['dateFrom'], $item['dateTo']);
			if ($resultat['cost'] !== null) {
				$job['accounts'][$iduserCle]['monthlyCosts'][$item['label']] = $resultat['cost'];
			}
			array_push($job['accounts'][$iduserCle]['errors'], ...$resultat['errors']);
		}

		$job['nextIndex']++;
		$this->sauvegarderJob($token, $job);

		return ['done' => $job['nextIndex'] >= $total, 'progress' => $job['nextIndex'], 'total' => $total];
	}

	/**
	 * Assemble la matrice finale (coût par compte par mois, totaux combinés) à partir de l'état
	 * accumulé de la tâche $token - à appeler une fois traiterEtape() arrivé à done=true.
	 */
	public function obtenirMatrice(string $token): array {
		$job = $this->chargerJob($token);
		if ($job === null) {
			return ['erreur' => _("Tâche introuvable ou expirée.")];
		}

		$totalParMois = [];
		foreach ($job['monthSlices'] as $slice) {
			$totalParMois[$slice['label']] = 0.0;
		}
		$grandTotalTousComptes = 0.0;
		$soldeCombine = 0.0;
		$comptes = [];

		foreach ($job['accounts'] as $iduser => $compteJob) {
			$periodTotal = array_sum($compteJob['monthlyCosts']);
			$nbMoisTraites = count($compteJob['monthlyCosts']);
			foreach ($compteJob['monthlyCosts'] as $label => $cout) {
				$totalParMois[$label] = ($totalParMois[$label] ?? 0.0) + $cout;
			}
			$grandTotalTousComptes += $periodTotal;
			if ($compteJob['balance'] !== null) {
				$soldeCombine += $compteJob['balance'];
			}
			$comptes[] = [
				'iduser' => (int)$iduser,
				'username' => $compteJob['username'],
				'monthlyCosts' => $compteJob['monthlyCosts'],
				'periodTotal' => round($periodTotal, 2),
				'monthlyAverage' => $nbMoisTraites > 0 ? round($periodTotal / $nbMoisTraites, 2) : 0.0,
				'balance' => $compteJob['balance'],
				'errors' => $compteJob['errors'],
			];
		}

		return [
			'dateFrom' => $job['dateFrom'],
			'dateTo' => $job['dateTo'],
			'monthSlices' => $job['monthSlices'],
			'accounts' => $comptes,
			'totalParMois' => array_map(fn($v) => round($v, 2), $totalParMois),
			'grandTotalAllAccounts' => round($grandTotalTousComptes, 2),
			'combinedBalance' => round($soldeCombine, 2),
		];
	}

	/** Supprime le fichier de la tâche $token (appelé après un téléchargement réussi). */
	public function supprimerJob(string $token): void {
		if (preg_match('/^[a-f0-9]{32}$/', $token)) {
			@unlink($this->cheminJob($token));
		}
	}
}

?>
