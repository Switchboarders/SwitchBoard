<?php

class TraitementComite{
	// Code de l'exception d'un agenda reçu sans aucun événement : cachecalendarfile() ne le traite
	// pas comme un téléchargement raté, et la page de diagnostic affiche quand même le message.
	const AGENDA_SANS_EVENEMENT = 1;
	protected InformationsUtilisateur $userinformations;
	protected Courriel $emailmanager;
	protected ClientVoipms $voipms;
	protected GroupeSonnerie $groupeSonnerie;
	protected JournalDeTache $logmanager;
	protected DatabaseManager $databasemanager;
	protected MemoireAlertes $memoireAlertes;
	public function __construct(InformationsUtilisateur $userinformations,Courriel $emailmanager,ClientVoipms $voipms,?GroupeSonnerie $groupeSonnerie = null){
		$this->userinformations = $userinformations;
		$this->emailmanager = $emailmanager;
		$this->voipms = $voipms;
		// Les messages du groupe de sonnerie vont à l'écran : page « Forcer » ou sortie du cron
		$this->groupeSonnerie = $groupeSonnerie ?? new GroupeSonnerie($voipms, $userinformations, $userinformations->databasemanager, fn(string $message) => print($message));
		$this->databasemanager = new DatabaseManager();
		$this->memoireAlertes = new MemoireAlertes($this->databasemanager);
	}
	public function groupeSonnerie(): GroupeSonnerie{
		return $this->groupeSonnerie;
	}
	/**
	 * Valide l'agenda du comité : retourne les messages à montrer, dans l'ordre, chacun [type, texte] avec type
	 * « avertissement » (à corriger) ou « info ». Le texte n'est pas échappé : c'est à la page de le mettre en HTML.
	 */
	public function validerAgenda(): array {
		$this->logmanager = new JournalDeTache("validate", intval($this->userinformations->iduser));
		$messages = [];
		$calendarfile = $this->cachecalendarfile();
		if ($calendarfile === False) {
			return [['type' => 'avertissement', 'texte' => $this->terminerAgendaIllisible(_("Agenda illisible : validation impossible."))]];
		}
		// Tout l'agenda, sans dates ni répétitions dépliées
		$agenda = new Agenda($calendarfile, null, null, false);
		$neededvalidation = false;
		foreach ($agenda->evenements() as $evenement){
			// Les repères (faire1->o, nbsec->20) sont retirés avant de chercher le numéro
			$description = $this->lireReperes($evenement->description);
			$numero = NumeroTelephone::lireDans($description['texte']);
			foreach ($description['avertissements'] as $avertissement) {
				$messages[] = ['type' => 'avertissement', 'texte' => $evenement->titre." : ".$avertissement];
				$neededvalidation = true;
			}
			if (!$numero->estValide()) {
				$messages[] = ['type' => 'avertissement', 'texte' => $numero->messageDeRefus($evenement)];
				$neededvalidation = true;
				continue;
			}
			// Montrer ce que les repères de l'événement donneront dans le groupe de sonnerie
			if (!empty($description['reperes'])) {
				$messages[] = ['type' => 'info', 'texte' => $evenement->titre." : ".$this->reglagesDuRepondant($description['reperes'])['description']];
			}
		}
		if(!$neededvalidation){
			$messages[] = ['type' => 'info', 'texte' => _("Les données sont entrées correctement.")];
		}
		return $messages;
	}
	public function sendremindersms($iduser,$sendthesms=False,$mode="daybefore"){
		$time_start = microtime(true);
		$now = new \DateTime('now');
		$this->logmanager = new JournalDeTache("remindersms".$mode,$iduser);
		$this->voipms->journaliserDans($this->logmanager);
		$this->logmanager->writelinetolog(_("Heure d'exécution : ").$now->format('d-m-Y H:i:s'));
		$this->logmanager->incrementtab();
		$calendarfile = $this->cachecalendarfile();
		if ($calendarfile === False) {
			return $this->terminerAgendaIllisible(_("Agenda illisible : aucun rappel envoyé."));
		}
		$agenda = new Agenda($calendarfile, 1, 3, true);

		[$debut, $fin] = FenetreDeRappel::pour($mode, $now);
		$this->logmanager->writelinetolog(_("Début du prochain shift : ").$debut->format("d-m-Y H:i:s P"));
		$this->logmanager->writelinetolog(_("Fin du prochain shift : "). $fin->format("d-m-Y H:i:s P"));

		$plan = RappelsSms::planifier($agenda, $mode, $debut, $fin);
		// Le même refus que le groupe de sonnerie, au journal du rappel et à l'écran, jamais par courriel
		foreach ($plan['refus'] as $refus) {
			$this->logmanager->writelinetolog($refus);
			echo $refus."\n";
		}
		$answer = $this->envoyerLesRappelsSms($plan['rappels'], $sendthesms);
		if ($answer === null) {
			$this->logmanager->writelinetolog(_("Personne ne sera texté, il n'y a personne d'identifié."));
		}
		$time_end = microtime(true);
		$execution_time = ($time_end - $time_start);
		$this->logmanager->decrementtab();
		$this->logmanager->writelinetolog(_("Temps d'execution : ").$execution_time."\n");
		$this->logmanager->trimandcloselog();
		return $answer ?? _("Personne à texter.");
	}
	/**
	 * Envoie les rappels (ou les décrit seulement, hors mode d'envoi) et retourne la réponse du dernier, ou null s'il
	 * n'y en a aucun.
	 */
	private function envoyerLesRappelsSms(array $rappels, bool $envoyer): ?string {
		$answer = null;
		foreach ($rappels as $rappel) {
			$this->logmanager->writelinetolog("Nom : ".$rappel['nom']._(" Numéro de téléphone : ").$rappel['numero']._(" Plages : ").$rappel['plages']);
			$this->logmanager->writelinetolog("Longueur du SMS : ".strlen($rappel['message']));
			if ($envoyer) {
				$answer = $this->envoyerSms($rappel['message'], $rappel['numero']);
			} else {
				$answer = str_replace("\n","<br>\n",$this->logmanager->getCurrentlog()) . _("Mode test, aucun sms envoyé.");
			}
		}
		return $answer;
	}
	/**
	 * Envoie un rappel par SMS et retourne le statut de voip.ms, ou le message d'erreur.
	 */
	private function envoyerSms(string $message, string $numero) {
		$resultat = $this->voipms->envoyerSms($numero, $message);
		if (!is_array($resultat)) {
			return $resultat;
		}
		echo $resultat["status"] . _(" pour le numéro de destination : ") . $numero . "\n";
		return $resultat["status"];
	}
	// Télécharge l'agenda et le garde dans le cache (calendarcache/). Si le téléchargement
	// échoue, on le signale (journal et courriels) et on se rabat sur la dernière copie en cache,
	// pour que le groupe de sonnerie continue de fonctionner pendant une panne passagère. Un agenda
	// reçu sans aucun événement ne remplace pas le cache et n'est écrit qu'au journal : il
	// reviendrait à chaque passage du cron.
	// Retourne le chemin du fichier en cache, ou False si l'agenda est illisible (aucun fichier
	// utilisable) : voir terminerAgendaIllisible().
	public function cachecalendarfile($type = "ringgroupandsms"){
		if ($this->adresseAgenda($type) === '') {
			$message = _("Veuillez entrer une url de calendrier pour cet utilisateur");
			$this->logmanager->writelinetolog($message);
			echo $message."<br>";
			return False;
		}
		$fichierCache = $this->generatecachefilename($type);

		try {
			if ($this->remplacerCache($fichierCache, $this->telechargerAgenda($type))) {
				echo _("Agenda téléchargé avec succès.<br>");
				return $fichierCache;
			}
		} catch (Exception $e) {
			if ($e->getCode() === self::AGENDA_SANS_EVENEMENT) {
				$this->logmanager->writelinetolog($e->getMessage());
			} else {
				$this->signalerEchecTelechargement($e->getMessage(), $type);
			}
		}

		if ($this->contientEvenements((string)@file_get_contents($fichierCache))) {
			echo _("Chargement à partir du cache.");
			return $fichierCache;
		}
		return False;
	}

