<?php
//Construit (et optionnellement envoie par courriel) le rapport de coûts par DID d'un seul
//utilisateur, pour une plage de dates donnée. Utilisé par le script CLI gendidcostreport.php
//ET par les pages web (un utilisateur consultant ses propres coûts, un admin consultant/envoyant
//ceux d'un autre utilisateur) : une seule logique, peu importe le déclencheur.
//Suppose que DatabaseManager, InformationsUtilisateur, Courriel, ClientVoipms, CoutsDid et
//Fichiers sont déjà chargés par l'appelant (même convention que les autres classes du projet).
class RapportCoutsDid {

	/**
	 * Construit le rapport de coûts par DID de $iduser pour la période [$dateFrom,$dateTo].
	 * Écrit toujours le CSV sur disque (utile même en consultation web, pour archive). N'envoie
	 * le courriel que si $sendEmail est vrai (une simple consultation web n'a pas besoin d'envoyer
	 * un courriel à chaque clic).
	 *
	 * Contenu selon le type de compte (DatabaseManager::getTypeCompte()) :
	 * - comité local autonome, ou compte principal : tous les DID du compte et le solde ;
	 * - comité local membre : seulement son DID, sans le solde ni les totaux du compte, qui
	 *   appartiennent au compte principal.
	 * $vueAdmin : un admin qui consulte la page d'un comité voit tout le compte à l'écran. Le
	 * courriel, lui, contient toujours la version du comité.
	 *
	 * @return array{success:bool, username:string, emailmanager:Courriel, report:?array, typeCompte:string}
	 */
	public function processUser(int $iduser, string $dateFrom, string $dateTo, bool $sendEmail = true, ?JournalDeTache $logmanager = null, bool $vueAdmin = false): array {
		$databasemanager = new DatabaseManager();
		$userinformations = new InformationsUtilisateur($iduser, true);
		$username = $databasemanager->getuserbyid($iduser)['username'];
		$typeCompte = $databasemanager->getTypeCompte($iduser);
		// L'écran de l'admin montre tout le compte ; un courriel envoyé au comité, jamais
		$montrerToutLeCompte = ($vueAdmin && !$sendEmail);
		$seulementLeDidDuComite = ($typeCompte === DatabaseManager::COMITE_MEMBRE && !$montrerToutLeCompte);

		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$corps = new CorpsDuRapport($emailmanager, $logmanager);
		$sujetEnErreur = _("Rapport de coûts par DID : erreur d'extraction");
		$corps->ajouter(_("Bonjour ").$username.",\n");
		$corps->ajouter(_("Voici la répartition des coûts par DID pour la période mentionnée:"));

		// Sans identifiants, voip.ms ne renvoie qu'une erreur incompréhensible : le dire clairement
		if ($userinformations->voipmsusername === '') {
			$corps->dire(_("Aucun compte voip.ms configuré pour cet utilisateur."));
			if ($sendEmail) {
				$emailmanager->sendemail($sujetEnErreur);
			}
			return $this->resultat(false, $username, $emailmanager, null, $typeCompte);
		}

		$voipms = new ClientVoipms($userinformations, $dateFrom, $dateTo);
		$didsInfoArr = $voipms->lireDids();
		$cdrArr = $voipms->lireAppelsComplets();
		$transactionsArr = $voipms->lireTransactions();

		// Chaque lecture qui a échoué est dite ; une seule suffit à ne pas faire de rapport
		$extractionEnErreur = false;
		$lectures = [
			[_("Retour de getDidsInfo: "), $didsInfoArr],
			[_("Retour de getCdrComplet: "), $cdrArr],
			[_("Retour de getTransactionHistory: "), $transactionsArr],
		];
		foreach ($lectures as [$libelle, $lecture]) {
			if (is_string($lecture)) {
				$corps->dire($libelle.$lecture);
				$extractionEnErreur = true;
			}
		}
		if ($extractionEnErreur) {
			if ($sendEmail) {
				$emailmanager->sendemail($sujetEnErreur);
			}
			return $this->resultat(false, $username, $emailmanager, null, $typeCompte);
		}

		$didcostmanager = new CoutsDid($userinformations, $didsInfoArr, $cdrArr, $transactionsArr);
		$report = $didcostmanager->buildReport();
		$this->writeCsv($iduser, $dateFrom, $dateTo, $report, $logmanager);

		if ($seulementLeDidDuComite) {
			$this->appendDidDuComiteToEmailBody($corps, $report, $userinformations->didnumber);
		} else {
			$this->appendReportToEmailBody($corps, $report);
			$corps->direSolde($voipms->lireSolde());
		}

		$corps->ajouter("\n"._("Passez une bonne journée !"));
		if ($sendEmail) {
			$emailmanager->sendemail(_("Coûts détaillés par DID du ").$dateFrom._(" au ").$dateTo._(" pour l'utilisateur: ").$username);
		}
		return $this->resultat(true, $username, $emailmanager, $report, $typeCompte);
	}

