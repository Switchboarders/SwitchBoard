<?php
/**
 * Le client de l'API voip.ms pour un comité : une méthode par appel de l'API, avec les reprises, les mesures de temps et
 * les erreurs au journal. Il ne sait rien du groupe de sonnerie (voir GroupeSonnerie), et n'écrit jamais à l'écran.
 *
 * Chaque méthode retourne ce que voip.ms a répondu (un tableau, ou la valeur de la clé attendue), ou un message d'erreur
 * (string) : erreur réseau, HTTP ou réponse illisible. Un statut d'erreur de voip.ms sous HTTP 200 (p. ex.
 * invalid_credentials) n'est pas une string : il est dans le tableau retourné, ou expliqué par dernierStatut().
 */
class ClientVoipms {
	// Au-delà de cette durée depuis le début du script (cron ou page), plus aucune reprise : lors
	// d'une panne prolongée de voip.ms, les reprises ne doivent pas allonger le passage du cron
	// au point de chevaucher le suivant (cron aux 10 ou 15 min selon le serveur).
	const LIMITE_REPRISES_SECONDES = 360;
	// Nombre maximal de requêtes pour une même opération (la première, plus les reprises)
	const TENTATIVES_MAX = 3;
	// Les messages d'erreur commencent par ce préfixe : le groupe de sonnerie et les journaux le reconnaissent
	const PREFIXE_ERREUR = 'voipmsmanager-';

	protected InformationsUtilisateur $comite;
	protected string $dateFrom;
	protected string $dateTo;
	protected int $nbRequetes = 0;
	// Mesures de temps des requêtes vers voip.ms, écrites au journal par ecrireMesuresAuJournal()
	protected array $lignesMesures = [];
	protected int $nbOperations = 0;
	protected float $secondesDansVoipms = 0.0;
	// Le statut de la dernière réponse de voip.ms qui en avait un (success, invalid_credentials...)
	protected ?string $dernierStatut = null;
	// Le journal de l'opération en cours (groupe de sonnerie, rappel) ; sans lui, le journal « voipms » du comité
	protected ?JournalDeTache $journalOperation = null;
	// Adresse de l'API voip.ms. Seuls les tests la remplacent (serveur factice, via une sous-classe).
	protected string $urlApi = "https://voip.ms/api/v1/rest.php";

	public function __construct(InformationsUtilisateur $comite, string $dateFrom = "", string $dateTo = "") {
		$this->comite = $comite;
		$this->dateFrom = $dateFrom;
		$this->dateTo = $dateTo;
	}

	/**
	 * Prépare un appel à l'API voip.ms. $params ne doit contenir que les paramètres propres
	 * à la méthode appelée (method, etc.) ; les identifiants sont ajoutés automatiquement ici.
	 *
	 * ATTENTION : l'API voip.ms n'accepte QUE des requêtes GET. Une requête POST vers
	 * rest.php est interprétée comme du SOAP et renvoie une erreur HTTP 500 ("Bad Request"),
	 * peu importe la méthode demandée - vérifié directement contre l'API. Ne pas "durcir" ceci
	 * en passant les identifiants dans un corps POST : ça casse entièrement l'intégration.
	 *
	 * Les identifiants se retrouvent donc dans l'URL, mais la requête est sortante et en
	 * HTTPS (la chaîne de requête est chiffrée en transit). Ne jamais journaliser ni afficher
	 * cette URL, qui contient le mot de passe API.
	 */
	private function preparerRequete(array $params) {
		$ch = curl_init();
		$donnees = array_merge([
			'api_username' => $this->comite->voipmsusername,
			'api_password' => $this->comite->voipmsapipassword,
		], $params);
		curl_setopt($ch, CURLOPT_URL, $this->urlApi . "?" . http_build_query($donnees));
		return $ch;
	}

