<?php
/**
 * Le nom d'un utilisateur de SwitchBoard Chat, tel qu'on le lit dans le titre d'un événement de l'agenda.
 */
class NomMatrix {
	/**
	 * Format Matrix : @localpart:domaine, ou seulement @localpart (SwitchBoard Chat ajoute alors son propre serveur :
	 * @lita veut dire @lita:matrix.example.org). Le nom est déjà en minuscules et sans espaces autour.
	 * - ^@ : le début, puis un @ ;
	 * - [a-z0-9._=-]+ : le nom local (minuscules, chiffres, point, soulignement, égal, trait d'union) ;
	 * - (:[a-z0-9.-]+)? : le domaine, facultatif, après deux-points.
	 */
	public static function estValide(string $nom): bool {
		return preg_match('/^@[a-z0-9._=-]+(:[a-z0-9.-]+)?$/', $nom) === 1;
	}

	/**
	 * Le nom d'un titre d'événement : en minuscules, sans espaces avant ni après.
	 */
	public static function depuisTitre(string $titre): string {
		return trim(strtolower($titre));
	}
}
