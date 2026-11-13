<?php
/**
 * Le groupe de sonnerie d'un comité chez voip.ms : inscrit les répondants de l'agenda (un renvoi d'appel par répondant,
 * puis le groupe qui pointe vers ces renvois). Les appels à l'API passent tous par ClientVoipms.
 *
 * La classe n'écrit pas à l'écran : ce que la page « Forcer » et les crons affichent passe par $dire, que l'appelant fournit.
 */
class GroupeSonnerie {
	// Réglages d'un répondant dans le groupe de sonnerie quand ni l'agenda ni le comité n'en
	// donnent (issues #62 et #35). voip.ms accepte une durée de 1 à 60 s, et 0 ou 1 pour « Faire le 1 ».
	const DUREE_SONNERIE_DEFAUT = 25;
	const DUREE_SONNERIE_MIN = 1;
	const DUREE_SONNERIE_MAX = 60;
	const FAIRE_LE_1_DEFAUT = 1;

	protected ClientVoipms $client;
	protected InformationsUtilisateur $comite;
	protected DatabaseManager $bd;
	/** @var callable(string): void */
	protected $dire;
	// Numéros dont le renvoi n'a pas pu être créé au dernier mettreAJour()
	protected array $renvoisManquants = [];
	// Le dernier mettreAJour() : a-t-il eu lieu, et pourquoi le groupe n'a-t-il pas pu être mis à jour
	protected bool $aTente = false;
	protected ?string $echec = null;
	// Décrit le dernier mettreAJour(), pour le journal
	protected string $modePassage = "";

	/**
	 * $dire reçoit chaque message destiné à la personne qui regarde (page « Forcer », sortie du cron), sans rien y ajouter.
	 */
	public function __construct(ClientVoipms $client, InformationsUtilisateur $comite, DatabaseManager $bd, callable $dire) {
		$this->client = $client;
		$this->comite = $comite;
		$this->bd = $bd;
		$this->dire = $dire;
	}

	private function dire(string $message): void {
		($this->dire)($message);
	}

	/**
	 * Inscrit les répondants récupérés de l'agenda dans le groupe de sonnerie.
	 *
	 * $reglageslist donne, pour chaque répondant (même ordre que $phonenumberlist), sa durée de
	 * sonnerie et « Faire le 1 » : ['ringtime' => secondes, 'pressone' => 0 ou 1]. Un réglage
	 * absent prend la valeur par défaut (DUREE_SONNERIE_DEFAUT, FAIRE_LE_1_DEFAUT).
	 *
	 * La liste conservée (ce qui a été envoyé avec succès au passage précédent, issue #63) évite
	 * de tout renvoyer à voip.ms à chaque passage :
	 * - mêmes numéros dans le même ordre, avec les mêmes réglages : aucun appel à voip.ms ;
	 * - sinon : renvois créés seulement pour les nouveaux numéros, setRingGroup, et seulement
	 *   ensuite effacement des renvois des partants (le groupe ne pointe jamais vers un renvoi
	 *   déjà effacé).
	 * Resynchronisation complète (tous les renvois du comité relus, remis à jour, et ceux qui ne
	 * servent plus effacés) : sans liste conservée, sur demande (bouton « Forcer »), si le compte
	 * voip.ms ou le nom du groupe a changé, une fois par jour après 3 h, ou si voip.ms refuse la
	 * liste conservée.
	 */
	public function mettreAJour($iduser, $participantslist, $phonenumberlist, array $reglageslist = [], bool $forcerResynchronisation = false) {
		if (count($participantslist) != count($phonenumberlist)) {
			$this->dire(_("Erreur, numéros de téléphones manquants ?"));
		}
		$repondants = [];
		$reglageslist = array_values($reglageslist);
		foreach (array_values($phonenumberlist) as $i => $numero) {
			$repondants[] = [
				'phonenumber' => (string)$numero,
				'ringtime' => intval($reglageslist[$i]['ringtime'] ?? self::DUREE_SONNERIE_DEFAUT),
				'pressone' => intval($reglageslist[$i]['pressone'] ?? self::FAIRE_LE_1_DEFAUT),
			];
		}
		$this->renvoisManquants = [];
		$this->aTente = true;
		$this->echec = null;

		$listeConservee = $this->bd->getListeConservee($iduser);
		$raisonResynchronisation = $this->raisonResynchronisationComplete($listeConservee, $forcerResynchronisation);

		if ($raisonResynchronisation === null) {
			if ($this->memesRepondants($listeConservee['membres'], $repondants)) {
				$this->modePassage = _("aucun changement, aucun appel à voip.ms");
				return _("aucun changement");
			}
			$this->modePassage = _("changement de répondants");
			$resultat = $this->envoyerGroupe($iduser, $repondants, $listeConservee['membres']);
			if ($resultat !== null) {
				return $resultat;
			}
			$raisonResynchronisation = _("voip.ms a refusé la liste conservée");
		}
		$this->modePassage = _("resynchronisation complète : ").$raisonResynchronisation;
		return $this->envoyerGroupe($iduser, $repondants, null);
	}