	/**
	 * Une requête à une méthode de l'API, avec ses reprises. $cleAttendue : la clé de la réponse à retourner
	 * (null : toute la réponse).
	 */
	private function appeler(string $methode, array $params = [], ?string $cleAttendue = null) {
		return $this->appelerAPI($this->preparerRequete(['method' => $methode] + $params), $methode, $cleAttendue);
	}

	// --- Renvois d'appel ---

	/**
	 * Tous les renvois d'appel du compte voip.ms (le comité n'en a qu'une partie : voir GroupeSonnerie).
	 */
	public function lireRenvois() {
		return $this->appeler('getForwardings', [], 'forwardings');
	}

	/**
	 * Crée un renvoi d'appel, ou met à jour celui dont l'identifiant est fourni. Retourne la réponse complète.
	 */
	public function ecrireRenvoi(string $numero, string $idRenvoi = '') {
		$params = [
			'phone_number' => $numero,
			'callerid_override' => $this->comite->didnumber,
			'description' => $this->comite->ringgroupname,
		];
		if ($idRenvoi != "") {
			$params['forwarding'] = $idRenvoi;
		}
		return $this->appeler('setForwarding', $params);
	}

	public function effacerRenvoi(string $idRenvoi) {
		return $this->appeler('delForwarding', ['forwarding' => $idRenvoi]);
	}

	// --- Groupes de sonnerie et messageries vocales ---

	/**
	 * Tous les groupes de sonnerie du compte.
	 */
	public function lireGroupes() {
		return $this->appeler('getRingGroups', ['ring_group' => ''], 'ring_groups');
	}

	/**
	 * Met à jour un groupe de sonnerie, ou le crée si $idGroupe est null. $membres est la liste ordonnée de
	 * ['forwardingid' => ..., 'ringtime' => secondes, 'pressone' => 0 ou 1] ; voip.ms n'en accepte que 4.
	 * Retourne la réponse complète.
	 */
	public function ecrireGroupe($idGroupe, string $nom, array $membres, $boiteVocale) {
		// Chaque membre : fwd:<renvoi>,<durée de sonnerie>,<faire le 1 : 0 ou 1>
		$texteMembres = "";
		foreach (array_values($membres) as $i => $membre) {
			if ($i > 3) {
				break;
			}
			$texteMembres .= "fwd:" . $membre['forwardingid'] . "," . intval($membre['ringtime']) . "," . intval($membre['pressone']) . ";";
		}
		return $this->appeler('setRingGroup', [
			'ring_group' => $idGroupe,
			'name' => $nom,
			'language' => $this->comite->language,
			'members' => $texteMembres,
			'voicemail' => $boiteVocale,
		]);
	}

	/**
	 * Les boîtes vocales du compte.
	 */
	public function lireBoitesVocales() {
		return $this->appeler('getVoicemails', [], 'voicemails');
	}

	/**
	 * Les messages vocaux d'une boîte vocale (identifiée au DID du comité) pour la plage de dates fournie.
	 */
	public function lireMessagesVocaux($boite) {
		// "date_From" (F majuscule) préservé tel quel : c'est ce que l'API recevait déjà.
		return $this->appeler('getVoicemailMessages', [
			'date_From' => $this->dateFrom,
			'date_to' => $this->dateTo,
			'mailbox' => $boite,
		], 'messages');
	}

	// --- Rappels ---

	/**
	 * Envoie un SMS depuis le DID du comité. Retourne la réponse complète.
	 */
	public function envoyerSms(string $numeroDestination, string $message) {
		return $this->appeler('sendSMS', [
			'did' => $this->comite->didnumber,
			'dst' => $numeroDestination,
			'message' => $message,
		]);
	}

	// --- Compte, appels et coûts (rapports) ---

	/**
	 * L'adresse IP vue par voip.ms, pour la liste blanche de l'API.
	 */
	public function lireIp() {
		return $this->appeler('getIP', [], 'ip');
	}

	public function lireSolde() {
		return $this->appeler('getBalance', ['advanced' => ''], 'balance');
	}

