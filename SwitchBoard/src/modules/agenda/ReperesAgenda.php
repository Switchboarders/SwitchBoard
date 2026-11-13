<?php
/**
 * Les repères qu'on peut écrire dans la description d'un événement de l'agenda, sous la forme nom->valeur (issue #62) :
 * « faire1->n nbsec->30 ». Ajouter un repère : son nom dans REPERES, et la lecture de sa valeur dans valeurDuRepere().
 */
class ReperesAgenda {
	const REPERES = ['faire1', 'nbsec'];

	/**
	 * Sépare les repères du reste de la description d'un événement. Retourne :
	 * - 'reperes' : [nom => valeur] des repères reconnus et valides ;
	 * - 'texte' : la description sans aucun repère, où chercher le numéro de téléphone ;
	 * - 'avertissements' : un message par repère inconnu ou par valeur invalide.
	 * Tout ce qui a la forme nom->valeur est retiré du texte, même inconnu ou invalide, pour que
	 * ses chiffres ne se mêlent jamais au numéro de téléphone.
	 */
	public static function lire(string $description): array {
		$reperes = [];
		$avertissements = [];
		$texte = preg_replace_callback(
			// Le nom commence par une lettre et n'est pas collé au mot qui précède. La valeur s'arrête
			// au premier espace, à une virgule, un point-virgule, ou au HTML de Google (<br>, &nbsp;).
			'/(?<![\p{L}\p{N}])(\p{L}[\p{L}\p{N}]*)[ \t]*->[ \t]*([^\s\x{00A0},;<&]*)/u',
			function ($trouve) use (&$reperes, &$avertissements) {
				// « Jean -> 514-555-1234 » ou « nbsec-> 514-555-1234 » (valeur oubliée) : ce qui suit
				// la flèche est un numéro de téléphone, pas une valeur. On le laisse dans le texte.
				if (strlen(preg_replace('/\D/', '', $trouve[2])) >= 7) {
					$avertissements[] = sprintf(_("« %s » : repère sans valeur, ignoré"), $trouve[1]."->");
					return " ".$trouve[2];
				}
				$nom = mb_strtolower($trouve[1]);
				if (!in_array($nom, self::REPERES, true)) {
					$avertissements[] = sprintf(_("« %s » : repère inconnu, ignoré"), $trouve[0]);
					return " ";
				}
				$valeur = self::valeurDuRepere($nom, mb_strtolower($trouve[2]));
				if ($valeur === null) {
					$avertissements[] = sprintf(_("« %s » : valeur invalide, valeur par défaut utilisée"), $trouve[0]);
					return " ";
				}
				$reperes[$nom] = $valeur;
				return " ";
			},
			$description
		);
		if ($texte === null) {
			// Description qui n'est pas de l'UTF-8 valide : gardée telle quelle, sans repère
			$texte = $description;
		}
		return ['reperes' => $reperes, 'texte' => $texte, 'avertissements' => $avertissements];
	}

	/**
	 * Valeur d'un repère (déjà en minuscules), ou null si elle est invalide.
	 */
	private static function valeurDuRepere(string $nom, string $valeur): ?int {
		switch ($nom) {
			case 'faire1':
				if (in_array($valeur, ['o', 'oui', 'y', 'yes', '1'], true)) return 1;
				if (in_array($valeur, ['n', 'non', 'no', '0'], true)) return 0;
				return null;
			case 'nbsec':
				return self::dureeValide($valeur);
		}
		return null;
	}

	/**
	 * Durée de sonnerie acceptée par voip.ms (nombre entier de 1 à 60 s), ou null.
	 */
	public static function dureeValide($valeur): ?int {
		if (!ctype_digit((string)$valeur)) return null;
		$secondes = intval($valeur);
		if ($secondes < GroupeSonnerie::DUREE_SONNERIE_MIN || $secondes > GroupeSonnerie::DUREE_SONNERIE_MAX) return null;
		return $secondes;
	}
}