	private function resultat(bool $success, string $username, Courriel $emailmanager, ?array $report, string $typeCompte): array {
		return [
			'success' => $success,
			'username' => $username,
			'emailmanager' => $emailmanager,
			'report' => $report,
			'typeCompte' => $typeCompte,
		];
	}

	private function writeCsv(int $iduser, string $dateFrom, string $dateTo, array $report, ?JournalDeTache $logmanager): void {
		$dateFromFich = str_replace('-', '', $dateFrom);
		$dateToFich = str_replace('-', '', $dateTo);
		$nomFich = "didcostid{$iduser}F{$dateFromFich}T{$dateToFich}";
		$filePath = SRC_DIRECTORY."files/".$nomFich.".csv";

		$csvRows = [];
		foreach ($report['perDid'] as $did => $data):
			$csvRows[] = [
				'DID' => $did,
				'Description' => $data['description'],
				'NbAppels' => $data['calls'],
				'NbAppelsRepondus' => $data['answeredCalls'],
				'DureeMinutes' => round($data['seconds'] / 60, 2),
				'CoutAppels' => round($data['callCost'], 4),
				'FraisLocationDID' => round($data['rentalFees'], 4),
				'PartFraisPartages' => round($data['sharedCostShare'], 4),
				'CoutTotal' => round($data['totalCost'], 4),
			];
		endforeach;
		$csvRows[] = [
			'DID' => 'TOTAL',
			'Description' => '',
			'NbAppels' => array_sum(array_column($csvRows, 'NbAppels')),
			'NbAppelsRepondus' => array_sum(array_column($csvRows, 'NbAppelsRepondus')),
			'DureeMinutes' => round(array_sum(array_column($csvRows, 'DureeMinutes')), 2),
			'CoutAppels' => round(array_sum(array_column($csvRows, 'CoutAppels')), 4),
			'FraisLocationDID' => round(array_sum(array_column($csvRows, 'FraisLocationDID')), 4),
			'PartFraisPartages' => round(array_sum(array_column($csvRows, 'PartFraisPartages')), 4),
			'CoutTotal' => round($report['grandTotal'], 4),
		];

		try {
			$fileManager = new Fichiers($logmanager);
			$fileManager->createCsvFile($filePath, $csvRows);
		} catch (Exception $e) {
			$logmanager?->writelinetolog("Error: " . $e->getMessage());
		}
	}

	// Version du comité local membre : seulement son DID (avec sa part des frais
	// partagés), sans les autres DID ni les totaux du compte. Si son DID n'est pas dans le compte
	// (DID mal saisi ou vide), on le dit, sans jamais montrer le reste du compte.
	private function appendDidDuComiteToEmailBody(CorpsDuRapport $corps, array $report, string $didDuComite): void {
		if (!isset($report['perDid'][$didDuComite])) {
			$corps->ajouter("");
			$corps->dire(sprintf(_("Le DID du comité (%s) est introuvable dans le compte voip.ms : rapport de coûts non disponible."), $didDuComite));
			return;
		}
		$this->appendDidToEmailBody($corps, $didDuComite, $report['perDid'][$didDuComite]);
	}

	private function appendDidToEmailBody(CorpsDuRapport $corps, string $did, array $data): void {
		$corps->ajouter("");
		$corps->dire(_("DID ").$did.($data['description'] !== "" ? " (".$data['description'].")" : "").":");
		$corps->dire("\t"._("Nombre d'appels : ").$data['calls']._(" (répondus : ").$data['answeredCalls'].")");
		$corps->dire("\t"._("Coût des appels : ").number_format($data['callCost'],2)._(" $"));
		$corps->dire("\t"._("Frais de location du DID : ").number_format($data['rentalFees'],2)._(" $"));
		$corps->dire("\t"._("Part des frais non attribuables à un DID en particulier : ").number_format($data['sharedCostShare'],2)._(" $"));
		$corps->dire("\t"._("Coût total pour ce DID : ").number_format($data['totalCost'],2)._(" $"));
	}

	// Version complète : tous les DID du compte, le détail des frais partagés et le total du compte
	private function appendReportToEmailBody(CorpsDuRapport $corps, array $report): void {
		foreach ($report['perDid'] as $did => $data) {
			$this->appendDidToEmailBody($corps, (string)$did, $data);
		}

		$corps->ajouter("");
		$corps->dire(_("Détail des frais non attribuables à un DID en particulier (total : ").number_format($report['shared']['sharedTotal'],2)._(" $, réparti également entre ").$report['shared']['didCount']._(" DID, soit ").number_format($report['shared']['perDidShare'],2)._(" $ chacun) :"));
		$corps->dire("\t"._("Appels non identifiables à un DID précis : ").number_format($report['shared']['nonAttributedCdrCost'],2)._(" $"));
		foreach ($report['shared']['otherAccountFees'] as $fee) {
			$corps->dire("\t".$fee['type'].' - '.$fee['description'].' : '.number_format($fee['amount'],2)._(" $"));
		}

		$corps->ajouter("");
		$corps->dire(_("Coût total du compte pour la période : ").number_format($report['grandTotal'],2)._(" $"));
	}
}

?>