	/**
	 * Le détail des appels (CDR) du DID du comité, ou les appels sortants de la ligne provinciale.
	 */
	public function lireAppels(bool $ligneProvinciale = false) {
		$sansReponse = $ligneProvinciale ? 0 : 1;
		return $this->appeler('getCDR', [
			'date_from' => $this->dateFrom,
			'date_to' => $this->dateTo,
			'answered' => 1,
			'noanswer' => $sansReponse,
			'busy' => $sansReponse,
			'failed' => $sansReponse,
			'timezone' => -5,
			'calltype' => $ligneProvinciale ? "outgoing" : $this->comite->didnumber,
		], 'cdr');
	}

	/**
	 * Le CDR complet du compte, sans filtre par DID : le rapport de coûts par DID regroupe lui-même les appels
	 * (voir CoutsDid) sans risquer d'en manquer.
	 */
	public function lireAppelsComplets() {
		return $this->appeler('getCDR', [
			'date_from' => $this->dateFrom,
			'date_to' => $this->dateTo,
			'answered' => 1,
			'noanswer' => 1,
			'busy' => 1,
			'failed' => 1,
			'timezone' => -5,
		], 'cdr');
	}

	/**
	 * Tous les DID du compte, avec leur description et leur frais de location mensuel : le rapport de coûts
	 * par DID n'en oublie ainsi aucun.
	 */
	public function lireDids() {
		return $this->appeler('getDIDsInfo', [], 'dids');
	}

	/**
	 * L'historique des transactions du compte pour la plage de dates : frais de location de DID, frais divers, recharges.
	 */
	public function lireTransactions() {
		return $this->appeler('getTransactionHistory', ['date_from' => $this->dateFrom, 'date_to' => $this->dateTo], 'transactions');
	}

	// --- Diagnostic ---

	/**
	 * Teste la connexion à l'API voip.ms avec les identifiants du comité (requête getLanguages, sans effet sur le
	 * compte) et retourne un message pour la page de diagnostic.
	 *
	 * voip.ms répond HTTP 200 même quand la connexion est refusée : l'échec n'apparaît que dans
	 * le champ "status" (ex.: missing_credentials, invalid_credentials, ip_not_enabled), que
	 * appelerAPI() retourne tel quel. Seul "success" veut donc dire que tout est correct.
	 */
	public function diagnostiquer(): string {
		$reponse = $this->appeler('getLanguages');

		// Erreur cURL, HTTP ou réponse illisible : appelerAPI() a déjà formulé le message
		if (!is_array($reponse)) {
			return $reponse;
		}

		$statut = $reponse['status'] ?? '';
		if ($statut === "success") {
			return _("La configuration est valide : voip.ms accepte les identifiants et l'adresse IP du serveur.");
		}
		return self::expliquerStatut($statut)
			?? sprintf(_("voip.ms a refusé la connexion (statut : %s)."), htmlspecialchars($statut));
	}

	/**
	 * Ce que veut dire, pour une personne, un statut qui refuse la connexion ; null pour tout autre statut.
	 */
	public static function expliquerStatut(string $statut): ?string {
		if ($statut === "missing_credentials") {
			return _("voip.ms n'a pas reçu de nom d'utilisateur ou de mot de passe API. Remplissez-les dans « Éditer informations utilisateur ».");
		}
		if ($statut === "invalid_credentials") {
			return _("voip.ms refuse le nom d'utilisateur ou le mot de passe API. Vérifiez-les dans « Éditer informations utilisateur » : le nom d'utilisateur est l'adresse courriel du compte voip.ms, et le mot de passe est celui de l'API (différent du mot de passe de connexion au site voip.ms).");
		}
		if ($statut === "ip_not_enabled") {
			return _("voip.ms refuse l'adresse IP de ce serveur. Ajoutez-la à la liste des adresses autorisées dans la section API du compte voip.ms (l'adresse du serveur est affichée sur la page principale de l'utilisateur).");
		}
		return null;
	}

