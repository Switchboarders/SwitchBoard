<?php
//Répartit les coûts d'un compte voip.ms entre tous ses DID pour une période donnée.
//Ne fait aucun appel API : reçoit les données déjà extraites (getDidsInfo, getCdrComplet, getTransactionHistory).
class CoutsDid{
	protected InformationsUtilisateur $userinformations;
	protected array $didsInfoRows;
	protected array $cdrRows;
	protected array $transactionRows;

	//Motif utilisé pour repérer, dans le champ call_logs d'une ligne CDR, un segment sortant
	//déclenché par un renvoi (Ring Group / Call Forwarding). Le texte exact retourné par voip.ms
	//pour un renvoi de Ring Group n'a pas encore été confirmé en conditions réelles : à ajuster
	//au besoin après une première extraction réelle (voir plan).
	protected string $forwardLegPattern = '/forward/i';

	//Motif utilisé pour extraire le numéro de DID d'un type de transaction voip.ms
	//(ex.: "DID5145551234" ou "SETUPDID5145551234").
	protected string $didTxnSuffixPattern = '/(\d{10,11})$/';

	public function __construct(InformationsUtilisateur $userinformations, array $didsInfoRows, array $cdrRows, array $transactionRows){
		$this->userinformations = $userinformations;
		$this->didsInfoRows = $didsInfoRows;
		$this->cdrRows = $cdrRows;
		$this->transactionRows = $transactionRows;
	}

	//Uniformise un numéro (CDR, transaction ou liste de DID) en gardant les 10 derniers chiffres,
	//pour ignorer les variations d'indicatif pays (+1) selon la provenance de la donnée.
	protected function normalizeDid($number){
		$digits = preg_replace('/\D/', '', (string)$number);
		return substr($digits, -10);
	}

	protected function emptyDidEntry($description = "", $currentMonthlyRate = 0.0){
		return [
			'description' => $description,
			//Taux mensuel actuel (getDIDsInfo), affiché à titre indicatif seulement : la vraie
			//charge de location facturée pour la période vient de l'historique des transactions
			//(étape 3), seule source retenue dans rentalFees/totalCost pour éviter un double compte.
			'currentMonthlyRate' => $currentMonthlyRate,
			'calls' => 0,
			'answeredCalls' => 0,
			'seconds' => 0,
			'callCost' => 0.0,
			'rentalFees' => 0.0,
			'sharedCostShare' => 0.0,
			'totalCost' => 0.0,
		];
	}