	/**
	 * Vrai si la liste conservée a les mêmes répondants, dans le même ordre, avec les mêmes
	 * réglages (durée de sonnerie, « Faire le 1 ») que ceux de l'agenda.
	 */
	private function memesRepondants(array $membresConserves, array $repondants): bool {
		$cle = fn($r) => $r['phonenumber'].",".intval($r['ringtime']).",".intval($r['pressone']);
		return array_map($cle, $membresConserves) === array_map($cle, $repondants);
	}

	/**
	 * Pourquoi il faut une resynchronisation complète, ou null si la liste conservée suffit.
	 */
	private function raisonResynchronisationComplete(?array $listeConservee, bool $forcerResynchronisation): ?string {
		if ($forcerResynchronisation) {
			return _("demandée");
		}
		if ($listeConservee === null) {
			return _("aucune liste conservée");
		}
		if ($listeConservee['voipmsusername'] !== $this->comite->voipmsusername
			|| $listeConservee['ringgroupname'] !== $this->comite->ringgroupname) {
			return _("compte voip.ms ou nom du groupe changé");
		}
		// Une fois par jour, au premier passage après 3 h : rattrape un changement fait à la main
		// dans voip.ms, que la liste conservée ne peut pas voir.
		$maintenant = new DateTime();
		$dernier3h = new DateTime("today 03:00");
		if ($maintenant < $dernier3h) {
			$dernier3h->modify("-1 day");
		}
		if (new DateTime($listeConservee['lastfullsync']) < $dernier3h) {
			return _("quotidienne");
		}
		return null;
	}

	/**
	 * Envoie le groupe de sonnerie à voip.ms. $repondants est la liste ordonnée de
	 * ['phonenumber', 'ringtime', 'pressone'] ; $membresConserves est la liste conservée, ou null
	 * pour une resynchronisation complète. Retourne le statut de setRingGroup (ou le message
	 * d'erreur), et null si voip.ms a refusé la liste conservée : l'appelant refait alors tout.
	 */
	private function envoyerGroupe($iduser, array $repondants, ?array $membresConserves): ?string {
		$complet = ($membresConserves === null);
		$this->renvoisManquants = [];

		// En resynchronisation complète, on relit les renvois que voip.ms a pour ce comité, pour les remettre
		// à jour plutôt que d'en créer de nouveaux.
		$renvoisDuComite = $complet ? $this->lireRenvoisDuComite() : null;
		$membres = $this->obtenirLesRenvois($repondants, $renvoisDuComite, $membresConserves);

		// Sans aucun renvoi, le groupe serait vide : voip.ms le refuserait, et on le refait au passage suivant. Si voip.ms
		// a refusé les identifiants ou l'adresse IP, c'est la vraie cause, et il faut la dire.
		if ($repondants !== [] && $membres === []) {
			$explication = ClientVoipms::expliquerStatut((string)$this->client->dernierStatut());
			return $this->noterEchecGroupe($explication ?? _("aucun renvoi d'appel n'a pu être créé, rien n'est envoyé"));
		}

		// Si une lecture échoue, le groupe est quand même envoyé (comme avant), mais la liste
		// n'est pas conservée : le passage suivant recommencera.
		[$boiteVocale, $lecturesReussies] = $this->lireBoiteVocale();
		$idGroupe = $this->trouverIdGroupe();
		if ($idGroupe === false) {
			// Sans identifiant, setRingGroup créerait un deuxième groupe du même nom : on
			// n'envoie rien, le passage suivant recommencera.
			return $this->noterEchecGroupe(_("lecture du groupe de sonnerie impossible, rien n'est envoyé"));
		}
		$statut = $this->ecrireGroupe($idGroupe, $membres, $boiteVocale);

		// Sans répondant, voip.ms refuse un groupe vide (missing_members) : c'est attendu. On le
		// conserve quand même, pour ne pas réessayer à chaque passage tant que personne n'est de garde.
		$groupeAccepte = ($statut === "success") || (empty($repondants) && $statut === "missing_members");
		if (!$groupeAccepte) {
			// Un statut de refus (pas une erreur réseau, dont le message commence par le préfixe de
			// ClientVoipms) sur une liste conservée : un renvoi conservé n'existe peut-être plus chez voip.ms.
			if (!$complet && !str_starts_with((string)$statut, ClientVoipms::PREFIXE_ERREUR)) {
				$this->dire(_("voip.ms a refusé la liste conservée : ").$statut."<br>\n");
				return null;
			}
			// Rien n'est effacé ni conservé : le passage suivant recommencera
			return $this->noterEchecGroupe($this->raisonDuRefus((string)$statut));
		}

		// Le groupe est accepté. Quand personne n'est de garde, voip.ms refuse pourtant le groupe vide : il garde alors
		// le dernier répondant, dont le renvoi est effacé ci-dessous (un membre mort), et un appel tombe sur la
		// messagerie vocale. C'est voulu. Sinon, le groupe pointe maintenant vers les bons renvois, et on peut
		// effacer ceux qui ne servent plus.
		$this->effacerLesRenvoisInutiles($iduser, $membres, $renvoisDuComite, $membresConserves);

		if (empty($this->renvoisManquants) && $lecturesReussies) {
			$this->bd->enregistrerListeConservee($iduser, $this->comite->voipmsusername, $this->comite->ringgroupname, $membres, $complet);
		}
		return $statut;
	}