	/**
	 * Le statut de la dernière réponse de voip.ms qui en avait un, ou null.
	 */
	public function dernierStatut(): ?string {
		return $this->dernierStatut;
	}

	// --- Reprises ---

	/**
	 * Décide si une requête vers voip.ms qui vient d'échouer peut être reprise, et après combien
	 * de secondes. Retourne null s'il ne faut pas la reprendre (succès, ou erreur qu'une nouvelle
	 * tentative ne réglerait pas).
	 *
	 * sendSMS n'est repris que si on est sûr que voip.ms n'a pas reçu la demande : sinon, le
	 * texto pourrait partir deux fois. Les autres méthodes peuvent être reprises sans danger
	 * (lectures, mises à jour, et setForwarding qui répond "used_number" si le renvoi existe déjà).
	 */
	private function delaiAvantReprise($ch, string $nomReqAPI, $result): ?int {
		$repriseToujoursSure = ($nomReqAPI !== "sendSMS");

		// Erreur cURL : la demande n'est jamais partie si la connexion n'a pas pu s'établir
		if ($result === false) {
			$demandeJamaisEnvoyee = in_array(curl_errno($ch), [CURLE_COULDNT_RESOLVE_HOST, CURLE_COULDNT_CONNECT], true)
				|| curl_getinfo($ch, CURLINFO_PRETRANSFER_TIME) == 0;
			return ($repriseToujoursSure || $demandeJamaisEnvoyee) ? 3 : null;
		}

		$codeHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		// 429 = débit limité : voip.ms a refusé la demande sans la traiter, pause plus longue
		if ($codeHttp === 429) {
			return 8;
		}
		// 521, 522, 523, 525 : Cloudflare n'a pas pu joindre voip.ms (ou la négociation TLS avec lui a échoué), la
		// demande ne lui est pas parvenue
		if (in_array($codeHttp, [521, 522, 523, 525], true)) {
			return 3;
		}
		// 403 : refus passager vu sur le serveur pilote (2026-11-06), qui disparaît à la reprise. Comme pour les pannes
		// ci-dessous, voip.ms a peut-être traité la demande : pas de reprise d'un SMS.
		// 502, 503, 504, 520, 524 : panne passagère, mais voip.ms a peut-être traité la demande
		if (in_array($codeHttp, [403, 502, 503, 504, 520, 524], true)) {
			return $repriseToujoursSure ? 3 : null;
		}
		// Succès, ou erreur durable (ex.: 500 pour une requête mal formée) : inutile de reprendre
		return null;
	}

	/**
	 * Vrai si le script tourne depuis plus de LIMITE_REPRISES_SECONDES. REQUEST_TIME_FLOAT est
	 * l'heure de démarrage du script, en ligne de commande comme par le web.
	 */
	private function limiteDeReprisesAtteinte(): bool {
		$debutScript = $_SERVER['REQUEST_TIME_FLOAT'] ?? microtime(true);
		return (microtime(true) - $debutScript) > self::LIMITE_REPRISES_SECONDES;
	}

	/**
	 * Envoie la requête, avec des reprises seulement pour les échecs transitoires (voir delaiAvantReprise()), jamais
	 * pour une réponse HTTP 200 avec un statut applicatif d'erreur (ex.: invalid_credentials) : réessayer ça ne
	 * changerait rien et ralentirait inutilement le traitement des autres comités.
	 * Retourne le texte de la réponse (false si cURL a échoué) et le nombre de tentatives faites.
	 */
	private function envoyerAvecReprises($ch, string $nomReqAPI): array {
		$resultat = false;
		$resultatRequete = "";
		for ($tentative = 1; $tentative <= self::TENTATIVES_MAX; $tentative++) {
			$debutRequete = microtime(true);
			$resultat = curl_exec($ch);
			$secondesRequete = microtime(true) - $debutRequete;
			$this->nbRequetes++;

			$resultatRequete = ($resultat === false)
				? "erreur cURL : " . curl_error($ch)
				: "HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE);
			$this->noterRequete($nomReqAPI, $tentative, $secondesRequete, $resultatRequete);

			$delai = $this->delaiAvantReprise($ch, $nomReqAPI, $resultat);
			if ($delai !== null && $tentative < self::TENTATIVES_MAX && $this->limiteDeReprisesAtteinte()) {
				$this->lignesMesures[] = "Reprise annulée | $nomReqAPI | le passage dure depuis plus de "
					. (self::LIMITE_REPRISES_SECONDES / 60) . " min";
				$delai = null;
			}
			if ($delai === null || $tentative >= self::TENTATIVES_MAX) {
				break;
			}
			sleep($delai);
		}
		return [$resultat, $tentative, $resultatRequete];
	}