	// Vrai si ce contenu ICS a au moins un événement. C'est ce qui sépare « agenda illisible » de
	// « personne de garde » : on regarde le fichier, parce que le filtre de dates de la librairie
	// ICal peut laisser 0 événement dans un agenda bien lisible. La casse compte, comme dans ICal.
	private function contientEvenements(string $contenu): bool {
		return str_contains($contenu, 'BEGIN:VEVENT');
	}

	// Remplace la copie en cache par l'agenda reçu ; retourne False si la copie n'a pas changé.
	// L'écriture passe par un fichier à côté, puis un renommage : file_put_contents() vide le
	// fichier avant d'écrire, et une écriture ratée (disque plein) détruirait la copie précédente.
	private function remplacerCache(string $fichierCache, string $contenu): bool {
		if (!$this->contientEvenements($contenu)) {
			$this->logmanager->writelinetolog(_("Agenda reçu sans aucun événement : il ne remplace pas la copie en cache."));
			return false;
		}
		// Un cron peut créer le dossier avant toute page du portail : le .htaccess part avec lui
		(new Fichiers())->protegerDossierDuWeb(DOSSIER_CACHE_AGENDAS);
		$fichierTemporaire = $fichierCache.".".getmypid().".tmp";
		if (@file_put_contents($fichierTemporaire, $contenu) !== strlen($contenu) || !@rename($fichierTemporaire, $fichierCache)) {
			@unlink($fichierTemporaire);
			$this->logmanager->writelinetolog(_("Écriture de l'agenda dans le cache impossible."));
			return false;
		}
		return true;
	}