	public function buildReport(){
		///////////////// Étape 1 : liste canonique des DID du compte ////////////////////////////
		//Garantit qu'un DID sans aucun appel ce mois-ci obtient quand même une ligne (0 appel +
		//ses frais de location), au lieu de disparaître silencieusement du rapport.
		$perDid = [];
		foreach ($this->didsInfoRows as $didRow):
			$did = $this->normalizeDid($didRow['did'] ?? '');
			if ($did === ''):
				continue;
			endif;
			$perDid[$did] = $this->emptyDidEntry($didRow['description'] ?? '', (float)($didRow['monthly'] ?? 0));
		endforeach;

		///////////////// Étape 2 : regroupement/appariement du CDR par DID ///////////////////////
		//Repère d'abord les segments sortants probablement déclenchés par un renvoi (candidats
		//d'appariement), regroupés par date seulement (pas par (date,secondes) exactes - voir plus
		//bas pourquoi).
		//
		//Un ring group peut sonner plusieurs membres simultanément (jusqu'à 4, voir setRinggroup())
		//: un seul appel entrant peut donc produire PLUSIEURS segments sortants "Call Forwarding"
		//à la même date, un seul ayant réellement été décroché. Vérifié avec un compte réel
		//(bcsquebec, juin 2026, script inspect_call_logs.php) : apparier sur (date, secondes)
		//exactes ne trouvait le bon segment que dans 135 cas sur 359 - la durée du segment sortant
		//diffère souvent de quelques secondes de celle de l'appel entrant (mesure du temps de
		//sonnerie vs temps de conversation). Apparier sur la date seule (premier disponible) trouve
		//plus de correspondances (305) mais risque d'attraper le mauvais segment simultané (souvent
		//à 0$, un membre qui n'a pas répondu) : sur ce compte ça ne récupérait même pas plus d'argent
		//que la stratégie actuelle (7.15$ vs 7.23$). Apparier sur la date, puis choisir parmi les
		//candidats de cette date celui dont la durée est la PLUS PROCHE de l'appel entrant,
		//récupère les 305 correspondances ET le bon montant (10.92$ au lieu de 7.23$, soit 3.68$ de
		//moins mal attribués au montant partagé pour ce seul compte/mois).
		$cdrRows = array_values($this->cdrRows);
		$outboundPoolByDate = [];
		foreach ($cdrRows as $idx => $row):
			$destination = $this->normalizeDid($row['destination'] ?? '');
			$callLogs = $row['call_logs'] ?? '';
			if (!isset($perDid[$destination]) and preg_match($this->forwardLegPattern, $callLogs)):
				$outboundPoolByDate[$row['date'] ?? ''][] = $idx;
			endif;
		endforeach;

		//$consumedIdx retient, par index de ligne CDR (et non par uniqueid, dont la présence n'est
		//pas confirmée), toute ligne déjà comptée : une ligne d'appel entrant vers un DID possédé,
		//ou un segment sortant apparié à un tel appel. Ceci garantit qu'aucune ligne n'est comptée
		//deux fois ni oubliée (voir étape 2, passe finale).
		$consumedIdx = [];

		foreach ($cdrRows as $idx => $row):
			$destination = $this->normalizeDid($row['destination'] ?? '');
			$description = strtolower($row['description'] ?? '');
			if (isset($perDid[$destination]) and str_contains($description, 'inbound')):
				$cost = (float)($row['total'] ?? 0);
				$perDid[$destination]['calls']++;
				if (($row['disposition'] ?? '') === 'ANSWERED'):
					$perDid[$destination]['answeredCalls']++;
					$perDid[$destination]['seconds'] += (int)($row['seconds'] ?? 0);
				endif;

				//Tenter d'apparier avec le segment sortant du renvoi dont la durée est la plus
				//proche, parmi les candidats de la même date, pour que le coût du renvoi ne soit
				//pas perdu (attribué au même DID que l'appel entrant).
				$date = $row['date'] ?? '';
				$targetSeconds = (int)($row['seconds'] ?? 0);
				$bestPos = null;
				$bestDelta = null;
				if (!empty($outboundPoolByDate[$date])):
					foreach ($outboundPoolByDate[$date] as $poolPos => $candidateIdx):
						if (isset($consumedIdx[$candidateIdx])):
							continue;
						endif;
						$delta = abs((int)($cdrRows[$candidateIdx]['seconds'] ?? 0) - $targetSeconds);
						if ($bestDelta === null or $delta < $bestDelta):
							$bestDelta = $delta;
							$bestPos = $poolPos;
						endif;
					endforeach;
				endif;
				if ($bestPos !== null):
					$candidateIdx = $outboundPoolByDate[$date][$bestPos];
					$cost += (float)($cdrRows[$candidateIdx]['total'] ?? 0);
					$consumedIdx[$candidateIdx] = true;
				endif;

				$perDid[$destination]['callCost'] += $cost;
				$consumedIdx[$idx] = true;
			endif;
		endforeach;

		//Passe finale (aucune omission) : tout ce qui n'a pas été attribué à un DID possédé ni
		//consommé comme segment apparié va dans un seul montant « non attribué à un DID ».
		$nonAttributedCdrCost = 0.0;
		foreach ($cdrRows as $idx => $row):
			if (isset($consumedIdx[$idx])):
				continue;
			endif;
			$nonAttributedCdrCost += (float)($row['total'] ?? 0);
		endforeach;

		///////////////// Étape 3 : répartition de l'historique des transactions //////////////////
		$otherAccountFees = [];
		$otherAccountFeesTotal = 0.0;
		foreach ($this->transactionRows as $txn):
			$type = $txn['type'] ?? '';
			$amount = abs((float)($txn['ammount'] ?? 0));
			if ($type === 'CC'):
				//Recharge par carte de crédit : pas un coût, on l'ignore ici.
				continue;
			endif;
			if (preg_match($this->didTxnSuffixPattern, $type, $matches)):
				$did = $this->normalizeDid($matches[1]);
				if (!isset($perDid[$did])):
					//DID mentionné dans les transactions mais absent de getDidsInfo (ex.: DID annulé
					//en cours de mois) : on le crée quand même pour ne perdre aucun montant.
					$perDid[$did] = $this->emptyDidEntry();
				endif;
				$perDid[$did]['rentalFees'] += $amount;
				continue;
			endif;
			//Tout le reste (catch-all) : jamais ignoré silencieusement, même un type de frais
			//que ce code ne connaît pas encore.
			$otherAccountFees[] = [
				'type' => $type,
				'description' => $txn['description'] ?? '',
				'amount' => $amount,
			];
			$otherAccountFeesTotal += $amount;
		endforeach;

		///////////////// Étape 4 : part égale des coûts non attribuables à un DID /////////////////
		$didCount = count($perDid);
		$sharedTotal = $nonAttributedCdrCost + $otherAccountFeesTotal;
		$perDidShare = $didCount > 0 ? $sharedTotal / $didCount : 0.0;

		foreach ($perDid as $did => &$data):
			$data['sharedCostShare'] = $perDidShare;
			$data['totalCost'] = $data['callCost'] + $data['rentalFees'] + $data['sharedCostShare'];
		endforeach;
		unset($data);

		$grandTotal = 0.0;
		foreach ($perDid as $data):
			$grandTotal += $data['totalCost'];
		endforeach;

		return [
			'perDid' => $perDid,
			'shared' => [
				'nonAttributedCdrCost' => $nonAttributedCdrCost,
				'otherAccountFees' => $otherAccountFees,
				'otherAccountFeesTotal' => $otherAccountFeesTotal,
				'sharedTotal' => $sharedTotal,
				'perDidShare' => $perDidShare,
				'didCount' => $didCount,
			],
			'grandTotal' => $grandTotal,
		];
	}
}

?>
