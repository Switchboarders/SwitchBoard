<?php
require_once dirname(__FILE__, 2) . '/commun/lesconstantes.php';

use Github\Client;
use Github\AuthMethod;

class RepoManager
{
	// Codes HTTP par lesquels GitHub refuse un jeton (401 : révoqué, expiré, inconnu ; 403 : accès
	// refusé à ce jeton) : la demande est alors refaite sans jeton, ce qui suffit pour un dépôt public.
	// Pas le 404 : GitHub le donne aussi, avec un bon jeton, pour un dépôt sans « release ».
	private const REFUS_DU_JETON = [401, 403];

	private $client;
	private $token;  // Contain the token to download the repository.
	private $savePath;
	private bool $jetonRefuse = false;

	public function __construct($token) {
		$this->savePath = DOWNLOAD_DIRECTORY;
		$this->token = is_string($token) ? $token : '';
		$this->client = new \Github\Client();
		// Un jeton n'est nécessaire que pour un dépôt privé. Sans jeton, on interroge
		// GitHub de façon anonyme, ce qui suffit pour un dépôt public.
		if ($this->token !== '') {
			$this->client->authenticate($this->token, null, \Github\AuthMethod::ACCESS_TOKEN);
		}
	}

	/**
	 * Vrai si GitHub a refusé le jeton configuré : les demandes suivantes se font sans jeton.
	 */
	public function jetonRefuse(): bool {
		return $this->jetonRefuse;
	}

	/**
	 * Fait l'appel à l'API de GitHub ; si GitHub refuse le jeton, le refait une fois sans jeton.
	 * Un dépôt public répond alors normalement ; un dépôt privé redonne la même erreur.
	 */
	private function appelerGithub(callable $appel) {
		try {
			return $appel($this->client);
		} catch (\Github\Exception\RuntimeException $e) {
			if ($this->token === '' || !in_array($e->getCode(), self::REFUS_DU_JETON, true)) {
				throw $e;
			}
			try {
				$resultat = $appel(new \Github\Client());
			} catch (\Github\Exception\RuntimeException $sansJeton) {
				// Un 401 dit que le jeton ne vaut rien : on ne le renvoie plus. Un 403 peut venir d'ailleurs
				// (limite de requêtes) : le jeton reste, puisque la demande sans jeton échoue aussi.
				if ($e->getCode() === 401) {
					$this->passerSansJeton();
				}
				throw $sansJeton;
			}
			$this->passerSansJeton();
			return $resultat;
		}
	}

	private function passerSansJeton(): void {
		$this->token = '';
		$this->jetonRefuse = true;
		$this->client = new \Github\Client();
	}

	/**
	 * Télécharge l'archive du dépôt dans $destination, sans la faire passer par la mémoire. Par cURL parce que
	 * allow_url_fopen est coupé en ligne de commande sur certains serveurs. GitHub redirige vers
	 * codeload.github.com, et cURL n'y envoie pas l'en-tête Authorization. Un jeton refusé : un second essai sans jeton.
	 *
	 * @throws RuntimeException Si l'archive n'a pas pu être téléchargée au complet.
	 */
	public function telechargerArchive(string $owner, string $repository, string $reference, string $destination): void {
		[$reussi, $codeHttp, $erreur] = $this->telechargerArchiveUneFois($owner, $repository, $reference, $destination, $this->token);
		if (!$reussi && $this->token !== '' && in_array($codeHttp, self::REFUS_DU_JETON, true)) {
			[$reussi, , $erreur] = $this->telechargerArchiveUneFois($owner, $repository, $reference, $destination, '');
			if ($reussi || $codeHttp === 401) {
				$this->passerSansJeton();
			}
		}
		if (!$reussi) {
			throw new RuntimeException(sprintf(_("Téléchargement de l'archive impossible : %s"), $erreur));
		}
	}

	/**
	 * @return array{0:bool, 1:int, 2:string} réussi, code HTTP, message d'erreur de cURL
	 */
	private function telechargerArchiveUneFois(string $owner, string $repository, string $reference, string $destination, string $jeton): array {
		$fichier = @fopen($destination, 'wb');
		if ($fichier === false) {
			throw new RuntimeException(sprintf(_("Impossible d'écrire dans %s."), $destination));
		}
		$ch = curl_init("https://api.github.com/repos/{$owner}/{$repository}/zipball/{$reference}");
		curl_setopt_array($ch, [
			CURLOPT_FILE           => $fichier,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_FAILONERROR    => true,
			CURLOPT_CONNECTTIMEOUT => 30,
			CURLOPT_TIMEOUT        => 600,
			CURLOPT_USERAGENT      => 'SwitchBoard (https://github.com/Switchboarders/SwitchBoard)',
			CURLOPT_HTTPHEADER     => $jeton !== '' ? ['Authorization: token '.$jeton] : [],
		]);
		$reussi = curl_exec($ch);
		$codeHttp = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
		$erreur = curl_error($ch);
		fclose($fichier);
		return [(bool)$reussi, $codeHttp, $erreur];
	}

	/**
	 * Download a specific file from GitHub
	 * lit le fichier /src/updatejson/codeVersion.json depuis le repo et le sauvegarde dans /download
	 */
	public function downloadFile($owner, $repository, $filePath, $branch = 'stable') {
		try {
			// Get the file content from specified branch.
			$fileContent = $this->appelerGithub(fn($client) => $client->api('repo')->contents()->download($owner, $repository, $filePath, $branch));

			// Save the file
			$fileName = basename($filePath);
			$fullPath = $this->savePath . $fileName;

			file_put_contents($fullPath, $fileContent);

			return [
				'success' => true,
				'message' => "File downloaded successfully : {$fullPath}",
				'path' => $fullPath
				];
		}
		catch (Exception $e) {
			return [
				'success' => false,
				'message' => "Error while downloading file : " . $e->getMessage()
			];
		}
	}

	/**
	 * Retourne le tag et le SHA du commit de la dernière release GitHub publiée - une
	 * référence immuable, utilisée à la place d'un nom de branche (qui peut changer de
	 * contenu à tout moment) pour ancrer les téléchargements de mise à jour.
	 *
	 * @return array{tag:string, sha:string}|false False si le dépôt n'a aucune release.
	 */
	public function getLatestRelease($owner, $repository) {
		try {
			$release = $this->appelerGithub(fn($client) => $client->api('repo')->releases()->latest($owner, $repository));
			$tag = $release['tag_name'] ?? null;
			if (!$tag) {
				return false;
			}
			$ref = $this->appelerGithub(fn($client) => $client->api('gitData')->references()->show($owner, $repository, 'tags/'.$tag));
			$objet = $ref['object'] ?? null;
			if (!$objet || empty($objet['sha'])) {
				return false;
			}
			$sha = $objet['sha'];
			if (($objet['type'] ?? '') === 'tag') {
				// Tag annoté : il pointe vers un objet tag, pas directement vers le commit ;
				// il faut le déréférencer pour obtenir le SHA du commit réel.
				$tagObjet = $this->appelerGithub(fn($client) => $client->api('gitData')->tags()->show($owner, $repository, $sha));
				$sha = $tagObjet['object']['sha'] ?? $sha;
			}
			return ['tag' => $tag, 'sha' => $sha];
		}
		catch (Exception $e) {
			return false;
		}
	}
}
