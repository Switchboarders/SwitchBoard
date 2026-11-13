<?php

/**
 * Un fichier de journal dans logs/ : ouverture, rotation à 5 Mo, protection du dossier et écriture d'une ligne.
 * JournalDeTache s'appuie sur cette classe pour écrire, et garde ce qui lui est propre (lignes gardées en mémoire,
 * indentation, affichage dans une page, rognage).
 *
 * Un journal impossible à ouvrir ne doit jamais arrêter un cron : ce serait la fin des groupes de sonnerie de tous
 * les comités. Le journal n'est alors pas écrit, et la cause va dans le journal d'erreurs de PHP.
 *
 * Ne jamais y écrire l'URL d'un appel voip.ms (elle contient le mot de passe de l'API) ni un numéro de téléphone complet.
 */
class Journal {
	const TAILLE_MAXIMALE = 5 * 1024 * 1024;

	private string $chemin;
	private $fichier = null;

	/**
	 * $nom dit ce que le journal contient (« courriel », « ringgroupupdate »...). Le journal d'un comité porte aussi son
	 * identifiant et son nom ; le journal général ne donne ni l'un ni l'autre.
	 */
	public function __construct(string $nom, int $iduser = -1, string $nomUtilisateur = '') {
		$prefixe = $this->nettoyerNomDeFichier($nomUtilisateur);
		if ($iduser !== -1) {
			$prefixe .= "id".$iduser;
		}
		$this->chemin = DOSSIER_JOURNAUX.$prefixe.$nom."log.txt";
		$this->ouvrir();
	}

	public function getChemin(): string {
		return $this->chemin;
	}

	public function ecrire(string $ligne): void {
		if (is_resource($this->fichier)) {
			@fwrite($this->fichier, $ligne."\n");
		}
	}

	public function fermer(): void {
		if (is_resource($this->fichier)) {
			fclose($this->fichier);
		}
		$this->fichier = null;
	}

	private function ouvrir(): void {
		// Un cron peut créer le dossier avant toute page du portail : le .htaccess part avec lui
		(new Fichiers())->protegerDossierDuWeb(DOSSIER_JOURNAUX);
		$this->tournerSiTropGros();
		$fichier = @fopen($this->chemin, "a");
		if ($fichier === false) {
			error_log("Journal: journal impossible à ouvrir : ".(error_get_last()['message'] ?? $this->chemin));
			return;
		}
		$this->fichier = $fichier;
	}

	// Si le renommage échoue, on continue d'écrire dans le même fichier : un gros journal vaut mieux que pas de journal
	private function tournerSiTropGros(): void {
		if ((int)@filesize($this->chemin) <= self::TAILLE_MAXIMALE) {
			return;
		}
		$ancien = substr($this->chemin, 0, -strlen(".txt")).".old.txt";
		if (!@rename($this->chemin, $ancien)) {
			error_log("Journal: rotation impossible : ".(error_get_last()['message'] ?? $this->chemin));
		}
	}

	// Garde les lettres, les chiffres, les espaces et -_~,;[]().
	private function nettoyerNomDeFichier(string $nom): string {
		$nom = (string)mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $nom);
		return (string)mb_ereg_replace("([\.]{2,})", '', $nom);
	}
}
