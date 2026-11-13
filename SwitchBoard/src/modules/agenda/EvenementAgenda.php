<?php
/**
 * Un événement lu par Agenda. Le titre et la description valent une chaîne vide quand l'agenda ne les donne pas. Le
 * début et la fin sont à l'heure du Québec (FUSEAU_HORAIRE).
 */
class EvenementAgenda {
	public function __construct(
		public readonly string $titre,
		public readonly string $description,
		public readonly DateTime $debut,
		public readonly DateTime $fin,
	) {}
}