	// Agenda illisible : un agenda qu'on ne peut pas lire ne veut pas dire que personne n'est de
	// garde, alors on ne change rien, et les derniers répondants restent en place. Pas d'exception :
	// ce n'est pas une panne du comité, et le décompte d'erreurs du cron (donc le signal de vie) ne
	// doit pas bouger. Le téléchargement raté, lui, a déjà son courriel limité. Écrit $message au
	// journal, le ferme et retourne $message.
	private function terminerAgendaIllisible(string $message): string {
		$maintenant = new DateTime();
		$this->logmanager->writelinetolog($maintenant->format('d-m-Y H:i:s')." : ".$message);
		$this->logmanager->trimandcloselog();
		return $message;
	}

	// Message pour une page, quand une exception a arrêté le traitement de l'agenda. Le message de
	// l'exception peut contenir un chemin de fichier : il va au journal d'erreurs de PHP, jamais à
	// l'écran.
	public static function messageErreurPourPage(\Throwable $erreur): string {
		error_log("TraitementComite: ".$erreur->getMessage());
		return _("L'agenda n'a pas pu être traité. Si le problème persiste, contactez votre administrateur.");
	}

	// Télécharge l'agenda et retourne son contenu ICS ; lève une Exception qui dit pourquoi en
	// cas d'échec. C'est la seule fonction qui télécharge un agenda : le cron (cachecalendarfile)
	// et la page de diagnostic l'utilisent toutes deux, pour que le diagnostic montre exactement
	// ce que le cron rencontrera.
	// Un seul essai, volontairement : plusieurs essais rapprochés pourraient faire passer le
	// serveur de SwitchBoard pour hostile aux yeux du fournisseur de l'agenda.
	public function telechargerAgenda($type = "ringgroupandsms"){
		$url = $this->adresseAgenda($type);
		if ($url === '') {
			throw new Exception(_("Aucune adresse d'agenda n'est configurée."));
		}
		// L'agenda SBChat est toujours une URL secrète ; seul l'agenda principal peut être CalDAV.
		if ($type === "ringgroupandsms" && $this->userinformations->calendartype === 'caldav') {
			return $this->telechargerAgendaCaldav($url);
		}
		return $this->telechargerAgendaUrlSecrete($url);
	}

	// Adresse de l'agenda principal (groupe de sonnerie et SMS) ou de l'agenda SBChat ;
	// chaîne vide si aucune n'est configurée.
	private function adresseAgenda($type){
		if ($type === "sbchat") {
			return (string)$this->databasemanager->getSbchatinfobyId($this->iduserDuComite())['sbchatcalendarurl'];
		}
		return (string)$this->userinformations->calendarurl;
	}

	// Sur une page, InformationsUtilisateur désigne l'utilisateur courant par -1
	private function iduserDuComite(): int {
		$iduser = intval($this->userinformations->iduser);
		return $iduser === -1 ? intval($this->databasemanager->getcurrentuserid()) : $iduser;
	}

