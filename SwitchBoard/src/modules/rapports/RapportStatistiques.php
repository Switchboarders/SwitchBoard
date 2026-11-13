<?php
//Construit (et optionnellement envoie par courriel) les statistiques d'appels d'un seul
//utilisateur, pour une plage de dates donnée. Utilisé par le script CLI genstatistics.php ET par
//les pages web (un utilisateur consultant ses propres statistiques, un admin consultant/envoyant
//celles d'un autre utilisateur) : une seule logique, peu importe le déclencheur.
//Suppose que DatabaseManager, InformationsUtilisateur, Courriel, ClientVoipms et Fichiers sont
//déjà chargés par l'appelant (même convention que les autres classes du projet).
class RapportStatistiques {

	/**
	 * Construit les statistiques d'appels de $iduser pour la période [$dateFrom,$dateTo]. Écrit
	 * toujours le CSV du CDR sur disque (utile même en consultation web, pour archive). N'envoie
	 * le courriel que si $sendEmail est vrai (une simple consultation web n'a pas besoin d'envoyer
	 * un courriel à chaque clic).
	 *
	 * @return array{success:bool, username:string, emailmanager:Courriel, stats:?array, typeCompte:string}
	 */
	public function processUser(int $iduser, string $dateFrom, string $dateTo, bool $sendEmail = true, ?JournalDeTache $logmanager = null): array {
		$databasemanager = new DatabaseManager();
		$userinformations = new InformationsUtilisateur($iduser, true);
		$username = $databasemanager->getuserbyid($iduser)['username'];
		$typeCompte = $databasemanager->getTypeCompte($iduser);
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);
		$corps = new CorpsDuRapport($emailmanager, $logmanager);
		$sujet = _("Statistiques des appels du ").$dateFrom._(" au ").$dateTo._(" pour l'utilisateur: ").$username;

		// Un compte principal n'a pas de DID à lui : pas de statistiques d'appels, rien à envoyer.
		// Ses appels sont comptés DID par DID dans le rapport de coûts (RapportCoutsDid.php).
		if ($typeCompte === DatabaseManager::COMPTE_PRINCIPAL) {
			$corps->dire(_("Compte principal : pas de statistiques d'appels (voir le rapport de coûts par DID)."));
			return $this->resultat(false, $username, $emailmanager, null, $typeCompte);
		}

		$corps->ajouter(_("Bonjour ").$username.",\n");
		$corps->ajouter(_("Voici les statistiques pour la période mentionnée:"));

		// Sans identifiants, voip.ms ne renvoie qu'une erreur incompréhensible : le dire clairement
		if ($userinformations->voipmsusername === '') {
			$corps->dire(_("Aucun compte voip.ms configuré pour cet utilisateur."));
			if ($sendEmail) {
				$emailmanager->sendemail($sujet);
			}
			return $this->resultat(false, $username, $emailmanager, null, $typeCompte);
		}

		$voipms = new ClientVoipms($userinformations, $dateFrom, $dateTo);
		$stats = $this->direLesAppels($corps, $voipms, $iduser, $dateFrom, $dateTo, $userinformations->didnumber, $logmanager);

		// Le solde appartient à celui qui paie le compte voip.ms : jamais à un comité local membre
		if ($typeCompte === DatabaseManager::COMITE_AUTONOME) {
			$corps->direSolde($voipms->lireSolde());
		}
		$this->direLesMessagesVocaux($corps, $voipms, $userinformations->didnumber);

