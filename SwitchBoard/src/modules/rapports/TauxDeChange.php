<?php
// Récupère le taux de change USD -> CAD depuis l'API Valet de la Banque du Canada (source
// officielle, gratuite, sans clé requise - https://www.bankofcanada.ca/valet/). Ne doit JAMAIS
// faire échouer l'appelant si cette source externe est indisponible ou change de format : un
// repli sur un taux par défaut (mis à jour manuellement de temps à autre dans le code) est
// toujours utilisé dans ce cas, plutôt que de faire échouer tout le rapport pour un simple taux
// de change.
class TauxDeChange {

	// Taux de repli si la Banque du Canada est injoignable - à mettre à jour à l'occasion (pas
	// besoin d'être exact au centième, seulement raisonnablement à jour).
	// Dernière mise à jour manuelle : 2026-07-29 (taux réel de ce jour-là, voir obtenirTauxUsdVersCad()).
	const TAUX_PAR_DEFAUT = 1.4083;

	const URL_BANQUE_CANADA = 'https://www.bankofcanada.ca/valet/observations/FXUSDCAD/json?recent=1';
	const DELAI_SECONDES = 8;

	/**
	 * @return array{taux: float, source: string, date: ?string}
	 */
	public function obtenirTauxUsdVersCad(): array {
		try {
			$ch = curl_init(self::URL_BANQUE_CANADA);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::DELAI_SECONDES);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::DELAI_SECONDES);
			// Certains hébergeurs/pare-feux bloquent les requêtes sortantes sans User-Agent -
			// curl (CLI) en envoie un par défaut, mais PHP-curl n'en envoie aucun sauf si
			// précisé explicitement ici.
			curl_setopt($ch, CURLOPT_USERAGENT, 'SwitchBoard/1.0 (+https://github.com/Switchboarders/SwitchBoard)');
			$reponse = curl_exec($ch);
			$codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$erreurCurl = curl_error($ch);
			unset($ch);

			if ($reponse === false || $erreurCurl !== '' || $codeHttp !== 200) {
				// Journalisé (pas juste ignoré) : sans ça, un blocage persistant de la source
				// externe (pare-feu sortant, blocage par l'hébergeur, panne de l'API elle-même)
				// ne laisse aucune trace exploitable pour comprendre pourquoi le taux par défaut
				// est utilisé à répétition.
				error_log("TauxDeChange: repli sur le taux par défaut - code HTTP=".$codeHttp.", erreur curl=".($erreurCurl !== '' ? $erreurCurl : '(aucune)'));
				return $this->tauxParDefaut();
			}

			$donnees = json_decode($reponse, true);
			$observations = $donnees['observations'] ?? null;
			if (!is_array($observations) || empty($observations)) {
				error_log("TauxDeChange: repli sur le taux par défaut - réponse sans 'observations' exploitable : ".substr((string)$reponse, 0, 300));
				return $this->tauxParDefaut();
			}
			$derniere = end($observations);
			$valeur = $derniere['FXUSDCAD']['v'] ?? null;
			$date = $derniere['d'] ?? null;

			if (!is_string($valeur) || !is_numeric($valeur) || $date === null) {
				error_log("TauxDeChange: repli sur le taux par défaut - format de la dernière observation inattendu : ".json_encode($derniere));
				return $this->tauxParDefaut();
			}

			return [
				'taux' => (float)$valeur,
				'source' => _("Banque du Canada (taux quotidien officiel)"),
				'date' => $date,
			];
		} catch (\Throwable $e) {
			// Ne jamais laisser une panne de source externe (réseau, format de réponse
			// changé...) faire échouer la génération du rapport pour un simple taux de change.
			error_log("TauxDeChange: repli sur le taux par défaut - exception : ".$e->getMessage());
			return $this->tauxParDefaut();
		}
	}

	private function tauxParDefaut(): array {
		return [
			'taux' => self::TAUX_PAR_DEFAUT,
			'source' => _("Valeur par défaut (source externe injoignable - taux figé dans le code, à mettre à jour au besoin)"),
			'date' => null,
		];
	}
}

?>