	/**
	 * Pour chaque répondant, l'identifiant de son renvoi : celui de la liste conservée, celui que voip.ms a déjà (sur
	 * resynchronisation complète), ou un renvoi à créer. Un répondant dont le renvoi ne peut pas être créé n'est pas
	 * dans le résultat : il est dans $renvoisManquants.
	 * @return array la liste ordonnée de ['forwardingid', 'phonenumber', 'ringtime', 'pressone']
	 */
	private function obtenirLesRenvois(array $repondants, ?array $renvoisDuComite, ?array $membresConserves): array {
		$complet = ($membresConserves === null);
		$idsConnus = [];
		if ($complet) {
			foreach ($renvoisDuComite ?? [] as $renvoi) {
				$idsConnus[(string)$renvoi['phone_number']] = (string)$renvoi['forwarding'];
			}
		} else {
			foreach ($membresConserves as $membre) {
				$idsConnus[$membre['phonenumber']] = $membre['forwardingid'];
			}
		}

		$membres = [];
		$idsObtenus = [];
		foreach ($repondants as $repondant) {
			$numero = $repondant['phonenumber'];
			if (isset($idsObtenus[$numero])) {
				// Même numéro deux fois dans l'agenda : même renvoi
				$idRenvoi = $idsObtenus[$numero];
			} elseif (!$complet && isset($idsConnus[$numero])) {
				$idRenvoi = $idsConnus[$numero];
			} else {
				$idRenvoi = $this->ecrireRenvoi($numero, $idsConnus[$numero] ?? "");
			}
			if ($idRenvoi === false) {
				$this->dire(_("Erreur lors de l'ajout du forwarding pour ") . $numero . "<br>\n");
				$this->renvoisManquants[] = $numero;
				continue;
			}
			$idsObtenus[$numero] = (string)$idRenvoi;
			$membres[] = ['forwardingid' => (string)$idRenvoi] + $repondant;
		}
		return $membres;
	}

	/**
	 * La boîte vocale du comité (son DID, s'il en a une), et si la lecture a réussi.
	 * @return array [boîte vocale ou 0, lecture réussie]
	 */
	private function lireBoiteVocale(): array {
		$boitesVocales = $this->client->lireBoitesVocales();
		if (is_string($boitesVocales)) {
			// Le statut de voip.ms dit le vrai problème (identifiants refusés, adresse IP non autorisée...)
			$explication = ClientVoipms::expliquerStatut((string)$this->client->dernierStatut());
			$this->dire(sprintf(_("Impossible de lire les messageries vocales de voip.ms : %s"), $explication ?? $boitesVocales)."\n");
			return [0, false];
		}
		foreach ($boitesVocales as $boite) {
			if ($boite['mailbox'] == $this->comite->didnumber) {
				return [$this->comite->didnumber, true];
			}
		}
		return [0, true];
	}

