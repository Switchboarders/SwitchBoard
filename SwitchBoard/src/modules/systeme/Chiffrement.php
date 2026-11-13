<?php

/**
 * Chiffre/déchiffre les secrets stockés en BD (mot de passe API voip.ms, jeton SBChat,
 * identifiants MAS) avec AES-256-GCM (ext-openssl, presque toujours disponible, y compris
 * en hébergement partagé/cPanel, contrairement à ext-sodium qui n'est pas garantie).
 *
 * La clé provient de la variable d'environnement APP_ENCRYPTION_KEY, ou sinon de
 * $appencryptionkey dans config/config.php (même convention que le jeton GitHub).
 *
 * Tant qu'aucune clé n'est configurée, encrypt() laisse la valeur en clair (pour ne pas
 * casser les déploiements existants) et decrypt() reconnaît et retourne intact tout texte
 * en clair déjà présent (préfixe "enc:v1:" absent) : la migration se fait donc de façon
 * paresseuse, valeur par valeur, à la prochaine sauvegarde.
 */
class Chiffrement {
	const PREFIXE = 'enc:v1:';
	const ALGO = 'aes-256-gcm';
	private ?string $cle;

	public function __construct() {
		$this->cle = $this->lireCle();
	}

	private function lireCle(): ?string {
		$b64 = getenv('APP_ENCRYPTION_KEY');
		if (!is_string($b64) || $b64 === '') {
			$b64 = '';
			$fichierConfig = CONFIG_DIRECTORY.'config.php';
			if (is_readable($fichierConfig)) {
				// Inclus dans la portée de la fonction pour ne pas polluer les variables globales
				include $fichierConfig;
				if (isset($appencryptionkey) && is_string($appencryptionkey)) {
					$b64 = $appencryptionkey;
				}
			}
		}
		if ($b64 === '') {
			return null;
		}
		$cle = base64_decode($b64, true);
		if ($cle === false || strlen($cle) !== 32) {
			// Clé mal formée : on refuse de chiffrer/déchiffrer plutôt que de continuer
			// avec une clé tronquée ou aléatoire.
			error_log("Chiffrement: APP_ENCRYPTION_KEY/\$appencryptionkey doit être 32 octets encodés en base64 (générer avec : php -r \"echo base64_encode(random_bytes(32));\")");
			return null;
		}
		return $cle;
	}

	public function isConfigured(): bool {
		return $this->cle !== null;
	}

	public function isEncrypted(?string $valeur): bool {
		return $valeur !== null && str_starts_with($valeur, self::PREFIXE);
	}

	/**
	 * Chiffre $texteClair. Si aucune clé n'est configurée, retourne la valeur telle
	 * quelle (déploiement pas encore migré). Ne rechiffre jamais une valeur déjà chiffrée.
	 */
	public function encrypt(?string $texteClair): ?string {
		if ($texteClair === null || $texteClair === '') {
			return $texteClair;
		}
		if ($this->isEncrypted($texteClair)) {
			return $texteClair;
		}
		if (!$this->isConfigured()) {
			return $texteClair;
		}
		$iv = random_bytes(openssl_cipher_iv_length(self::ALGO));
		$tag = '';
		$chiffre = openssl_encrypt($texteClair, self::ALGO, $this->cle, OPENSSL_RAW_DATA, $iv, $tag);
		if ($chiffre === false) {
			error_log("Chiffrement: échec du chiffrement");
			return $texteClair;
		}
		return self::PREFIXE . base64_encode($iv . $tag . $chiffre);
	}

	/**
	 * Déchiffre $valeurStockee. Une valeur sans le préfixe "enc:v1:" est considérée comme
	 * du texte en clair historique et retournée intacte.
	 */
	public function decrypt(?string $valeurStockee): ?string {
		if (!$this->isEncrypted($valeurStockee)) {
			return $valeurStockee;
		}
		if (!$this->isConfigured()) {
			die(_("Impossible de déchiffrer les secrets : la clé de chiffrement (APP_ENCRYPTION_KEY) n'est pas configurée."));
		}
		$brut = base64_decode(substr($valeurStockee, strlen(self::PREFIXE)), true);
		$ivLen = openssl_cipher_iv_length(self::ALGO);
		$tagLen = 16;
		if ($brut === false || strlen($brut) < $ivLen + $tagLen) {
			error_log("Chiffrement: valeur chiffrée corrompue");
			return null;
		}
		$iv = substr($brut, 0, $ivLen);
		$tag = substr($brut, $ivLen, $tagLen);
		$chiffre = substr($brut, $ivLen + $tagLen);
		$clair = openssl_decrypt($chiffre, self::ALGO, $this->cle, OPENSSL_RAW_DATA, $iv, $tag);
		if ($clair === false) {
			error_log("Chiffrement: échec du déchiffrement (mauvaise clé ?)");
			return null;
		}
		return $clair;
	}
}