	// Agenda publié par une adresse secrète (Google, Outlook / Office 365, etc.) : simple GET.
	// On exige un agenda dans la réponse : une page d'erreur ou de connexion renvoyée avec le
	// code 200 ne doit pas remplacer la bonne copie en cache.
	private function telechargerAgendaUrlSecrete($url){
		$reponse = $this->requeteHttp($url, []);
		if ($reponse['code'] !== 200) {
			throw new Exception(sprintf(_("Le serveur de l'agenda a répondu avec le code HTTP %d."), $reponse['code']));
		}
		if (strpos($reponse['corps'], 'BEGIN:VCALENDAR') === false) {
			throw new Exception(_("La réponse du serveur n'est pas un agenda (BEGIN:VCALENDAR absent)."));
		}
		return $reponse['corps'];
	}

	// Agenda CalDAV (p. ex. cPanel) : requête REPORT avec nom d'utilisateur et mot de passe. Le
	// serveur répond un XML (code 207) qui contient un ICS par événement ; on les fusionne en un
	// seul agenda.
	private function telechargerAgendaCaldav($url){
		$requeteCaldav = '<?xml version="1.0" encoding="utf-8" ?>
<C:calendar-query xmlns:D="DAV:" xmlns:C="urn:ietf:params:xml:ns:caldav">
	<D:prop>
		<D:getetag />
		<C:calendar-data />
	</D:prop>
	<C:filter>
		<C:comp-filter name="VCALENDAR">
			<C:comp-filter name="VEVENT" />
		</C:comp-filter>
	</C:filter>
</C:calendar-query>';
		$reponse = $this->requeteHttp($url, [
			CURLOPT_CUSTOMREQUEST => 'REPORT',
			CURLOPT_POSTFIELDS    => $requeteCaldav,
			CURLOPT_HTTPHEADER    => ['Content-Type: application/xml; charset=utf-8', 'Depth: 1'],
			CURLOPT_HTTPAUTH      => CURLAUTH_BASIC,
			CURLOPT_USERPWD       => $this->userinformations->calendarusername.':'.$this->userinformations->calendarpassword,
		]);
		if ($reponse['code'] !== 207) {
			throw new Exception(sprintf(_("Le serveur CalDAV a répondu avec le code HTTP %d au lieu de 207."), $reponse['code']));
		}
		return $this->mergeCaldavResponseToIcs($reponse['corps']);
	}

