<?php
/**
 * Le numéro de téléphone d'un répondant, lu dans la description d'un événement de l'agenda, repères déjà retirés. Une
 * seule règle pour la page de validation, le groupe de sonnerie et les rappels SMS (décisions des mainteneurs du
 * 2026-11-09, tests/numeroagenda_test.php) :
 * - on prend le premier numéro bien formé de la description, où qu'il soit : 10 chiffres en groupes de 3, 3 et 4,
 *   avec ou sans 1 (ou +1) devant. La mise en forme est libre, parce qu'une faute de frappe ne doit pas retirer un
 *   répondant du groupe : entre deux groupes, jusqu'à 3 séparateurs (espace, point, tiret, barre oblique), et
 *   l'indicatif entre parenthèses, même s'il en manque une (« 514) 555-1234 », vu dans de vrais agendas) ;
 * - un numéro collé à d'autres chiffres n'est pas bien formé : « 25145551234 » n'est pas « 2514555123 » ;
 * - le numéro gardé n'a que ses 10 chiffres, sans le 1 initial, et ne commence jamais par 1.
 * Sans numéro bien formé, le refus est « invalide » si quelque chose ressemble à un numéro (au moins 7 chiffres de suite,
 * séparés ou non : la même limite que pour les repères de l'agenda), sinon « absent ».
 */
class NumeroTelephone {
	private const SEPARATEURS = '[\s.\-\/]{0,3}';
	private const BIEN_FORME = '/(?<!\d)(?:\+?1'.self::SEPARATEURS.')?\(?\d{3}\)?'.self::SEPARATEURS.'\d{3}'.self::SEPARATEURS.'\d{4}(?!\d)/';
	private const SUITE_DE_CHIFFRES = '/\+?\d[\d\s.\-\/()]*\d/';
	private const CHIFFRES_POUR_RESSEMBLER = 7;

	/**
	 * $numero : les 10 chiffres, ou null si le numéro est refusé. $lu : ce qui a été lu comme numéro, tel qu'écrit
	 * dans l'agenda ; vide si rien n'y ressemble.
	 */
	private function __construct(
		public readonly ?string $numero,
		public readonly string $lu,
	) {}

	public static function lireDans(string $description): self {
		// L'espace insécable (Outlook) sépare les chiffres comme une autre espace
		$texte = str_replace("\u{00A0}", ' ', $description);
		if (preg_match(self::BIEN_FORME, $texte, $trouve) === 1) {
			$chiffres = preg_replace('/\D/', '', $trouve[0]);
			if ($chiffres[0] === '1') {
				$chiffres = substr($chiffres, 1);
			}
			return new self(strlen($chiffres) === 10 && $chiffres[0] !== '1' ? $chiffres : null, $trouve[0]);
		}
		preg_match_all(self::SUITE_DE_CHIFFRES, $texte, $suites);
		foreach ($suites[0] as $suite) {
			if (strlen(preg_replace('/\D/', '', $suite)) >= self::CHIFFRES_POUR_RESSEMBLER) {
				return new self(null, $suite);
			}
		}
		return new self(null, '');
	}

	public function estValide(): bool {
		return $this->numero !== null;
	}

	/**
	 * Refusé parce que rien dans la description ne ressemble à un numéro.
	 */
	public function estAbsent(): bool {
		return $this->numero === null && $this->lu === '';
	}

	/**
	 * Le message de refus, le même pour la page de validation, le groupe de sonnerie et les rappels SMS. Il cite le
	 * titre et ce qui a été lu comme numéro, jamais la description entière (repères, HTML). L'heure de l'événement
	 * permet de le retrouver dans l'agenda quand le titre est vide.
	 */
	public function messageDeRefus(EvenementAgenda $evenement): string {
		$debut = $evenement->debut->format('d-m-Y H:i');
		$fin = $evenement->fin->format('d-m-Y H:i');
		if ($this->estAbsent()) {
			return sprintf(_("Numéro absent pour « %s » (événement du %s au %s)."), $evenement->titre, $debut, $fin);
		}
		return sprintf(_("Numéro invalide pour « %s » : « %s » (événement du %s au %s). Il faut 10 chiffres, avec ou sans 1 devant."),
			$evenement->titre, $this->lu, $debut, $fin);
	}
}
