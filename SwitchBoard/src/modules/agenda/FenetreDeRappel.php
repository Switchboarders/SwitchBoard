<?php
/**
 * La plage de temps dont un rappel (SMS ou SwitchBoard Chat) parle : la journée de demain (« daybefore »), ou la
 * prochaine heure pleine (« hourbefore »). Les quarts qui commencent dans cette plage reçoivent le rappel.
 */
class FenetreDeRappel {
	/**
	 * @return DateTime[] [début, fin], la fin étant la dernière seconde de la plage
	 */
	public static function pour(string $mode, DateTime $maintenant): array {
		if ($mode == "daybefore") {
			$debut = (clone $maintenant)->modify('+1 day')->setTime(0, 0, 0);
			$fin = (clone $maintenant)->modify('+1 day')->setTime(23, 59, 59);
			return [$debut, $fin];
		}
		if ($mode == "hourbefore") {
			$debut = self::prochaineHeurePleine($maintenant);
			$fin = (clone $debut)->modify("+1 hour")->modify("-1 second");
			return [$debut, $fin];
		}
		throw new InvalidArgumentException("Mode de rappel inconnu : $mode");
	}

	/**
	 * L'heure pleine qui suit (ou la même, si on est pile à l'heure).
	 */
	public static function prochaineHeurePleine(DateTime $date): DateTime {
		$date = clone $date;
		$minutes = $date->format('i');
		if ($minutes > 0) {
			$date->modify("+1 hour");
			$date->modify('-'.$minutes.' minutes');
		}
		$date->setTime($date->format('H'), 0, 0);
		return $date;
	}
}
