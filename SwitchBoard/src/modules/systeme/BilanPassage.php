<?php
/**
 * Ce qui s'est passé pour chaque comité pendant un passage d'un cron, et les courriels à l'admin qui en découlent.
 * Règles : CONVENTIONS.md, section 6.
 *
 * Pendant la boucle, on ne fait que noter en mémoire : aucune BD, aucun courriel. Tout le reste vient après la boucle,
 * quand les groupes de sonnerie sont déjà traités : c'est seulement là qu'on sait si tous les comités ont échoué, et
 * une panne de la mémoire ou du courriel ne peut plus gêner aucun comité.
 */
class BilanPassage {
	// Un comité qui échoue depuis plus d'une heure, avec un passage aux 10 minutes
	const PASSAGES_AVANT_COURRIEL = 6;

	private string $cron;
	private array $reussites = [];
	private array $echecsVoipms = [];
	private array $exceptions = [];
	private array $passagesEnEchec = [];

	/**
	 * $cron : le nom du script, qui distingue les exceptions d'un même comité d'un cron à l'autre.
	 */
	public function __construct(string $cron) {
		$this->cron = $cron;
	}

	/**
	 * Le résultat du groupe de sonnerie d'un comité. $echec : la raison, ou null si le groupe n'est pas en échec.
	 */
	public function noterGroupe(int $iduser, ?string $echec): void {
		if ($echec === null) {
			$this->reussites[] = $iduser;
			return;
		}
		$this->echecsVoipms[$iduser] = $echec;
	}

	public function noterException(int $iduser, string $message): void {
		$this->exceptions[$iduser] = $message;
	}

	public function nombreExceptions(): int {
		return count($this->exceptions);
	}

	public function nombreEchecsVoipms(): int {
		return count($this->echecsVoipms);
	}

	public function nombreEnEchec(): int {
		return $this->nombreExceptions() + $this->nombreEchecsVoipms();
	}

	public function sontTousEnException(int $nombreTraites): bool {
		return $nombreTraites >= 1 && $this->nombreExceptions() === $nombreTraites;
	}

	public function sontTousEnEchec(int $nombreTraites): bool {
		return $nombreTraites >= 1 && $this->nombreEnEchec() === $nombreTraites;
	}

	/**
	 * Met à jour les compteurs de passages en échec. Un comité dont le groupe n'a pas été tenté (agenda illisible) ou
	 * qui a levé une exception garde son compteur : on ne sait rien de voip.ms pour lui.
	 * Retourne le nombre de passages de suite où tous les comités sont en échec, celui-ci compris (0 si ce passage
	 * n'en est pas un), ou null si la mémoire est illisible. Sans comité traité, aucun compteur ne bouge.
	 */
	public function mettreAJourCompteurs(MemoireAlertes $memoire, int $nombreTraites): ?int {
		foreach ($this->reussites as $iduser) {
			$memoire->remettreAZero($iduser, MemoireAlertes::VOIPMS);
		}
		foreach (array_keys($this->echecsVoipms) as $iduser) {
			$this->passagesEnEchec[$iduser] = $memoire->compterPassageEnEchec($iduser, MemoireAlertes::VOIPMS);
		}
		if ($nombreTraites === 0) {
			return 0;
		}
		if ($this->sontTousEnEchec($nombreTraites)) {
			return $memoire->compterPassageEnEchec(MemoireAlertes::AUCUN_COMITE, MemoireAlertes::TOUS_EN_ECHEC);
		}
		$memoire->remettreAZero(MemoireAlertes::AUCUN_COMITE, MemoireAlertes::TOUS_EN_ECHEC);
		return 0;
	}

	/**
	 * Envoie à l'admin ce qui peut partir. L'appelant règle la langue et décide : pas d'appel quand tous les comités
	 * échouent et qu'un signal de vie s'en charge. Retourne le nombre de courriels partis.
	 */
	public function envoyerCourriels(MemoireAlertes $memoire, Courriel $courriel): int {
		$envoyes = 0;
		foreach ($this->echecsVoipms as $iduser => $raison) {
			if (($this->passagesEnEchec[$iduser] ?? 0) < self::PASSAGES_AVANT_COURRIEL) {
				continue;
			}
			$lignes = [
				sprintf(_("Le groupe de sonnerie n'a pas pu être mis à jour depuis %d passages de suite du cron."), $this->passagesEnEchec[$iduser]),
				_("Dernière raison : ").$this->sansSecret($raison),
				_("Vérifiez le mot de passe de l'API et l'adresse IP autorisée dans le compte voip.ms. Le journal du comité donne le détail."),
				_("Ce courriel part au plus une fois par 24 heures, tant que le problème dure."),
			];
			$envoyes += (int)$this->envoyer($memoire, $courriel, $iduser, MemoireAlertes::VOIPMS, _("Groupe de sonnerie en échec"), $lignes);
		}
		foreach ($this->exceptions as $iduser => $message) {
			$lignes = [
				sprintf(_("Le cron %s a rencontré une erreur pour ce comité :"), $this->cron),
				$this->sansSecret($message),
				_("Ce courriel part au plus une fois par 24 heures, tant que le problème dure."),
			];
			$envoyes += (int)$this->envoyer($memoire, $courriel, $iduser, MemoireAlertes::PREFIXE_EXCEPTION.$this->cron, _("Erreur dans un cron"), $lignes);
		}
		return $envoyes;
	}

	private function envoyer(MemoireAlertes $memoire, Courriel $courriel, int $iduser, string $probleme, string $titre, array $lignes): bool {
		if (!$memoire->peutPartir($iduser, $probleme)) {
			return false;
		}
		foreach ($lignes as $ligne) {
			$courriel->addlinetomessagebody($ligne);
		}
		if (!$courriel->sendemailtoadmin($titre, $iduser)) {
			return false;
		}
		$memoire->noterEnvoi($iduser, $probleme);
		return true;
	}

	// Le message d'une exception peut citer une adresse, et celle d'un appel voip.ms contient le mot de passe de l'API
	private function sansSecret(string $message): string {
		return (string)preg_replace(['#[a-z][a-z0-9+.-]*://\S+#i', '/api_password=\S+/i'], '[...]', $message);
	}
}
