<?php 
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';

/**
 * Les pages de la console d'administration. Le droit d'y entrer se contrôle avant l'appel, d'après la table des routes
 * (modules/portail/Routes.php), et non dans ces méthodes ; les actions (formulaires, liens qui modifient) gardent le leur.
 */
class AdminPanel{
	private $useraccess;
	public $databasemanager;
	public $userinformations;
	public $successmessage;
	public $sbchat;

	/**
	 * Construit un objet de la classe AdminPanel, conserve les infos suivantes:
	 *  $useraccess,$databasemanager,$userinformations
	 */
	function __construct($useraccess,$databasemanager,$userinformations){
		echo "<br>\n";
		$this->useraccess = $useraccess; 
		$this->databasemanager = $databasemanager;
		$this->userinformations = $userinformations;
		$this->sbchat = new ClientSwitchBoardChat(null);
		
	}

	/**
	 * Affiche la console d'administration
	 */
	function displayadminpanel(){
		ini_set('memory_limit', '512M');
		$this->useraccess->initiatecalendar();
		// Les actions de la console n'agissent qu'en POST (boutons de la page) ; la page qui suit montre la console
		if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['action'])) {
			requireCsrf();
			commencerEnregistrement();
			if($_POST['action']=="suppressuser"){
				$this->databasemanager->deleteuserbyid($_POST['iduser'] ?? '');
			}
			if($_POST['action']=="toggledebug"){
				// Bascule le mode debug depuis la console : voir modeDebugActif() dans
				// commun/lesconstantes.php pour les trois façons dont il peut être activé et
				// leur priorité. Sans effet si SB_DEBUG force déjà l'état (ops).
				// Toggles debug mode from the console: see modeDebugActif() in
				// commun/lesconstantes.php for the three ways it can be enabled and their
				// priority. Has no effect if SB_DEBUG already forces the state (ops).
				if (!modeDebugForceParEnv()) {
					definirModeDebug(!modeDebugActif());
				}
			}
			terminerEnregistrement();
		}

		$displayadminpanel = new Webpage($this);
		$displayadminpanel->addHeader('pages/utilisateur/header.php');
		
		$myUpdateManager = new UpdateManager();
		$nouvelleVersionDisponible = $myUpdateManager->checkNewVersionAvailable();

		// Les trois compteurs qui déterminent l'état des mises à jour. Ils sont indépendants
		// (le code et le schéma de BD avancent séparément) et étaient jusqu'ici invisibles :
		// impossible de comprendre "aucune mise à jour détectée" ou un échec de migration
		// sans aller lire un fichier JSON sur le serveur et une ligne dans la table parameters.
		echo "<div class='updatemessage'>"
			.htmlspecialchars(sprintf(
				_("Version du code installée : %s | disponible sur GitHub : %s | version du schéma de BD : %s"),
				$myUpdateManager->getVersionLocale() !== '' ? $myUpdateManager->getVersionLocale() : '?',
				$myUpdateManager->getVersionDistante() !== '' ? $myUpdateManager->getVersionDistante() : '?',
				(string)$this->databasemanager->getDbVersion()
			))
			."</div>\n";
		// Un jeton révoqué ou expiré n'empêche rien (la vérification se refait sans jeton), mais il faut le retirer ou le remplacer
		if ($myUpdateManager->jetonRefuse()) {
			echo "<div class='updatemessage'>".htmlspecialchars(_("GitHub refuse le jeton configuré (révoqué ou expiré) : la vérification s'est faite sans jeton. Retirez-le ou remplacez-le (GITHUB_TOKEN ou SB_GITHUB_TOKEN, ou \$githubtoken dans config/config.php)."))."</div>\n";
		}

		if ($myUpdateManager->getDerniereErreur() !== '' && (!TOKEN_REPO_CONFIGURE || $myUpdateManager->jetonRefuse())) {
			// GitHub n'a pas répondu à une demande sans jeton (injoignable, limite de requêtes
			// atteinte, ou dépôt privé) : la page explique pourquoi la mise à jour n'est pas
			// vérifiée, au lieu de la laisser échouer silencieusement.
			$displayadminpanel->addContent('pages/admin/tokenreminder.php');
		} elseif ($nouvelleVersionDisponible) {
			// Une fois la réponse donnée, la boîte et son bouton « Oui » ne s'affichent plus au-dessus des étapes
			if (!isset($_POST['update_choice'])) {
				$displayadminpanel->addContent('pages/admin/newVersionAvailable.php');
			}
			$displayadminpanel->addContent('pages/admin/updateVersionSB.php');
		}

		$displayadminpanel->addContent('pages/admin/adminpanelpage.php');
		$displayadminpanel->addFooter('pages/utilisateur/footer.php');
		$displayadminpanel->render();
	}
	/**
	 * Affiche une étape de la mise à jour, en vidant les tampons de sortie immédiatement.
	 * La mise à jour peut mourir sur une erreur fatale non rattrapable (dépassement de la
	 * mémoire ou du temps d'exécution pendant le backup de la BD, fréquent en hébergement
	 * mutualisé) : sans ces marqueurs envoyés au fur et à mesure, la page s'arrête net sans
	 * rien indiquer et on ne sait pas à quelle étape ça a cassé.
	 */
	private function etapeMaj($message) {
		echo "<div class='updatemessage'>".htmlspecialchars($message)."</div>\n";
		if (ob_get_level() > 0) { @ob_flush(); }
		@flush();
	}

	/**
	 * Lance la mise à jour puis termine la requête. Une fois src/ remplacé, le code neuf est sur le disque
	 * et l'ancien en mémoire : rien ne doit plus être inclus, ni pied de page ni displaysuccesspage().
	 */
	function lancerMiseAJour(): never {
		// display_errors est désactivé par défaut (voir modeDebugActif()) : une erreur fatale
		// pendant la mise à jour n'afficherait donc RIEN du tout. On l'attrape ici pour au
		// moins dire ce qui s'est passé - une mise à jour interrompue sans message est bien
		// pire qu'un message d'erreur technique à l'écran.
		register_shutdown_function(function () {
			$erreur = error_get_last();
			if ($erreur !== null && in_array($erreur['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
				echo "<div class='alert alert-error'>"
					. htmlspecialchars(_("La mise à jour s'est interrompue sur une erreur fatale : ") . $erreur['message']
						. " (" . $erreur['file'] . ":" . $erreur['line'] . ")")
					. "</div>\n";
				echo "<div class='alert alert-error'>"
					. htmlspecialchars(_("Si l'erreur concerne la mémoire ou le temps d'exécution pendant la sauvegarde de la base de données, augmentez memory_limit / max_execution_time, ou sauvegardez la base à la main puis relancez la mise à jour."))
					. "</div>\n";
			}
		});

		// Les étapes s'affichent l'une sous l'autre. Après une erreur fatale, le conteneur reste ouvert
		// et les messages de register_shutdown_function() s'y ajoutent : le navigateur le ferme seul.
		echo "<div class='etapesmaj'>\n";
		try {
			$miseAJour = new MiseAJour($this->databasemanager, new Fichiers(), new UpdateManager(), fn(string $message) => $this->etapeMaj($message));
			$miseAJour->executer();
		} catch (\Throwable $erreur) {
			echo "<div class='alert alert-error'>".htmlspecialchars(_("La mise à jour a échoué : ").$erreur->getMessage())."</div>\n";
		}
		echo "<p><a href='managementportal.php?page=adminpanel'>".htmlspecialchars(_("Retour à la console d'administration"))."</a></p>\n</div>\n</body>\n</html>\n";
		exit;
	}

	/**
	 * Affiche un message de succès
	 */
	function displaysuccesspage($successmessage){
		$this->successmessage = $successmessage;
		$displaysuccesspage = new Webpage($this);
		$displaysuccesspage->addHeader('pages/utilisateur/header.php');
		$displaysuccesspage->addContent('pages/admin/successpage.php');
		$displaysuccesspage->addFooter('pages/utilisateur/footer.php');
		$displaysuccesspage->render();
	}

	/**
	 * Affiche la page d'ajout un nouveau user, mot de passe et niveau de privilège
	 */
	function displayadduserpage(){
		$displayadduserpage = new Webpage($this);
		$displayadduserpage->addHeader('pages/utilisateur/header.php');
		$displayadduserpage->addContent('pages/admin/adduserpage.php');
		$displayadduserpage->addContent('pages/utilisateur/usermodificationform/usermodificationform.php');
		$displayadduserpage->addFooter('pages/utilisateur/footer.php');
		$displayadduserpage->render();
	}
	
	/**
	 * Affiche la page de modification du mot de passe et du niveau de privilège d' un user
	 */
	function displayuserloginandprivilegespage(){
		$displayuserloginandprivilegespage = new Webpage($this);
		$displayuserloginandprivilegespage->addHeader('pages/utilisateur/header.php');
		$displayuserloginandprivilegespage->addContent('pages/admin/userloginandprivilegespage.php');
		$displayuserloginandprivilegespage->addContent('pages/utilisateur/usermodificationform/usermodificationform.php');
		$displayuserloginandprivilegespage->addFooter('pages/utilisateur/footer.php');
		$displayuserloginandprivilegespage->render();
	}
	
	/**
	 * Affiche la page des abonnements aux services d' un user
	 */
	function displayuserservicepage(){
		$displayuserservicepage = new Webpage($this);
		$displayuserservicepage->addHeader('pages/utilisateur/header.php');
		$displayuserservicepage->addContent('pages/admin/userservicepage.php');
		$displayuserservicepage->addContent('pages/admin/userserviceform/userserviceform.php');
		$displayuserservicepage->addFooter('pages/utilisateur/footer.php');
		$displayuserservicepage->render();
	}
	
	/**
	 * Affiche la page de diagnostic pour un user
	 */
	function displayuserdiagnosticpage(){
		$userdiagnosticpage = new Webpage($this);
		$userdiagnosticpage->addHeader('pages/utilisateur/header.php');
		$userdiagnosticpage->addContent('pages/utilisateur/userdiagnostic.php');
		$userdiagnosticpage->addFooter('pages/utilisateur/footer.php');
		$userdiagnosticpage->render();
	}

	/**
	 * Affiche les statistiques et la répartition des coûts par DID d'un user (consultation
	 * ou envoi par courriel à la demande, sans attendre le cron mensuel)
	 */
	function displayuserstatsreportpage(){
		$userstatsreportpage = new Webpage($this);
		$userstatsreportpage->addHeader('pages/utilisateur/header.php');
		$userstatsreportpage->addContent('pages/admin/userstatsreportpage.php');
		$userstatsreportpage->addFooter('pages/utilisateur/footer.php');
		$userstatsreportpage->render();
	}

	/**
	 * Affiche le message de confirmation de suppression d'un user
	 */
	function displayusers(){
		$confirmationSuppression = _("Voulez vous vraiment supprimer cette entrée ?");
		$confirmationForcer = _("Envoyer maintenant les répondants de l'agenda de ce comité à voip.ms ?");
		$userlist = $this->databasemanager->getuserslist();
		echo "<div class='item-list'>";
		while($row = $userlist->fetch_assoc()) {
			echo "<div class='item-card'>";
			echo "<div class='item-card-title'>" . htmlspecialchars($row["username"]) .
				" <span class='item-card-meta'>(ID : " . $row["iduser"] . ", "._("Niveau de privilèges : ") . $row["privilegelevel"] . ")</span></div>";
			echo "<div class='item-card-actions'>";
			echo "<A href='?page=userloginandprivileges&iduser=".$row["iduser"]."'>"._("Modifier le mot de passe ou le niveau d'accès de l'utilisateur")."</A>";
			echo "<A href='?page=userinfo&iduser=".$row["iduser"]."'>"._("Éditer informations utilisateur")."</A>";
			echo "<A href='?page=sbchatinfo&iduser=".$row["iduser"]."'>"._("SwitchBoard Chat informations")."</A>";
			echo "<A href='?page=sbchatmacros&iduser=".$row["iduser"]."'>"._("Modifier les macros SwitchBoard Chat")."</A>";
			echo "<A href='?page=sbchatagreement&iduser=".$row["iduser"]."'>"._("Entente de confidentialité des bénévoles")."</A>";
			echo "<A href='?page=userdiagnostic&iduser=".$row["iduser"]."'>"._("Diagnostiquer les connexions établies par l'utilisateur")."</A>";
			// Seulement pour un comité local qui a un agenda : un compte principal ou un compte admin n'a rien à forcer
			if ($row["privilegelevel"] > 0 && !empty($this->databasemanager->getuserinfobyid($row["iduser"])['calendarurl'] ?? '')) {
				echo boutonAction('refreshringgroup', _("Forcer le changement des répondants"), ['iduser' => intval($row["iduser"]), 'confirmation' => $confirmationForcer]);
			}
			echo "<A href='?page=userservice&iduser=".$row["iduser"]."'>"._("Définir les services utilisés par un utilisateur")."</A>";
			echo "<A href='?page=userstatsreport&iduser=".$row["iduser"]."'>"._("Consulter ou envoyer les statistiques de l'utilisateur")."</A>";
			echo boutonAction('adminpanel', _("Supprimer utilisateur"), ['champs' => ['action' => 'suppressuser', 'iduser' => $row["iduser"]], 'confirmation' => $confirmationSuppression, 'classe' => 'danger']);
			echo "</div></div>";
		}
		echo "</div>";
	}
	/**
	 * Demande un jeton API SwitchBoard Chat pour le compte ?iduser=. N'agit qu'en POST (le bouton de la page des informations
	 * SwitchBoard Chat) : ce que la demande a dit s'affiche sur cette page, après la redirection. Un GET y renvoie, sans rien demander.
	 */
	function request_sbchat_token(){
		ini_set('memory_limit', '512M');
		if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
			redirigerVers("managementportal.php?page=sbchatinfo".(empty($_GET['iduser']) ? '' : "&iduser=".intval($_GET['iduser'])));
		}
		commencerEnregistrement();
		$requesterid = $this->databasemanager->getcurrentuserid();
		$requestersbchatinfo = $this->databasemanager->getSbchatinfobyId($requesterid);
		$this->sbchat->setsbchaturl($requestersbchatinfo['sbchatserveraddress']);
		$this->sbchat->request_sbchat_api($requesterid, $_GET["iduser"] ?? -1);
		terminerEnregistrement([], 'sbchatinfo');
	}
	function manageuserssbchatpage(){
		$sbchatinformationspage = new Webpage($this);
		$sbchatinformationspage->addHeader('pages/utilisateur/header.php');
		$sbchatinformationspage->addContent('pages/utilisateur/masalteruserform/masalteruserform.php');
		$sbchatinformationspage->addFooter('pages/utilisateur/footer.php');
		$sbchatinformationspage->render();


	}

	/**
	 * Certificat SBChat/MAS approuvable en un clic depuis la console admin - voir
	 * adminpanelspages/sbchatcertificatepage.php.
	 */
	function displaySbchatCertificatePage(){
		$sbchatcertificatepage = new Webpage($this);
		$sbchatcertificatepage->addHeader('pages/utilisateur/header.php');
		$sbchatcertificatepage->addContent('pages/admin/sbchatcertificatepage.php');
		$sbchatcertificatepage->addFooter('pages/utilisateur/footer.php');
		$sbchatcertificatepage->render();
	}

	/**
	 * Courriel de test envoyable depuis la console admin - voir
	 * adminpanelspages/mailtestpage.php.
	 */
	function displayMailTestPage(){
		$mailtestpage = new Webpage($this);
		$mailtestpage->addHeader('pages/utilisateur/header.php');
		$mailtestpage->addContent('pages/admin/mailtestpage.php');
		$mailtestpage->addFooter('pages/utilisateur/footer.php');
		$mailtestpage->render();
	}

	/**
	 * Autorisation OAuth2 pour le courriel (Microsoft 365 / Google) - voir
	 * adminpanelspages/mailoauthpage.php.
	 */
	function displayMailOAuthPage(){
		$mailoauthpage = new Webpage($this);
		$mailoauthpage->addHeader('pages/utilisateur/header.php');
		$mailoauthpage->addContent('pages/admin/mailoauthpage.php');
		$mailoauthpage->addFooter('pages/utilisateur/footer.php');
		$mailoauthpage->render();
	}

	/**
	 * Réglages des pings de dead-man's-switch (healthchecks.io ou compatible) - voir
	 * adminpanelspages/deadmanswitchpage.php.
	 */
	function displayDeadManSwitchPage(){
		$deadmanswitchpage = new Webpage($this);
		$deadmanswitchpage->addHeader('pages/utilisateur/header.php');
		$deadmanswitchpage->addContent('pages/admin/deadmanswitchpage.php');
		$deadmanswitchpage->addFooter('pages/utilisateur/footer.php');
		$deadmanswitchpage->render();
	}

	/**
	 * Affiche la page des suggestions de tâche cron
	 */
	function displaycronsuggestionpage(){
		$cronsuggestionpage = new Webpage($this);
		$cronsuggestionpage->addHeader('pages/utilisateur/header.php');
		$cronsuggestionpage->addContent('pages/admin/cronsuggestion.php');
		$cronsuggestionpage->addFooter('pages/utilisateur/footer.php');
		$cronsuggestionpage->render();

	}

	/**
	 * Affiche la page de comparaison des coûts entre plusieurs comptes voip.ms (mois par mois +
	 * solde actuel), pour appuyer une décision de consolidation de comptes. Voir
	 * ComparaisonCouts.php (calcul) et ExportComparaisonCouts.php
	 * (CSV/Excel).
	 *
	 * Les actions de téléchargement (download_csv/download_xlsx) sont interceptées ICI, AVANT
	 * de construire un Webpage/appeler render() : Webpage::render() fait des include() directs
	 * (userpages/header.php produit déjà du HTML), et AdminPanel::__construct() a lui-même déjà
	 * émis du texte avant que cette méthode ne soit appelée - un fichier à télécharger doit donc
	 * être envoyé avant que quoi que ce soit d'autre ne s'affiche. Voir le ob_start() en tête de
	 * managementportal.php, vidé juste avant les header() ci-dessous.
	 */
	function displayMultiAccountCostReportPage(){
		$actionsInterceptees = ['init_job', 'process_step', 'render_result', 'download_csv', 'download_xlsx'];
		if (!empty($_POST['action']) && in_array($_POST['action'], $actionsInterceptees, true)) {
			requireCsrf();
			ini_set('memory_limit', '512M');

			// Voir la même note dans ComparaisonCouts.php : ces classes sont
			// utilisées directement par ComparaisonCouts, à charger ici par
			// l'appelant.

			// Vide tout tampon de sortie existant (voir ob_start() dans managementportal.php et
			// le echo inconditionnel de AdminPanel::__construct()) - sans ça, ces octets HTML
			// parasites précéderaient la réponse JSON ou le fichier téléchargé et le
			// corrompraient. Nécessaire pour TOUTES les actions ci-dessous, pas seulement les
			// téléchargements : une réponse JSON précédée de HTML n'est plus du JSON valide.
			while (ob_get_level() > 0) {
				@ob_end_clean();
			}

			$action = $_POST['action'];
			$jobmanager = new TacheComparaisonCouts();

			if ($action === 'init_job') {
				$dateFrom = trim((string)($_POST['dateFrom'] ?? ''));
				$dateTo = trim((string)($_POST['dateTo'] ?? ''));
				$idusers = array_map('intval', (array)($_POST['idusers'] ?? []));
				$resultat = $jobmanager->demarrerJob($idusers, $dateFrom, $dateTo);
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode($resultat);
				exit;
			}

			if ($action === 'process_step') {
				// Une seule unité de travail (1 à 3 appels API) par appel - voir
				// ComparaisonCouts.php pour pourquoi c'est fractionné ainsi.
				$resultat = $jobmanager->traiterEtape($this->databasemanager, (string)($_POST['jobToken'] ?? ''));
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode($resultat);
				exit;
			}

			if ($action === 'render_result') {
				$matrix = $jobmanager->obtenirMatrice((string)($_POST['jobToken'] ?? ''));
				if (isset($matrix['erreur'])) {
					header('Content-Type: application/json; charset=UTF-8');
					echo json_encode($matrix);
					exit;
				}
				$jobToken = (string)($_POST['jobToken'] ?? '');
				ob_start();
				require PORTAIL_DIRECTORY.'pages/admin/multiaccountcostreportresult.php';
				$html = ob_get_clean();
				header('Content-Type: application/json; charset=UTF-8');
				echo json_encode(['html' => $html]);
				exit;
			}

			// download_csv / download_xlsx
			$matrix = $jobmanager->obtenirMatrice((string)($_POST['jobToken'] ?? ''));
			if (isset($matrix['erreur'])) {
				die(htmlspecialchars($matrix['erreur']));
			}
			$exporter = new ExportComparaisonCouts();
			$nomFichier = "comparaison-couts-{$matrix['dateFrom']}-{$matrix['dateTo']}";
			if ($action === 'download_csv') {
				header('Content-Type: text/csv; charset=UTF-8');
				header('Content-Disposition: attachment; filename="'.$nomFichier.'.csv"');
				header('Cache-Control: no-store');
				echo $exporter->buildCsv($matrix);
			} else {
				$spreadsheet = $exporter->buildXlsxSpreadsheet($matrix);
				$writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
				header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
				header('Content-Disposition: attachment; filename="'.$nomFichier.'.xlsx"');
				header('Cache-Control: no-store');
				$writer->save('php://output');
			}
			$jobmanager->supprimerJob((string)($_POST['jobToken'] ?? ''));
			exit;
		}

		$multiaccountcostreportpage = new Webpage($this);
		$multiaccountcostreportpage->addHeader('pages/utilisateur/header.php');
		$multiaccountcostreportpage->addContent('pages/admin/multiaccountcostreportpage.php');
		$multiaccountcostreportpage->addFooter('pages/utilisateur/footer.php');
		$multiaccountcostreportpage->render();
	}

}