	/**
	 * Fonction unique qui appelle les méthodes de voip.ms et gère les messages d'erreur retournés.
	 */
	private function appelerAPI($ch, string $nomReqAPI, ?string $cleAttendue = null) {
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout total de 30 secondes
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // 10 sec pour établir la connexion

		$debutOperation = microtime(true);
		[$resultat, $nbTentatives, $resultatRequete] = $this->envoyerAvecReprises($ch, $nomReqAPI);
		$info = ($resultat === false) ? [] : curl_getinfo($ch);

		// Erreur cURL brute (ex.: timeout), après épuisement des tentatives s'il y a lieu
		if ($resultat === false) {
			$this->noterOperation($nomReqAPI, $nbTentatives, $debutOperation, false, $resultatRequete);
			return $this->refuser($nomReqAPI, _("Erreur cURL : ") . curl_error($ch));
		}

		$this->noterOperation($nomReqAPI, $nbTentatives, $debutOperation, ($info['http_code'] ?? null) === 200, $resultatRequete);

		// Erreur HTTP (code différent de 200), après épuisement des tentatives s'il y a lieu
		if (!isset($info['http_code']) || $info['http_code'] !== 200) {
			return $this->refuser($nomReqAPI, _("Erreur HTTP : ") . ($info['http_code'] ?? 'inconnue'));
		}

		$donnees = json_decode($resultat, true);
		if (!is_array($donnees)) {
			return $this->refuser($nomReqAPI, _("Erreur : réponse JSON invalide ou vide"));
		}
		if (!isset($donnees['status'])) {
			return $this->refuser($nomReqAPI, _("Erreur: mauvais retour de voip.ms"));
		}
		$this->dernierStatut = (string)$donnees['status'];
		if ($donnees['status'] == "failure") {
			return $this->refuser($nomReqAPI, _("Erreur: ") . $donnees['status']);
		}

		// Si aucune clé précise n’est demandée, on retourne tout le tableau
		if ($cleAttendue === null) {
			if ($this->estStatutDeRefus((string)$donnees['status'])) {
				$this->noterErreur(self::PREFIXE_ERREUR."$nomReqAPI "._("statut : ").$donnees['status']);
			}
			return $donnees;
		}

		if (!array_key_exists($cleAttendue, $donnees)) {
			// voip.ms utilise des statuts "no_xxx" (ex.: no_cdr, no_did, no_forwarding) pour
			// signaler un résultat vide (ex.: aucun appel ce mois-ci) plutôt qu'une erreur -
			// ce n'est pas un échec, on retourne un tableau vide pour que l'appelant traite
			// ça comme "zéro résultat" plutôt que de faire échouer tout le traitement du compte.
			if (str_starts_with((string)$donnees['status'], 'no_')) {
				return [];
			}
			// Le statut dit le vrai problème, p. ex. invalid_credentials
			return $this->refuser($nomReqAPI, _("Erreur : clé attendue manquante dans la réponse (").$cleAttendue.") "._("statut : ").$donnees['status']);
		}

		return $donnees[$cleAttendue];
	}

