<?php
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';

/**
 * Verrou exclusif non bloquant basé sur flock(), utilisé pour détecter qu'une exécution
 * précédente d'un script (ex.: refreshallringgroups.php) est toujours en cours quand la
 * suivante démarre (chevauchement, ex.: 10 minutes ne suffisent pas si beaucoup de comités/
 * appels API lents). LOCK_EX | LOCK_NB ne bloque JAMAIS - acquerirSansAttendre() retourne
 * immédiatement true/false, jamais après une attente.
 *
 * Le verrou est TOUJOURS libéré par le noyau à la fin du processus qui le détient - même si ce
 * processus est tué (kill -9) ou plante - donc un run bloqué qui finit par être tué ne laisse
 * jamais les invocations suivantes bloquées indéfiniment, sans qu'on ait besoin de nettoyer le
 * fichier de verrou nous-mêmes.
 *
 * Non-blocking exclusive lock based on flock(), used to detect that a previous run of a script
 * (e.g. refreshallringgroups.php) is still in progress when the next one starts (overlap, e.g.
 * 10 minutes isn't enough if there are many comités/slow API calls). LOCK_EX | LOCK_NB never
 * blocks - acquerirSansAttendre() returns immediately true/false, never after waiting.
 *
 * The lock is ALWAYS released by the kernel when the holding process ends - even if that
 * process is killed (kill -9) or crashes - so a stuck run that eventually gets killed never
 * leaves subsequent invocations blocked forever, with no need to clean up the lock file
 * ourselves.
 */
class VerrouExclusif {
	const SONNERIE = 'refreshallringgroups';
	const MISE_A_JOUR = 'miseajour';

	private $handle = null;
	private string $chemin;

	public function __construct(string $nomVerrou) {
		$dossier = CONFIG_DIRECTORY.'locks/';
		if (!is_dir($dossier)) {
			@mkdir($dossier, 0700, true);
		}
		$this->chemin = $dossier.preg_replace('/[^a-zA-Z0-9_-]/', '_', $nomVerrou).'.lock';
	}

	/**
	 * Retourne true si le verrou est acquis (l'appelant doit alors traiter cette invocation
	 * normalement), false s'il est déjà détenu par une autre exécution en cours (chevauchement -
	 * l'appelant doit abandonner immédiatement cette invocation, PAS attendre).
	 *
	 * Returns true if the lock is acquired (the caller should process this invocation
	 * normally), false if it's already held by another still-running execution (overlap - the
	 * caller must abandon this invocation immediately, NOT wait).
	 */
	public function acquerirSansAttendre(): bool {
		$this->handle = @fopen($this->chemin, 'c');
		if ($this->handle === false) {
			error_log("VerrouExclusif: impossible d'ouvrir {$this->chemin}, chevauchement non détectable cette fois.");
			return true; // ne jamais bloquer l'exécution pour une erreur de permission locale
		}
		if (!flock($this->handle, LOCK_EX | LOCK_NB)) {
			fclose($this->handle);
			$this->handle = null;
			return false;
		}
		ftruncate($this->handle, 0);
		fwrite($this->handle, date('Y-m-d H:i:s')." pid=".getmypid()."\n"); // diagnostic seulement
		fflush($this->handle);
		return true;
	}

	/**
	 * Le verrou d'un comité : tenu pendant qu'on met à jour SON groupe de sonnerie, par le cron ou par la page
	 * « Forcer ». Il empêche deux mises à jour du même comité en même temps (deux séries de requêtes qui se
	 * croisent chez voip.ms). Les autres comités ne sont pas touchés.
	 */
	public static function pourComite(int $iduser): self {
		return new self('sonnerie-comite-'.$iduser);
	}

	public function liberer(): void {
		if ($this->handle !== null) {
			flock($this->handle, LOCK_UN);
			fclose($this->handle);
			$this->handle = null;
		}
	}

	/**
	 * Vrai si le fichier du verrou s'ouvre. acquerirSansAttendre() laisse passer ce cas pour ne pas bloquer
	 * un cron, mais une mise à jour doit le savoir : elle remplacerait le code sous un cron en marche.
	 */
	public function estUtilisable(): bool {
		$fichier = @fopen($this->chemin, 'c');
		if ($fichier === false) {
			return false;
		}
		fclose($fichier);
		return true;
	}

	/**
	 * Vrai si une mise à jour tourne. Elle tient ce verrou du début à la fin, et le verrou du cron de sonnerie
	 * pendant la migration et le remplacement du code : un cron peut ainsi distinguer ce cas d'un vrai
	 * chevauchement.
	 */
	public static function miseAJourEnCours(): bool {
		$verrou = new self(self::MISE_A_JOUR);
		if (!$verrou->acquerirSansAttendre()) {
			return true;
		}
		$verrou->liberer();
		return false;
	}
}
