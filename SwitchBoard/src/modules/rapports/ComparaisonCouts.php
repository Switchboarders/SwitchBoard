<?php
// Construit une comparaison des coûts entre plusieurs comptes voip.ms (un "groupe" = un
// utilisateur SwitchBoard = un compte voip.ms indépendant, voir InformationsUtilisateur.php), répartis
// mois par mois sur une période choisie, avec le solde actuel de chaque compte. Utilisé pour
// appuyer une décision de consolidation de plusieurs comptes en un seul.
//
// Réutilise entièrement le moteur de calcul existant (ClientVoipms + CoutsDid, voir
// RapportCoutsDid.php pour le même enchaînement appliqué à un seul compte/une seule
// période) : aucune logique de réconciliation des coûts n'est réinventée ici.
//
// Le travail est volontairement découpé en petites unités (une par compte pour le solde/DID, une
// par compte x mois pour le coût) plutôt que fait en un seul bloc synchrone : pour une période
// longue ou plusieurs comptes, le nombre total d'appels API dépasserait facilement le délai
// d'exécution par défaut de PHP. Voir TacheComparaisonCouts, qui pilote ces unités une à la fois
// depuis le navigateur (petits appels AJAX successifs) au lieu d'augmenter ce délai.
class ComparaisonCouts {

	/**
	 * Liste les comptes admissibles (un utilisateur SwitchBoard avec un compte voip.ms
	 * configuré) pour la case à cocher du formulaire.
	 *
	 * @return array<int, array{iduser:int, username:string}>
	 */
	public function listEligibleAccounts(DatabaseManager $databasemanager): array {
		$comptes = [];
		$result = $databasemanager->getuserslist();
		if ($result === null || $result === false) {
			return $comptes;
		}
		while ($row = $result->fetch_assoc()) {
			$userinformations = new InformationsUtilisateur((int)$row['iduser'], true);
			if ($userinformations->voipmsusername !== '') {
				$comptes[] = ['iduser' => (int)$row['iduser'], 'username' => $row['username']];
			}
		}
		return $comptes;
	}

	/**
	 * Découpe [$dateFrom,$dateTo] (format "Y-m-d") en tranches calendaires mensuelles. Le premier
	 * et le dernier mois sont "clippés" aux vraies dates choisies (pas arrondis au mois complet) ;
	 * chaque mois intermédiaire est complet. Pure fonction, aucun appel API.
	 *
	 * @return array<int, array{label:string, dateFrom:string, dateTo:string}>
	 */
	public function buildMonthSlices(string $dateFrom, string $dateTo): array {
		$slices = [];
		$cursor = DateTime::createFromFormat('Y-m-d', $dateFrom);
		$fin = DateTime::createFromFormat('Y-m-d', $dateTo);
		if ($cursor === false || $fin === false || $cursor > $fin) {
			return $slices;
		}
		while ($cursor <= $fin) {
			$label = $cursor->format('Y-m');
			$sliceDebut = clone $cursor;
			$finDeMois = DateTime::createFromFormat('Y-m-d', $cursor->format('Y-m-t'));
			$sliceFin = ($finDeMois < $fin) ? $finDeMois : clone $fin;
			$slices[] = [
				'label' => $label,
				'dateFrom' => $sliceDebut->format('Y-m-d'),
				'dateTo' => $sliceFin->format('Y-m-d'),
			];
			$cursor = (clone $sliceFin)->modify('+1 day');
		}
		return $slices;
	}

	/**
	 * Construit la liste ordonnée des unités de travail (une "init" par compte, une "month" par
	 * compte x tranche de mois) - pure fonction, aucun appel API. C'est cette liste que
	 * TacheComparaisonCouts parcourt une unité à la fois.
	 *
	 * @param int[] $idusers
	 * @return array{monthSlices: array, items: array}
	 */
	public function buildWorkPlan(array $idusers, string $dateFrom, string $dateTo): array {
		$monthSlices = $this->buildMonthSlices($dateFrom, $dateTo);
		$items = [];
		foreach ($idusers as $iduser) {
			$iduser = (int)$iduser;
			$items[] = ['type' => 'init', 'iduser' => $iduser];
			foreach ($monthSlices as $slice) {
				$items[] = [
					'type' => 'month',
					'iduser' => $iduser,
					'label' => $slice['label'],
					'dateFrom' => $slice['dateFrom'],
					'dateTo' => $slice['dateTo'],
				];
			}
		}
		return ['monthSlices' => $monthSlices, 'items' => $items];
	}

