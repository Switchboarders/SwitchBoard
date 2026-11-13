<?php
/**
 * Ce qu'un rapport (statistiques d'appels, coûts par DID) dit : chaque ligne va au courriel, et au journal quand il y en a un.
 * Le courriel et le journal sont à l'appelant, qui décide de l'envoi.
 */
class CorpsDuRapport {
	private Courriel $courriel;
	private ?JournalDeTache $journal;

	public function __construct(Courriel $courriel, ?JournalDeTache $journal) {
		$this->courriel = $courriel;
		$this->journal = $journal;
	}

	/**
	 * Une ligne du courriel et du journal.
	 */
	public function dire(string $ligne): void {
		$this->journal?->writelinetolog($ligne);
		$this->courriel->addlinetomessagebody($ligne);
	}

	/**
	 * Une ligne du courriel seulement (politesses, que le journal n'a pas à répéter).
	 */
	public function ajouter(string $ligne): void {
		$this->courriel->addlinetomessagebody($ligne);
	}

	/**
	 * Le solde du compte voip.ms tel que ClientVoipms::lireSolde() le donne : une ligne par valeur, ou le message d'erreur.
	 * @param array|string $solde
	 */
	public function direSolde($solde): void {
		if (is_string($solde)) {
			$this->dire(_("Retour de getBalance: ").$solde);
			return;
		}
		foreach ($solde as $valeur) {
			$this->dire(_("Solde au compte voip.ms: ").$valeur);
		}
	}
}
