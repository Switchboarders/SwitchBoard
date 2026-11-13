<?php

/**
 * Émet des pings vers un service externe de dead-man's-switch (healthchecks.io ou compatible -
 * voir reglagesDeadManSwitch() dans commun/surveillance.php) après que SwitchBoard ait lui-même
 * vérifié qu'un sous-système critique est en bonne santé. Volontairement générique (une URL
 * "succès" + une URL "échec" optionnelle par check nommé), pas lié à la convention d'URL d'un
 * fournisseur précis.
 *
 * Un check n'est jamais bloquant : un ping raté (panne réseau vers le service externe, URL mal
 * configurée) ne fait jamais planter l'appelant - ce serait strictement pire que ne pas
 * surveiller du tout.
 *
 * Emits pings to an external dead-man's-switch service (healthchecks.io or compatible - see
 * reglagesDeadManSwitch() in commun/surveillance.php) after SwitchBoard has itself verified that a
 * critical subsystem is healthy. Deliberately generic (one "success" URL + an optional "fail"
 * URL per named check), not tied to one provider's URL convention.
 *
 * A check is never blocking: a failed ping (network issue reaching the external service, a
 * misconfigured URL) never crashes the caller - that would be strictly worse than not
 * monitoring at all.
 */
class SignalDeVie {
	const TIMEOUT_SECONDES = 5;

	private string $check;
	private string $successUrl;
	private string $failUrl;
	private bool $termine = false;
	private bool $armee = false;

	public function __construct(string $check) {
		$this->check = $check;
		$reglages = reglagesDeadManSwitch($check);
		$this->successUrl = $reglages['success_url'];
		$this->failUrl = $reglages['fail_url'];
	}

	public function estConfigure(): bool {
		return $this->successUrl !== '';
	}

	/**
	 * Arme la détection automatique : si le script se termine (die(), erreur fatale, exception
	 * non rattrapée...) SANS que finirAvecSucces()/finirAvecEchec() n'ait été appelé avant, un
	 * ping d'échec générique part automatiquement à la fin du script. Complémentaire au délai
	 * de grâce du service externe (silence prolongé) : celui-ci reste la dernière ligne de
	 * défense si le conteneur/hôte est complètement figé ou éteint et qu'aucun shutdown
	 * function ne peut même s'exécuter.
	 *
	 * Arms automatic detection: if the script terminates (die(), fatal error, uncaught
	 * exception...) WITHOUT finirAvecSucces()/finirAvecEchec() having been called first, a
	 * generic failure ping goes out automatically at the end of the script. Complements the
	 * external service's own grace-period silence detection: that remains the last line of
	 * defense if the container/host is fully hung or powered off and no shutdown function can
	 * even run.
	 */
	public function armerDetectionEchec(): void {
		if (!$this->estConfigure() || $this->armee) {
			return;
		}
		$this->armee = true;
		register_shutdown_function(function() {
			if ($this->termine) {
				return;
			}
			$erreur = error_get_last();
			$fatales = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
			$raison = ($erreur !== null && in_array($erreur['type'], $fatales, true))
				? $erreur['message']." (".$erreur['file'].":".$erreur['line'].")"
				: "Le script s'est terminé sans envoyer le ping de succès (die()/exit() prématuré ou exception non interceptée) - voir cron.log pour le détail.";
			$this->pingEchec($raison);
		});
	}

	/** À appeler à la toute fin d'une exécution réussie. */
	public function finirAvecSucces(string $corps = ''): void {
		$this->termine = true; // désarme le repli générique de armerDetectionEchec()
		$this->pingSucces($corps);
	}

	/** À appeler pour un échec explicite et catégorisé (ex.: chevauchement détecté). */
	public function finirAvecEchec(string $raison): void {
		$this->termine = true; // désarme le repli générique : celui-ci est plus précis
		$this->pingEchec($raison);
	}

	public function pingSucces(string $corps = ''): void {
		if (!$this->estConfigure()) {
			return;
		}
		$this->envoyerPing($this->successUrl, $corps);
	}

	public function pingEchec(string $raison): void {
		if (!$this->estConfigure()) {
			return;
		}
		// Repli sur l'URL de succès + suffixe /fail (convention healthchecks.io) si aucune URL
		// d'échec dédiée n'est fournie - reste service-agnostique : pour un service qui ne suit
		// pas cette convention, ce ping échouera juste silencieusement (voir envoyerPing(),
		// jamais fatal) plutôt que de faire une hypothèse dangereuse.
		$url = $this->failUrl !== '' ? $this->failUrl : rtrim($this->successUrl, '/').'/fail';
		$this->envoyerPing($url, $raison);
	}

	private function envoyerPing(string $url, string $corps): void {
		try {
			$ch = curl_init($url);
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
			curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDES);
			curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SECONDES);
			if ($corps !== '') {
				curl_setopt($ch, CURLOPT_POST, true);
				curl_setopt($ch, CURLOPT_POSTFIELDS, substr($corps, 0, 10000));
			}
			curl_exec($ch);
			$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$erreurCurl = curl_error($ch);
			// Pas de curl_close() : sans effet depuis PHP 8.0 (le handle est libéré
			// automatiquement), et déprécié explicitement depuis PHP 8.5.
			if ($erreurCurl !== '' || $code < 200 || $code >= 300) {
				error_log("SignalDeVie({$this->check}): échec de ping (http=$code, $erreurCurl) - non fatal.");
			}
		} catch (\Throwable $e) {
			error_log("SignalDeVie({$this->check}): exception pendant le ping - ".$e->getMessage());
		}
	}
}
