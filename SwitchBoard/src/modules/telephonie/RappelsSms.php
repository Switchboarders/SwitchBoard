<?php
/**
 * Les rappels par SMS (« daybefore » : la veille ; « hourbefore » : une heure avant) : qui reçoit un texto, et lequel.
 * La classe décide seulement : l'envoi, l'écran et le journal sont à l'appelant (TraitementComite).
 *
 * Reçoit un rappel le répondant dont le titre porte une astérisque (la veille) ou un point d'exclamation (une heure
 * avant, sauf pour un quart qui dure toute la journée), et dont un quart commence dans la plage du rappel.
 */
class RappelsSms {
	/**
	 * @param DateTime $debut, $fin la plage du rappel (voir FenetreDeRappel)
	 * @return array{rappels: array, refus: string[]} 'rappels' : la liste de ['numero', 'nom', 'plages', 'message'] ;
	 *         'refus' : un message par numéro refusé dont le quart tombe dans la plage (au journal et à l'écran, jamais par courriel)
	 */
	public static function planifier(Agenda $agenda, string $mode, DateTime $debut, DateTime $fin): array {
		$numerosATexter = [];
		$quarts = [];
		$refus = [];
		foreach ($agenda->evenements() as $evenement) {
			$numero = NumeroTelephone::lireDans(ReperesAgenda::lire($evenement->description)['texte']);
			if (self::doitEtreTexte($mode, $evenement) && !in_array($numero->numero, $numerosATexter)) {
				$numerosATexter[] = $numero->numero;
			}
			if ($evenement->debut < $debut || $evenement->debut > $fin) {
				continue;
			}
			if (!$numero->estValide()) {
				$refus[] = $numero->messageDeRefus($evenement);
			}
			if ($numero->numero) {
				$quarts = self::ajouterLeQuart($quarts, $numero->numero, $evenement);
			}
		}

		$rappels = [];
		foreach ($quarts as [$numero, $titre, $plages]) {
			if (!in_array($numero, $numerosATexter)) {
				continue;
			}
			$rappels[] = ['numero' => $numero, 'nom' => self::nomAffichable($titre), 'plages' => $plages,
				'message' => self::message($mode, $titre, $plages, $debut)];
		}
		return ['rappels' => $rappels, 'refus' => $refus];
	}

	/**
	 * Seules les personnes dont le titre est marqué d'une astérisque sont textées la veille ; d'un point d'exclamation,
	 * une heure avant, à moins que le quart dure toute la journée (début et fin à la même heure).
	 */
	private static function doitEtreTexte(string $mode, EvenementAgenda $evenement): bool {
		if ($mode == "daybefore") {
			return strpos($evenement->titre, "*") !== false;
		}
		if ($mode == "hourbefore") {
			return $evenement->debut->format('H:i') != $evenement->fin->format('H:i') && strpos($evenement->titre, "!") !== false;
		}
		return false;
	}

	/**
	 * Un numéro n'a qu'un texto : ses plages s'ajoutent à celles qu'il a déjà, et c'est le titre du dernier quart qui sert.
	 * @return array la liste de [numéro, titre, plages]
	 */
	private static function ajouterLeQuart(array $quarts, string $numero, EvenementAgenda $evenement): array {
		$plage = $evenement->debut->format('H:i')._(" à ").$evenement->fin->format('H:i');
		foreach ($quarts as $cle => [$numeroConnu, , $plagesConnues]) {
			if ($numero == $numeroConnu) {
				$quarts[$cle] = [$numero, $evenement->titre, $plagesConnues.",".$plage];
				return $quarts;
			}
		}
		$toutelaJournee = $evenement->debut->format('H:i') == $evenement->fin->format('H:i');
		$quarts[] = [$numero, $evenement->titre, $toutelaJournee ? _("toute la journée") : $plage];
		return $quarts;
	}

	private static function message(string $mode, string $titre, string $plages, DateTime $debut): string {
		$nom = self::nomAffichable($titre);
		if ($mode == "daybefore") {
			return $nom._(" vous êtes cédulé demain le ").$debut->format("d")." : ".$plages;
		}
		return $nom._(" vous êtes cédulé dans la prochaine heure de ").$plages;
	}

	/**
	 * Le nom d'un répondant sans les marques qui servent à choisir qui est texté (*, !) ni « off-> ».
	 */
	public static function nomAffichable(string $titre): string {
		return str_ireplace(array("*", "!", "off->"), "", $titre);
	}
}
