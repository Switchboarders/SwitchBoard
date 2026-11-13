<?php
use ICal\ICal;

/**
 * Un agenda de garde lu dans un fichier ICS : le seul endroit qui appelle la librairie ICal (johngrogg/ics-parser).
 *
 * La fenêtre est celle de la librairie : un événement est lu si la date de son début, à minuit, tombe entre maintenant
 * moins $joursAvant jours et maintenant plus $joursApres jours (null : aucune limite de ce côté). Un long quart commencé
 * avant la fenêtre n'est donc pas lu, même s'il est encore en cours : les mainteneurs jugent que les quarts de plus de
 * 2 jours n'arrivent pas en vrai (2026-11-09).
 *
 * Les heures sont justes quel que soit le fuseau que l'agenda déclare (X-WR-TIMEZONE, sinon son VTIMEZONE), et elles
 * s'affichent à l'heure du Québec (FUSEAU_HORAIRE) : journal, écran, texte des SMS.
 *
 * Un fichier que la librairie ne sait pas lire lève son exception, qu'on laisse passer : l'appelant ne doit rien changer.
 */
class Agenda {
	private ICal $ical;
	private DateTimeZone $fuseauDeLAgenda;
	private DateTimeZone $fuseauDuQuebec;

	/**
	 * $repetitionsDepliees : un événement répété donne un événement par occurrence ; sinon, seulement le premier.
	 */
	public function __construct(string $fichier, ?int $joursAvant, ?int $joursApres, bool $repetitionsDepliees) {
		$this->ical = new ICal($fichier, [
			// Le fuseau des heures écrites sans fuseau
			'defaultTimeZone' => FUSEAU_HORAIRE,
			'filterDaysBefore' => $joursAvant,
			'filterDaysAfter' => $joursApres,
			'skipRecurrence' => !$repetitionsDepliees,
		]);
		$this->fuseauDeLAgenda = new DateTimeZone($this->ical->calendarTimeZone());
		$this->fuseauDuQuebec = new DateTimeZone(FUSEAU_HORAIRE);
	}

	/**
	 * Les événements, dans l'ordre du fichier : aucune source d'agenda ne garantit cet ordre. Un titre ou une
	 * description absents valent une chaîne vide.
	 *
	 * @return EvenementAgenda[]
	 */
	public function evenements(): array {
		return array_map(fn($evenement) => new EvenementAgenda(
			(string)$evenement->summary,
			(string)$evenement->description,
			$this->heure($evenement->dtstart_tz),
			$this->heure($evenement->dtend_tz),
		), $this->ical->events());
	}

	/**
	 * La librairie donne chaque heure dans le fuseau de l'agenda, sans dire lequel : on la relit dans ce fuseau, puis
	 * on la met à l'heure du Québec.
	 */
	private function heure(string $heureDeLaLibrairie): DateTime {
		$heure = DateTime::createFromFormat('!Ymd\THis', $heureDeLaLibrairie, $this->fuseauDeLAgenda);
		if ($heure === false) {
			throw new UnexpectedValueException(sprintf(_("Heure illisible dans l'agenda : %s"), $heureDeLaLibrairie));
		}
		return $heure->setTimezone($this->fuseauDuQuebec);
	}
}