	/**
	 * Unité de travail "init" : solde actuel + liste des DID du compte (ni l'un ni l'autre n'est
	 * lié à une tranche de mois précise, un seul appel de chaque par compte - comme dans
	 * RapportCoutsDid::processUser()). La liste des DID est retournée pour être réutilisée
	 * par processMonthItem() sur chaque tranche de mois, sans la redemander à chaque fois.
	 *
	 * @return array{username:string, balance:?float, didsInfo:array, errors:array}
	 */
	public function processInitItem(DatabaseManager $databasemanager, int $iduser): array {
		$username = $databasemanager->getuserbyid($iduser)['username'] ?? ("iduser=".$iduser);
		$resultat = ['username' => $username, 'balance' => null, 'didsInfo' => [], 'errors' => []];

		$userinformations = new InformationsUtilisateur($iduser, true);
		if ($userinformations->voipmsusername === '') {
			$resultat['errors'][] = _("Aucun compte voip.ms configuré pour cet utilisateur.");
			return $resultat;
		}
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$voipms = new ClientVoipms($userinformations);

		$balanceArr = $voipms->lireSolde();
		if (is_string($balanceArr)) {
			$resultat['errors'][] = _("Retour de getBalance: ").$balanceArr;
		} else {
			// Réponse documentée de voip.ms pour getBalance : {"balance":{"current_balance":"...", ...}}
			// - ClientVoipms::lireSolde() a déjà dépouillé la clé "balance" externe, donc
			// $balanceArr est ici directement ce tableau interne.
			if (isset($balanceArr['current_balance']) && is_numeric($balanceArr['current_balance'])) {
				$resultat['balance'] = (float)$balanceArr['current_balance'];
			} else {
				$resultat['errors'][] = _("Format de solde inattendu (clé current_balance absente) : ").json_encode($balanceArr);
			}
		}

		$didsInfoArr = $voipms->lireDids();
		if (is_string($didsInfoArr)) {
			$resultat['errors'][] = _("Retour de getDidsInfo: ").$didsInfoArr;
			$didsInfoArr = [];
		}
		$resultat['didsInfo'] = $didsInfoArr;
		return $resultat;
	}

	/**
	 * Unité de travail "month" : coût d'UN compte pour UNE tranche de mois. $didsInfo doit venir
	 * du résultat de processInitItem() pour ce même compte (pas redemandé ici).
	 *
	 * @return array{cost:?float, errors:array}
	 */
	public function processMonthItem(int $iduser, array $didsInfo, string $sliceDateFrom, string $sliceDateTo): array {
		$resultat = ['cost' => null, 'errors' => []];

		$userinformations = new InformationsUtilisateur($iduser, true);
		if ($userinformations->voipmsusername === '') {
			// Déjà signalé par l'étape "init" pour ce compte - rien de plus à ajouter ici.
			return $resultat;
		}
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$voipms = new ClientVoipms($userinformations, $sliceDateFrom, $sliceDateTo);
		$cdrArr = $voipms->lireAppelsComplets();
		$transactionsArr = $voipms->lireTransactions();

		if (is_string($cdrArr) || is_string($transactionsArr)) {
			if (is_string($cdrArr)) {
				$resultat['errors'][] = _("Retour de getCdrComplet: ").$cdrArr;
			}
			if (is_string($transactionsArr)) {
				$resultat['errors'][] = _("Retour de getTransactionHistory: ").$transactionsArr;
			}
			return $resultat;
		}

		$didcostmanager = new CoutsDid($userinformations, $didsInfo, $cdrArr, $transactionsArr);
		$rapport = $didcostmanager->buildReport();
		$resultat['cost'] = round($rapport['grandTotal'], 2);
		return $resultat;
	}
}

?>