	// Requête HTTP commune aux deux sortes d'agenda. La vérification du certificat TLS reste
	// active (réglage par défaut de cURL) : les fournisseurs d'agendas ont des certificats
	// reconnus. Retourne ['code' => code HTTP, 'corps' => contenu reçu] ; lève une Exception si
	// le serveur n'a pas pu être joint.
	private function requeteHttp($url, array $optionsParticulieres){
		$ch = curl_init($url);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_MAXREDIRS      => 5,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'SwitchBoard (https://github.com/Switchboarders/SwitchBoard)',
		]);
		curl_setopt_array($ch, $optionsParticulieres);
		$corps = curl_exec($ch);
		if ($corps === false) {
			throw new Exception(_("Le serveur de l'agenda n'a pas pu être joint : ").curl_error($ch));
		}
		// Pas de curl_close() : sans effet depuis PHP 8.0, déprécié depuis PHP 8.5.
		return ['code' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE), 'corps' => $corps];
	}

	// Échec du téléchargement : l'écran et le journal à chaque passage ; un courriel au plus par 24 heures au comité et
	// à l'admin. L'agenda de SBChat est un autre agenda : son problème ne doit pas cacher celui du groupe de sonnerie.
	private function signalerEchecTelechargement($raison, string $type){
		$message = _("Échec du téléchargement de l'agenda : ").$raison;
		echo $message."<br>";
		$this->logmanager->writelinetolog($message);
		$lignes = [
			$message,
			_("Note, ce message d'erreur peut se produire occasionnellement sans occasionner de problème. Contacter votre administrateur s'il se produit continuellement."),
			_("Notez que vous pouvez effacer les plages horaires passées de votre agenda pour réduire la charge sur le système !"),
		];
		$estSbchat = ($type === "sbchat");
		$this->envoyerAlerteAuComite($estSbchat ? MemoireAlertes::AGENDA_SBCHAT : MemoireAlertes::AGENDA, $lignes);

		$iduser = $this->iduserDuComite();
		$probleme = $estSbchat ? MemoireAlertes::AGENDA_SBCHAT_ADMIN : MemoireAlertes::AGENDA_ADMIN;
		if (!$this->memoireAlertes->peutPartir($iduser, $probleme)) {
			return;
		}
		foreach ($lignes as $ligne) {
			$this->emailmanager->addlinetomessagebody($ligne);
		}
		if ($this->emailmanager->sendemailtoadmin(_("Rapport d'erreur"), $iduser)) {
			$this->memoireAlertes->noterEnvoi($iduser, $probleme);
		}
	}

	// Au plus un courriel au comité par 24 heures pour ce problème. Si l'envoi rate, le passage suivant réessaie.
	private function envoyerAlerteAuComite(string $probleme, array $lignes): void {
		$iduser = $this->iduserDuComite();
		if ($lignes === [] || !$this->memoireAlertes->peutPartir($iduser, $probleme)) {
			return;
		}
		foreach ($lignes as $ligne) {
			$this->emailmanager->addlinetomessagebody($ligne);
		}
		if ($this->emailmanager->sendemail(_("Rapport d'erreur"))) {
			$this->memoireAlertes->noterEnvoi($iduser, $probleme);
		}
	}

	// Un nom SBChat invalide : l'écran et le journal à chaque passage, le courriel au comité au plus une fois par 24 heures
	private function signalerNomsSbchatInvalides(array $noms): void {
		$lignes = array_map(fn($nom) => _("Le nom d'utilisateur ").$nom._(" est invalide"), $noms);
		foreach ($lignes as $ligne) {
			$this->logmanager->writelinetolog($ligne);
			echo $ligne."\n";
		}
		$this->envoyerAlerteAuComite(MemoireAlertes::NOM_SBCHAT, $lignes);
	}

	// Fusionne la réponse XML multistatus CalDAV (une ressource ICS par événement) en un seul
	// fichier ICS valide (un VTIMEZONE + tous les VEVENT), au format attendu par la librairie ICal.
	private function mergeCaldavResponseToIcs($xmlResponse){
		libxml_use_internal_errors(true);
		$dom = new DOMDocument();
		if (!$dom->loadXML($xmlResponse)) {
			throw new Exception(_("La réponse CalDAV n'est pas un XML valide."));
		}
		$xpath = new DOMXPath($dom);
		$xpath->registerNamespace('C', 'urn:ietf:params:xml:ns:caldav');
		$noeuds = $xpath->query('//C:calendar-data');

		$vtimezone = '';
		$vevents = '';
		foreach ($noeuds as $noeud) {
			$ics = $noeud->textContent;
			if ($vtimezone === '' && preg_match('/BEGIN:VTIMEZONE.*?END:VTIMEZONE/s', $ics, $matches)) {
				$vtimezone = $matches[0]."\r\n";
			}
			if (preg_match_all('/BEGIN:VEVENT.*?END:VEVENT/s', $ics, $matches)) {
				foreach ($matches[0] as $vevent) {
					$vevents .= $vevent."\r\n";
				}
			}
		}
		if ($vevents === '') {
			throw new Exception(_("Aucun événement trouvé dans la réponse CalDAV."), self::AGENDA_SANS_EVENEMENT);
		}
		return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:-//SwitchBoard//CalDAV//FR\r\n".$vtimezone.$vevents."END:VCALENDAR\r\n";
	}

	public function generatecachefilename($type="ringgroupandsms"){
		
		// Remove anything which isn't a word, whitespace, number
		// or any of the following caracters -_~,;[]().
		// If you don't need to handle multi-byte characters
		// you can use preg_replace rather than mb_ereg_replac
		$filename = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $this->userinformations->username);
		// Remove any runs of periods 
		$filename = mb_ereg_replace("([\.]{2,})", '', $filename);
		
		if ($this->userinformations->iduser != -1) $filename .= "id".strval($this->userinformations->iduser);
		switch ($type){
			case "ringgroupandsms" :
				$filename = DOSSIER_CACHE_AGENDAS.$filename.".ical";
				break;
			case "sbchat" :
				$filename = DOSSIER_CACHE_AGENDAS.$filename."sbchat.ical";
				break;
		}
		
		
		return $filename;
		
	}
	// On rafraichie le ring group
	/**
	 * Met le groupe de sonnerie du comité à jour selon l'agenda. $forcerResynchronisation ignore
	 * la liste conservée et renvoie tout à voip.ms (bouton « Forcer le changement de répondants »).
	 */
	public function refreshringgroup($iduser, bool $forcerResynchronisation = false){
		$time_start = microtime(true);
		$this->logmanager = new JournalDeTache("ringgroupupdate",$iduser);
		$this->voipms->journaliserDans($this->logmanager);
		$calendarfile = $this->cachecalendarfile();
		if ($calendarfile === False) {
			$message = $this->terminerAgendaIllisible(_("Agenda illisible : groupe de sonnerie inchangé, les derniers répondants restent en place."));
			// La page « Forcer » affiche ce qui est écrit à l'écran, pas la valeur retournée
			echo $message."\n";
			return $message;
		}
		$agenda = new Agenda($calendarfile, 2, 1, true);
		$repondants = array();
		$numerosInvalides = array();
		$now = new DateTime();
		
		$this->logmanager->writelinetolog(_("Heure d'exécution : ").$now->format('d-m-Y H:i:s'));
	
		
		echo _("Heure d'exécution :").$now->format('d-m-Y H:i:s')."\n";
		$this->logmanager->incrementtab();
		foreach ($agenda->evenements() as $evenement){
			$dtstart_tz = $evenement->debut;
			$dtend_tz = $evenement->fin;
			// Les repères (faire1->o, nbsec->20) sont retirés avant de chercher le numéro
			$description = $this->lireReperes($evenement->description);
			$numero = NumeroTelephone::lireDans($description['texte']);
			if (!$numero->estValide()) {
				$numerosInvalides[] = $numero->messageDeRefus($evenement);
				continue;
			}
			$phonenumber = $numero->numero;
			//ne pas tenir compte des evenements qui commencent par "off->" qui signifie que le répondant ne doit pas etre inscrit
			// dans voip.ms puisque celui-ci est présent au bureau
			if (($now > $dtstart_tz && $now < $dtend_tz)&&(stripos($evenement->titre, "off->") === false)){
				$reglages = $this->reglagesDuRepondant($description['reperes']);
				$repondants[] = ['nom' => $evenement->titre, 'numero' => $phonenumber, 'reglages' => $reglages, 'debut' => $dtstart_tz->getTimestamp()];
				// Au journal seulement : un courriel à chaque passage pour une faute de frappe serait
				// trop. La page de validation de l'agenda les montre aussi.
				foreach ($description['avertissements'] as $avertissement) {
					$this->logmanager->writelinetolog($evenement->titre." : ".$avertissement);
					echo $evenement->titre." : ".$avertissement."\n";
				}
				$this->logmanager->writelinetolog(_("Tour de ").$evenement->titre);
				$this->logmanager->writelinetolog(_("Début du shift : ").$dtstart_tz->format('d-m-Y H:i'));
				$this->logmanager->writelinetolog(_("Fin du shift : ").$dtend_tz->format('d-m-Y H:i'));
				$this->logmanager->writelinetolog(_("Numéro  de téléphone: ").$phonenumber."\n");
				
				echo _("Tour de ").$evenement->titre."\n" ;
				echo _("Début du shift : ").$dtstart_tz->format('d-m-Y H:i')."\n";
				echo _("Fin du shift : ").$dtend_tz->format('d-m-Y H:i')."\n" ;
				echo _("Numéro  de téléphone: ").$phonenumber."\n" ;
				echo _("Réglages : ").$reglages['description']."\n" ;
				$this->logmanager->writelinetolog(_("Réglages : ").$reglages['description']);

			}
		}
		// L'ordre des événements n'est pas garanti d'un téléchargement à l'autre, quelle que soit la
		// source (Google, Office 365, CalDAV) : sans tri, la liste conservée verrait un faux
		// changement à chaque inversion et renverrait le groupe.
		$repondants = self::trierRepondants($repondants);
		$ringparticipantsnames = array_column($repondants, 'nom');
		$ringparticipantsnumbers = array_column($repondants, 'numero');
		$ringparticipantsreglages = array_column($repondants, 'reglages');

		for($i=0;$i<count($ringparticipantsnames);$i++){
			echo _("Nom : ").$ringparticipantsnames[$i]._(" Numéro : ").$ringparticipantsnumbers[$i]."\n";
			
		}
		$result = $this->groupeSonnerie->mettreAJour($iduser,$ringparticipantsnames,$ringparticipantsnumbers,$ringparticipantsreglages,$forcerResynchronisation);
		$this->logmanager->writelinetolog(_("Groupe de sonnerie : ").$this->groupeSonnerie->modePassage());
		// Un renvoi qui n'a pas pu être créé n'empêche pas setRingGroup de réussir : le dire, pour
		// qu'un « success » ne cache pas un répondant absent du groupe de sonnerie.
		$renvoisManquants = $this->groupeSonnerie->renvoisManquants();
		if (!empty($renvoisManquants)) {
			// Seuls les 4 derniers chiffres vont au journal
			$numerosMasques = array_map(fn($numero) => "…".substr($numero, -4), $renvoisManquants);
			$result .= " ".sprintf(_("(partiel : renvoi manquant pour %d numéro(s) sur %d : %s)"), count($renvoisManquants), count($ringparticipantsnumbers), implode(", ", $numerosMasques));
			$this->logmanager->writelinetolog(sprintf(_("Renvoi d'appel non créé pour : %s. Ce répondant ne recevra pas les appels jusqu'au prochain passage."), implode(", ", $numerosMasques)));
		}
		print(_("Le résultat de l'envoi vers voip.ms est : ").$result."\n");
		// Après l'envoi à voip.ms : un courriel qui tarde ne retarde pas le groupe de sonnerie
		foreach ($numerosInvalides as $message) {
			$this->logmanager->writelinetolog($message);
			echo $message."\n";
		}
		$this->envoyerAlerteAuComite(MemoireAlertes::NUMERO, $numerosInvalides);
		$time_end = microtime(true);
		$execution_time = ($time_end - $time_start);
		$this->logmanager->decrementtab();
		$this->logmanager->writelinetolog("Temps d'execution : ".$execution_time."\n");
		$this->logmanager->trimandcloselog();
		return _("Le résultat de l'envoi vers voip.ms est : ").$result."\n";
	}
	// On rafraichi SwitchBoardChat
	public function refreshsbchat($iduser){
		$time_start = microtime(true);
		$this->logmanager = new JournalDeTache("sbchatupdate",$iduser);
		$calendarfile = $this->cachecalendarfile("sbchat");
		if ($calendarfile === False) {
			return $this->terminerAgendaIllisible(_("Agenda illisible : répondants de SwitchBoard Chat inchangés."));
		}
		$agenda = new Agenda($calendarfile, 2, 1, true);
		$respondents = array();
		$nomsInvalides = array();
		$now = new DateTime();
		
		$this->logmanager->writelinetolog(_("Heure d'exécution : ").$now->format('d-m-Y H:i:s'));
	
		
		echo _("Heure d'exécution :").$now->format('d-m-Y H:i:s')."\n";
		$this->logmanager->incrementtab();
		foreach ($agenda->evenements() as $evenement){
			$dtstart_tz = $evenement->debut;
			$dtend_tz = $evenement->fin;
			// To lowercase and remove whitespaces before and after
			$matrixusername = NomMatrix::depuisTitre($evenement->titre);
			if (!NomMatrix::estValide($matrixusername)){
				$nomsInvalides[] = $evenement->titre;
				continue;
			}

			if($now > $dtstart_tz && $now < $dtend_tz){
			$respondents[] = $matrixusername ; 
			$this->logmanager->writelinetolog(_("Tour de ").$evenement->titre);
			$this->logmanager->writelinetolog(_("Début du shift : ").$dtstart_tz->format('d-m-Y H:i'));
			$this->logmanager->writelinetolog(_("Fin du shift : ").$dtend_tz->format('d-m-Y H:i'));
			echo _("Tour de ").$evenement->titre."\n" ;
			echo _("Début du shift : ").$dtstart_tz->format('d-m-Y H:i')."\n";
			echo _("Fin du shift : ").$dtend_tz->format('d-m-Y H:i')."\n" ;

			}
		}
		
		for($i=0;$i<count($respondents);$i++){
			echo _("Nom utilisateur : ").$respondents[$i]."\n";
			
		}
		$this->signalerNomsSbchatInvalides($nomsInvalides);

		$sbchatinfo = $this->databasemanager->getSbchatinfobyId($iduser);
		$sbchat = new ClientSwitchBoardChat($sbchatinfo['sbchatserveraddress']);

		$result = $sbchat->change_call_center_respondents($sbchatinfo['sbchatapitoken'],$respondents);
		
		$time_end = microtime(true);
		$execution_time = ($time_end - $time_start);
		$this->logmanager->decrementtab();
		$this->logmanager->writelinetolog("Temps d'execution : ".$execution_time."\n");
		$this->logmanager->trimandcloselog();
		return _("<br>Le résultat de l'envoi vers sbchat est : ").$result['querystatus']." Users updated : ".$result['message']."\n";
	}	

	public function sendremindersbchat($iduser,$mode){
		$time_start = microtime(true);
		$now = new \DateTime('now');
		$this->logmanager = new JournalDeTache("remindersbchat".$mode,$iduser);
		$this->logmanager->writelinetolog(_("Heure d'exécution : ").$now->format('d-m-Y H:i:s'));
		$this->logmanager->incrementtab();
		$calendarfile = $this->cachecalendarfile("sbchat");
		if ($calendarfile === False) {
			return $this->terminerAgendaIllisible(_("Agenda illisible : aucun rappel envoyé."));
		}
		$agenda = new Agenda($calendarfile, 1, 3, true);

		[$debut, $fin] = FenetreDeRappel::pour($mode, $now);
		$this->logmanager->writelinetolog(_("Début du prochain shift : ").$debut->format("d-m-Y H:i:s P"));
		$this->logmanager->writelinetolog(_("Fin du prochain shift : "). $fin->format("d-m-Y H:i:s P"));

		$plan = RappelsChat::planifier($agenda, $mode, $debut, $fin);
		foreach ($plan['lignes'] as $ligne) {
			$this->logmanager->writelinetolog($ligne);
			echo $ligne."\n";
		}
		foreach ($plan['destinataires'] as $destinataire) {
			echo _("Nom utilisateur : ").$destinataire[0]."\n";
		}
		$this->signalerNomsSbchatInvalides($plan['nomsInvalides']);
		$sbchatinfo = $this->databasemanager->getSbchatinfobyId($iduser);
		$sbchat = new ClientSwitchBoardChat($sbchatinfo['sbchatserveraddress']);
		$finalresult = "";
		foreach ($plan['destinataires'] as $destinataire){
			$result = $sbchat->send_message_to_respondents($sbchatinfo['sbchatapitoken'],[$destinataire[0]],$plan['prefixe'].$destinataire[1]);
			$finalresult .= $result['querystatus']."<br>Users messaged : ".$result['message'] . "<br>";
		}
		$time_end = microtime(true);
		$execution_time = ($time_end - $time_start);
		$this->logmanager->decrementtab();
		$this->logmanager->writelinetolog("Temps d'execution : ".$execution_time."\n");
		$this->logmanager->trimandcloselog();
		return _("<br>Le résultat de l'envoi vers sbchat est : ").$finalresult."\n";
	}
	/**
	 * Voir ReperesAgenda::lire().
	 */
	public function lireReperes($description): array {
		return ReperesAgenda::lire((string)$description);
	}

	/**
	 * Met les répondants dans un ordre qui ne dépend pas de l'agenda : par début du quart, puis par
	 * numéro de téléphone. Chaque répondant est ['nom', 'numero', 'reglages', 'debut' (timestamp)].
	 */
	public static function trierRepondants(array $repondants): array {
		usort($repondants, fn($a, $b) => [$a['debut'], $a['numero']] <=> [$b['debut'], $b['numero']]);
		return $repondants;
	}

	/**
	 * Durée de sonnerie et « Faire le 1 » d'un répondant : le repère de l'agenda d'abord, sinon le
	 * réglage du comité, sinon la valeur par défaut. 'description' dit d'où vient chaque valeur.
	 */
	public function reglagesDuRepondant(array $reperes): array {
		$choisir = function ($repere, $comite, $defaut) {
			if ($repere !== null) return [$repere, _("agenda")];
			if ($comite !== null) return [$comite, _("comité")];
			return [$defaut, _("par défaut")];
		};
		$dureeComite = $this->userinformations->ringtime === null ? null : ReperesAgenda::dureeValide($this->userinformations->ringtime);
		$faire1Comite = in_array($this->userinformations->pressone, [0, 1], true) ? $this->userinformations->pressone : null;
		[$ringtime, $sourceDuree] = $choisir($reperes['nbsec'] ?? null, $dureeComite, GroupeSonnerie::DUREE_SONNERIE_DEFAUT);
		[$pressone, $sourceFaire1] = $choisir($reperes['faire1'] ?? null, $faire1Comite, GroupeSonnerie::FAIRE_LE_1_DEFAUT);
		return [
			'ringtime' => $ringtime,
			'pressone' => $pressone,
			'description' => sprintf(_("sonnerie %d s (%s), faire le 1 : %s (%s)"), $ringtime, $sourceDuree, $pressone ? _("oui") : _("non"), $sourceFaire1),
		];
	}
}