		$corps->ajouter("\n"._("Passez une bonne journée !"));
		$corps->ajouter("\n"._("Votre Comité de l'aide téléphonique provincial !"));
		if ($sendEmail) {
			$emailmanager->sendemail($sujet);
		}
		return $this->resultat($stats !== null, $username, $emailmanager, $stats, $typeCompte);
	}

	private function resultat(bool $success, string $username, Courriel $emailmanager, ?array $stats, string $typeCompte): array {
		return [
			'success' => $success,
			'username' => $username,
			'emailmanager' => $emailmanager,
			'stats' => $stats,
			'typeCompte' => $typeCompte,
		];
	}

	/**
	 * Lit les appels, en garde le CSV et dit les statistiques. Retourne les statistiques, ou null si voip.ms n'a pas donné les appels.
	 */
	private function direLesAppels(CorpsDuRapport $corps, ClientVoipms $voipms, int $iduser, string $dateFrom, string $dateTo, string $didDuComite, ?JournalDeTache $logmanager): ?array {
		$cdrArr = $voipms->lireAppels();
		if (is_string($cdrArr)) {
			$corps->dire(_("problème avec getCDR, impossible d'extraire les détails des appels !"));
			$corps->dire(_("Retour de getCdr: ").$cdrArr);
			return null;
		}
		sort($cdrArr);
		$this->writeCsv($iduser, $dateFrom, $dateTo, $cdrArr, $logmanager);
		$stats = $this->computeStats($cdrArr, $didDuComite);
		$this->appendStatsToEmailBody($corps, $stats);
		return $stats;
	}

	/**
	 * Dit le nombre de messages vocaux reçus dans la boîte du DID du comité.
	 */
	private function direLesMessagesVocaux(CorpsDuRapport $corps, ClientVoipms $voipms, string $boite): void {
		$corps->dire(_("Nom de mailbox recherché: ").$boite);
		$messages = $voipms->lireMessagesVocaux($boite);
		if (is_string($messages)) {
			$corps->dire(_("Retour de getVoiceMailMessages: ").$messages);
			return;
		}
		$corps->dire(_("Nombre de messages vocaux reçus : ").count($messages));
	}

	private function writeCsv(int $iduser, string $dateFrom, string $dateTo, array $cdrArr, ?JournalDeTache $logmanager): void {
		$dateFromFich = str_replace('-', '', $dateFrom);
		$dateToFich = str_replace('-', '', $dateTo);
		$nomFich = "cdrid{$iduser}F{$dateFromFich}T{$dateToFich}";
		$filePath = SRC_DIRECTORY."files/".$nomFich.".csv";
		try {
			$fileManager = new Fichiers($logmanager);
			$fileManager->createCsvFile($filePath, $cdrArr);
		} catch (Exception $e) {
			$logmanager?->writelinetolog("Error: " . $e->getMessage());
		}
	}

	private function computeStats(array $cdrArr, string $userDid): array {
		$s = [
			'ctrAppels' => 0, 'ctrAnswered' => 0, 'ctrNoAnswer' => 0, 'ctrBusy' => 0,
			'ctrFailed' => 0, 'ctrAutres' => 0, 'ctr30sec' => 0, 'ctr30sec1min' => 0,
			'ctr1min' => 0, 'ctrAppels2201' => 0, 'ctrAppels0106' => 0, 'ctrAppels0609' => 0,
			'ctrAppels0922' => 0,
		];
		foreach ($cdrArr as $entree):
			$haystack = strtolower($entree['description']);
			$cdrDID = $entree['destination'];
			if (str_contains($haystack, 'inbound') and $cdrDID == $userDid):
				$s['ctrAppels']++;
				if ($entree['disposition'] == 'ANSWERED'):
					$s['ctrAnswered']++;
					if ($entree['duration'] < "00:00:30"):
						$s['ctr30sec']++;
					elseif ($entree['duration'] < "00:01:00"):
						$s['ctr30sec1min']++;
					else:
						$s['ctr1min']++;
					endif;
				elseif ($entree['disposition'] == 'NO ANSWER'):
					$s['ctrNoAnswer']++;
				elseif ($entree['disposition'] == 'BUSY'):
					$s['ctrBusy']++;
				elseif ($entree['disposition'] == 'FAILED'):
					$s['ctrFailed']++;
				else:
					$s['ctrAutres']++;
				endif;
				$heureAppel = substr($entree['date'], -8);
				if ($heureAppel < '01:00:00' or $heureAppel >= '22:00:00'):
					$s['ctrAppels2201']++;
				elseif ($heureAppel < '06:00:00'):
					$s['ctrAppels0106']++;
				elseif ($heureAppel < '09:00:00'):
					$s['ctrAppels0609']++;
				else:
					$s['ctrAppels0922']++;
				endif;
			endif;
		endforeach;
		return $s;
	}

	private function appendStatsToEmailBody(CorpsDuRapport $corps, array $s): void {
		$lines = [
			_("Nombre d'appels reçus : ").$s['ctrAppels'],
			"\t"._("Nombre d'appels entre 22:00:00 et 01:00:00 : ").$s['ctrAppels2201'],
			"\t"._("Nombre d'appels entre 01:00:00 et 06:00:00 : ").$s['ctrAppels0106'],
			"\t"._("Nombre d'appels entre 06:00:00 et 09:00:00 : ").$s['ctrAppels0609'],
			"\t"._("Nombre d'appels entre 09:00:00 et 22:00:00 : ").$s['ctrAppels0922'],
			_("Nombre d'appels répondus : ").$s['ctrAnswered'],
			"\t"._("Nombre d'appels répondus < 30 sec: ").$s['ctr30sec'],
			"\t"._("Nombre d'appels répondus entre 30 sec et 1 min: ").$s['ctr30sec1min'],
			"\t"._("Nombre d'appels répondus > 1 min: ").$s['ctr1min'],
			_("Nombre d'appels non répondus : ").$s['ctrNoAnswer'],
			_("Nombre d'appels occupés : ").$s['ctrBusy'],
			_("Nombre d'appels échoués : ").$s['ctrFailed'],
			_("Nombre d'appels autres : ").$s['ctrAutres'],
		];
		foreach ($lines as $ligne) {
			$corps->dire($ligne);
		}
	}
}

?>
