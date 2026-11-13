<?php
/**
 * Les rappels par SwitchBoard Chat : à qui écrire, et ce qu'on lui dit. L'envoi, l'écran et le journal sont à
 * l'appelant (TraitementComite), comme pour RappelsSms. Le titre de chaque événement est le nom Matrix du répondant.
 */
class RappelsChat {
	/**
	 * @param DateTime $debut, $fin la plage du rappel (voir FenetreDeRappel)
	 * @return array{prefixe: string, destinataires: array, nomsInvalides: string[], lignes: string[]}
	 *         'prefixe' : le début du message ; 'destinataires' : la liste de [nom Matrix, « 9:00 et 17:00 »] ;
	 *         'nomsInvalides' : les titres qui ne sont pas un nom Matrix ; 'lignes' : ce qui va au journal et à l'écran
	 */
	public static function planifier(Agenda $agenda, string $mode, DateTime $debut, DateTime $fin): array {
		$destinataires = [];
		$nomsInvalides = [];
		$lignes = [];
		foreach ($agenda->evenements() as $evenement) {
			if ($evenement->debut < $debut || $evenement->debut > $fin) {
				continue;
			}
			$nom = NomMatrix::depuisTitre($evenement->titre);
			if (!NomMatrix::estValide($nom)) {
				$nomsInvalides[] = $evenement->titre;
				continue;
			}
			$lignes[] = _("Tour de ").$evenement->titre;
			$lignes[] = _("Début du shift : ").$evenement->debut->format('d-m-Y H:i');
			$lignes[] = _("Fin du shift : ").$evenement->fin->format('d-m-Y H:i');
			$destinataires[] = [$nom, $evenement->debut->format('H:i')._(" et ").$evenement->fin->format('H:i')];
		}
		return ['prefixe' => self::prefixe($mode), 'destinataires' => $destinataires, 'nomsInvalides' => $nomsInvalides, 'lignes' => $lignes];
	}

	private static function prefixe(string $mode): string {
		if ($mode == "daybefore") {
			return _("Vous êtes cédulé à répondre aux textos demain entre : ");
		}
		return _("Vous êtes cédulé à répondre aux textos dans la prochaine heure entre : ");
	}
}