	/**
	 * Efface les renvois qui ne servent plus. En resynchronisation complète ($membresConserves null), tous ceux du
	 * comité que le groupe n'utilise pas ; sinon, seulement ceux des partants, et seulement s'il y en a (une lecture de plus).
	 */
	private function effacerLesRenvoisInutiles($iduser, array $membres, ?array $renvoisDuComite, ?array $membresConserves): void {
		if ($membresConserves !== null) {
			$partants = array_diff(array_column($membresConserves, 'forwardingid'), array_column($membres, 'forwardingid'));
			$renvoisDuComite = empty($partants) ? null : $this->lireRenvoisDuComite();
			if ($renvoisDuComite !== null) {
				$renvoisDuComite = array_filter($renvoisDuComite, fn($renvoi) => in_array((string)$renvoi['forwarding'], $partants, true));
			}
		}
		if ($renvoisDuComite === null) {
			return;
		}
		// Les renvois du groupe, et ceux qu'un autre comité du même compte voip.ms utilise, restent
		$renvoisAGarder = array_merge(
			array_column($membres, 'forwardingid'),
			$this->bd->getRenvoisUtilisesAilleurs($iduser, $this->comite->voipmsusername)
		);
		$this->effacerRenvois($renvoisDuComite, $renvoisAGarder);
	}

	/**
	 * Ce qui est dit au comité et au journal quand voip.ms refuse le groupe.
	 */
	private function raisonDuRefus(string $statut): string {
		if ($statut === 'name_toolong') {
			return sprintf(_("voip.ms refuse le nom du groupe de sonnerie, qui est trop long : « %s »"), $this->comite->ringgroupname);
		}
		return $statut;
	}

	private function noterEchecGroupe(string $raison): string {
		$this->echec = $raison;
		$this->client->noterErreur(_("Groupe de sonnerie non mis à jour : ").$raison);
		return $raison;
	}

	// --- Ce que le cron et les pages demandent après une mise à jour ---

	/**
	 * Vrai si mettreAJour() a été appelée : un agenda illisible ne tente rien.
	 */
	public function aTente(): bool {
		return $this->aTente;
	}

	/**
	 * Pourquoi le dernier mettreAJour() n'a pas pu mettre le groupe à jour, ou null s'il n'est pas en
	 * échec. Ne sont pas des échecs : aucun changement, personne de garde (missing_members sans répondant) et un
	 * résultat partiel (groupe accepté, renvoi manquant pour un répondant : voir renvoisManquants()).
	 */
	public function echec(): ?string {
		return $this->echec;
	}

	/**
	 * Comment le dernier mettreAJour() s'est passé : aucun changement, changement
	 * de répondants, ou resynchronisation complète (avec sa raison).
	 */
	public function modePassage(): string {
		return $this->modePassage;
	}

	/**
	 * Numéros dont le renvoi n'a pas pu être créé au dernier mettreAJour() : ces
	 * répondants ne sont pas dans le groupe de sonnerie jusqu'au prochain passage.
	 */
	public function renvoisManquants(): array {
		return $this->renvoisManquants;
	}

	// --- Renvois d'appel du comité ---

	/**
	 * Renvois d'appel de ce comité chez voip.ms (description = nom du groupe de sonnerie).
	 * Retourne un tableau, vide s'il n'y en a aucun, ou null si la lecture a échoué.
	 */
	public function lireRenvoisDuComite(): ?array {
		$renvois = $this->client->lireRenvois();
		if (!is_array($renvois)) {
			return null;
		}
		return array_values(array_filter($renvois, fn($renvoi) =>
			$renvoi['description'] == $this->comite->ringgroupname || $renvoi['phone_number'] == $this->comite->ringgroupname));
	}