	/**
	 * Écrit l'erreur au journal et la retourne, avec le préfixe qui la fait reconnaître.
	 */
	private function refuser(string $nomReqAPI, string $detail): string {
		$message = self::PREFIXE_ERREUR."$nomReqAPI $detail";
		$this->noterErreur($message);
		return $message;
	}

	/**
	 * Vrai pour un statut d'une réponse HTTP 200 qui vaut la peine d'être au journal. Ne le sont pas : le succès, un
	 * résultat vide (no_xxx), un renvoi qui existe déjà (used_number) et un groupe vide (missing_members) : si des
	 * répondants attendaient, GroupeSonnerie le dit.
	 */
	private function estStatutDeRefus(string $statut): bool {
		return !in_array($statut, ['success', 'used_number', 'missing_members'], true) && !str_starts_with($statut, 'no_');
	}

	// --- Journal et mesures ---

	/**
	 * Les erreurs de voip.ms vont dans ce journal plutôt que dans celui du comité (voir $journalOperation).
	 */
	public function journaliserDans(JournalDeTache $journal): void {
		$this->journalOperation = $journal;
	}

	// Jamais l'URL de l'appel : elle contient le mot de passe de l'API.
	public function noterErreur(string $message): void {
		$ligne = date('d-m-Y H:i:s')." : ".$message;
		if ($this->journalOperation !== null) {
			$this->journalOperation->writelinetolog($ligne);
			return;
		}
		(new Journal("voipms", intval($this->comite->iduser), (string)$this->comite->username))->ecrire($ligne);
	}

	/**
	 * Le nombre de requêtes faites vers voip.ms pour ce comité.
	 */
	public function nbRequetes(): int {
		return $this->nbRequetes;
	}

	/**
	 * Mémorise la durée d'une requête (une tentative) vers voip.ms.
	 */
	private function noterRequete(string $nomReqAPI, int $tentative, float $secondes, string $resultat): void {
		$this->secondesDansVoipms += $secondes;
		$this->lignesMesures[] = "Requête voip.ms | $nomReqAPI | tentative $tentative/".self::TENTATIVES_MAX." | "
			. $this->formaterSecondes($secondes) . " | $resultat";
	}

	/**
	 * Mémorise le bilan d'une opération, c'est-à-dire un appel à une méthode de l'API voip.ms avec
	 * ses reprises éventuelles. Le bilan n'est écrit que s'il y a eu plus d'une requête : avec une
	 * seule, il répéterait la ligne de la requête.
	 */
	private function noterOperation(string $nomReqAPI, int $nbRequetes, float $debutOperation, bool $reussi, string $resultatFinal): void {
		$this->nbOperations++;
		if ($nbRequetes > 1) {
			$secondes = microtime(true) - $debutOperation;
			$issue = $reussi ? "succès" : "échec : $resultatFinal";
			$this->lignesMesures[] = "Opération voip.ms | $nomReqAPI | $nbRequetes requêtes | "
				. $this->formaterSecondes($secondes) . " au total | $issue";
		}
	}

	/**
	 * Écrit au journal les mesures de temps accumulées par cet objet (une ligne par requête vers
	 * voip.ms, un bilan par opération répétée), suivies du résumé du comité.
	 * $secondesTotalComite est le temps de traitement complet du comité, mesuré par le script appelant.
	 */
	public function ecrireMesuresAuJournal(JournalDeTache $journal, float $secondesTotalComite): void {
		foreach ($this->lignesMesures as $ligne) {
			$journal->writelinetolog($ligne);
		}
		$journal->writelinetolog("Nbre de requêtes vers voip.ms pour ce comité= "
			. $this->nbRequetes . " (" . $this->nbOperations . " opérations)");
		$journal->writelinetolog("Temps dans voip.ms : " . $this->formaterSecondes($this->secondesDansVoipms)
			. " | Temps total du comité : " . $this->formaterSecondes($secondesTotalComite));
	}

	private function formaterSecondes(float $secondes): string {
		return number_format($secondes, 2, '.', '') . " s";
	}
}
