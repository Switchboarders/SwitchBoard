<?php
/**
 * Ce qu'on retient d'un problème d'un comité : la date du dernier courriel réussi (au plus un par 24 heures) et le nombre
 * de passages en échec de suite. Règles : CONVENTIONS.md, section 6.
 *
 * Une panne de cette mémoire (BD, table absente) ne doit jamais empêcher le traitement d'un comité : DatabaseManager
 * attrape l'erreur SQL. On n'envoie alors rien, et on continue.
 */
class MemoireAlertes {
	const DELAI_ENTRE_COURRIELS = 24 * 3600;

	const AGENDA = 'agenda';
	const AGENDA_ADMIN = 'agendaadmin';
	const AGENDA_SBCHAT = 'agendasbchat';
	const AGENDA_SBCHAT_ADMIN = 'agendasbchatadmin';
	const NUMERO = 'numero';
	const NOM_SBCHAT = 'nomsbchat';
	const VOIPMS = 'voipms';
	const TOUS_EN_ECHEC = 'tousenechec';
	const PREFIXE_EXCEPTION = 'exception-';

	// Le compteur des passages où tous les comités sont en échec n'est celui d'aucun comité. Aucun iduser ne vaut 0.
	const AUCUN_COMITE = 0;

	private DatabaseManager $bd;

	public function __construct(DatabaseManager $bd) {
		$this->bd = $bd;
	}

	/**
	 * Vrai si ce problème de ce comité n'a envoyé aucun courriel réussi depuis 24 heures. Faux si la mémoire est illisible.
	 */
	public function peutPartir(int $iduser, string $probleme): bool {
		$alerte = $this->bd->lireAlerteComite($iduser, $probleme);
		if ($alerte === null) {
			return false;
		}
		if ($alerte['dernierenvoi'] === null) {
			return true;
		}
		return time() - strtotime($alerte['dernierenvoi']) >= self::DELAI_ENTRE_COURRIELS;
	}

	/**
	 * À appeler seulement après un envoi réussi : si l'envoi rate, le passage suivant réessaie.
	 */
	public function noterEnvoi(int $iduser, string $probleme): void {
		$this->bd->noterEnvoiAlerteComite($iduser, $probleme, date('Y-m-d H:i:s'));
	}

	/**
	 * Ajoute un passage en échec. Retourne le nombre de passages de suite, ou null si la mémoire est illisible.
	 */
	public function compterPassageEnEchec(int $iduser, string $probleme): ?int {
		return $this->bd->compterPassageAlerteComite($iduser, $probleme);
	}

	public function remettreAZero(int $iduser, string $probleme): void {
		$this->bd->remettreAlerteComiteAZero($iduser, $probleme);
	}
}