	/**
	 * Efface les renvois reçus, sauf ceux dont l'identifiant est dans $renvoisAGarder (renvois du
	 * groupe, ou qu'un autre comité du même compte voip.ms utilise).
	 */
	private function effacerRenvois(array $renvois, array $renvoisAGarder): void {
		$renvoisAGarder = array_map('strval', $renvoisAGarder);

		$nbEfface = 0;
		foreach ($renvois as $renvoi) {
			$idRenvoi = (string)$renvoi['forwarding'];
			$numero = $renvoi['phone_number'];
			if (in_array($idRenvoi, $renvoisAGarder, true)) {
				continue;
			}
			$resultat = $this->client->effacerRenvoi($idRenvoi);

			// voip.ms peut répondre HTTP 200 avec un statut d'erreur : seul "success" confirme
			// la suppression. Une erreur est affichée, mais la suppression des autres continue.
			if (is_string($resultat)) {
				$this->dire(_("Erreur lors de la suppression du forwarding: ").$numero.$resultat."<br>\n");
			} elseif (($resultat['status'] ?? '') !== "success") {
				$this->dire(_("Erreur lors de la suppression du forwarding: ").$numero." (".($resultat['status'] ?? '').")<br>\n");
			} else {
				$nbEfface++;
				$this->dire(_("Forwarding ").$numero._(" supprimé.<br>\n"));
			}
		}

		$this->dire(strval($nbEfface) . _(" entrées supprimées") . "<br>\n");
	}

	/**
	 * Crée le renvoi d'un numéro, ou met à jour celui dont l'identifiant est fourni. Retourne son identifiant, ou
	 * false si voip.ms ne l'a pas créé.
	 */
	private function ecrireRenvoi(string $numero, string $idRenvoi = "") {
		$resultat = $this->client->ecrireRenvoi($numero, $idRenvoi);

		// En cas d'erreur retournée par le client (string)
		if (!is_array($resultat)) {
			$this->dire(_("addForwarding: Erreur, result= ").$resultat."<br>\n");
			return false;
		}

		// Un renvoi existe déjà pour ce numéro : on le réutilise
		if (isset($resultat['status']) && $resultat['status'] == "used_number") {
			$this->dire(_("addForwarding: réutilisation de 'used_number'"). "<br>\n");
			return $this->trouverIdRenvoi($numero);
		}

		// Mise à jour d'un renvoi existant : on garde son identifiant, même si voip.ms ne le
		// renvoie pas dans sa réponse.
		if ($idRenvoi != "" && ($resultat['status'] ?? '') === "success") {
			return $resultat['forwarding'] ?? $idRenvoi;
		}

		if (!isset($resultat['forwarding'])) {
			$this->dire(_("addForwarding: Pas de forwarding ajouté.")."<br>\n");
			return false;
		}

		$this->dire(_("addForwarding: on retourne le forwarding !")."<br>\n");
		return $resultat['forwarding'];
	}

	/**
	 * L'identifiant du renvoi d'un numéro, parmi tous ceux du compte, ou false.
	 */
	private function trouverIdRenvoi(string $numero) {
		$renvois = $this->client->lireRenvois();
		if (!is_array($renvois)) {
			return false;
		}
		foreach ($renvois as $renvoi) {
			if ($renvoi['phone_number'] == $numero) {
				$this->dire(_("Id existant : ") . $renvoi['forwarding'] . "<br>\n");
				return $renvoi['forwarding'];
			}
		}
		return false;
	}

	// --- Groupe de sonnerie chez voip.ms ---

	/**
	 * L'identifiant du groupe de sonnerie du comité (par son nom). Retourne null s'il n'existe pas encore, et false
	 * si la lecture a échoué.
	 */
	private function trouverIdGroupe() {
		$groupes = $this->client->lireGroupes();
		if (!is_array($groupes)) {
			// En cas d'erreur, $groupes est un message
			$this->dire(_("Retour de getRingGroups anormal= ").$groupes."<br>\n");
			return false;
		}
		foreach ($groupes as $groupe) {
			if (isset($groupe['name']) && $groupe['name'] === $this->comite->ringgroupname) {
				return $groupe['ring_group'];
			}
		}
		// Aucun groupe de ce nom : ecrireGroupe() le créera
		$this->dire(_("Aucun ring group trouvé avec ce nom !")."<br>\n");
		return null;
	}

	/**
	 * Met à jour le groupe de sonnerie du comité. $membres est la liste ordonnée de
	 * ['forwardingid' => ..., 'ringtime' => secondes, 'pressone' => 0 ou 1]. Retourne le statut de voip.ms, ou le
	 * message d'erreur.
	 */
	private function ecrireGroupe($idGroupe, array $membres, $boiteVocale) {
		$resultat = $this->client->ecrireGroupe($idGroupe, $this->comite->ringgroupname, $membres, $boiteVocale);
		return is_array($resultat) ? $resultat["status"] : $resultat;
	}
}
