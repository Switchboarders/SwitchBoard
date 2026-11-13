<?php
/**
 * Les pages du portail : pour chaque valeur de ?page=, la classe et la méthode qui l'affichent, et le droit qu'elle
 * exige. Une nouvelle page s'ajoute ici, avec son droit, et nulle part ailleurs : managementportal.php contrôle ce
 * droit avant d'appeler la méthode, et le banc d'essai essaie chaque route de cette liste.
 *
 * La classe ne fait que décrire : elle ne lit pas la requête, ne contrôle rien et n'affiche rien.
 */
class Routes {
	// Réservée aux admins : niveau de privilège 0, ou -1 pour l'admin principal
	const ADMIN = 'admin';
	// Toute personne connectée. Avec iduser dans l'URL : son propre compte seulement, n'importe lequel pour un admin.
	const CONNECTE = 'connecte';

	// Les pages qui agissent (voip.ms, SwitchBoard Chat, SMS) : elles n'agissent qu'en POST, avec le jeton CSRF que
	// managementportal.php contrôle avant l'appel (un bouton, boutonAction()). Ouvertes par un GET, elles montrent ce que
	// la dernière action a dit, ou renvoient au tableau de bord. Aucune autre page ne modifie rien sur un GET.
	const ACTIONS = ['refreshringgroup', 'refreshsbchat', 'remindersms', 'remindersmsjustbefore', 'remindersbchat', 'remindersbchatjustbefore', 'requestsbchattoken'];

	// page => [classe, méthode, droit]. Le nom d'une page ne change jamais : des liens et des favoris en dépendent.
	const PAGES = [
		'mainpage'                 => [UserAccess::class, 'displaymainuserpage',                 self::CONNECTE],
		'validatecalendar'         => [UserAccess::class, 'displayvalidationpage',               self::CONNECTE],
		'refreshringgroup'         => [UserAccess::class, 'displayrefreshringgrouppage',         self::CONNECTE],
		'remindersms'              => [UserAccess::class, 'displayremindersmspage',              self::CONNECTE],
		'remindersmsjustbefore'    => [UserAccess::class, 'displayremindersmsjustbeforepage',    self::CONNECTE],
		'refreshsbchat'            => [UserAccess::class, 'refreshsbchat',                       self::CONNECTE],
		'remindersbchatjustbefore' => [UserAccess::class, 'displayremindersbchatjustbeforepage', self::CONNECTE],
		'remindersbchat'           => [UserAccess::class, 'displayremindersbchatpage',           self::CONNECTE],
		'userinfo'                 => [UserAccess::class, 'userinformationspage',                self::CONNECTE],
		'sbchatinfo'               => [UserAccess::class, 'sbchatinformationspage',              self::CONNECTE],
		'sbchatagreement'          => [UserAccess::class, 'sbchatagreementpage',                 self::CONNECTE],
		'sbchatmacros'             => [UserAccess::class, 'sbchatmacrospage',                    self::CONNECTE],
		'mystats'                  => [UserAccess::class, 'displaymystatspage',                  self::CONNECTE],
		'logout'                   => [UserAccess::class, 'logout',                              self::CONNECTE],

		'adminpanel'               => [AdminPanel::class, 'displayadminpanel',                   self::ADMIN],
		'modifyuser'               => [AdminPanel::class, 'displayadduserpage',                  self::ADMIN],
		'userloginandprivileges'   => [AdminPanel::class, 'displayuserloginandprivilegespage',   self::ADMIN],
		'userservice'              => [AdminPanel::class, 'displayuserservicepage',              self::ADMIN],
		'userdiagnostic'           => [AdminPanel::class, 'displayuserdiagnosticpage',           self::ADMIN],
		'userstatsreport'          => [AdminPanel::class, 'displayuserstatsreportpage',          self::ADMIN],
		'requestsbchattoken'       => [AdminPanel::class, 'request_sbchat_token',                self::ADMIN],
		'manageuserssbchat'        => [AdminPanel::class, 'manageuserssbchatpage',               self::ADMIN],
		'croncommands'             => [AdminPanel::class, 'displaycronsuggestionpage',           self::ADMIN],
		'sbchatcertificate'        => [AdminPanel::class, 'displaySbchatCertificatePage',        self::ADMIN],
		'mailtest'                 => [AdminPanel::class, 'displayMailTestPage',                 self::ADMIN],
		'mailoauth'                => [AdminPanel::class, 'displayMailOAuthPage',                self::ADMIN],
		'deadmanswitch'            => [AdminPanel::class, 'displayDeadManSwitchPage',            self::ADMIN],
		'multiaccountcostreport'   => [AdminPanel::class, 'displayMultiAccountCostReportPage',   self::ADMIN],
	];

	/**
	 * La page d'une URL sans ?page= : la console pour un admin, qui s'en sert le plus, le tableau de bord sinon.
	 */
	public static function pageParDefaut(bool $estAdmin): string {
		return $estAdmin ? 'adminpanel' : 'mainpage';
	}

	/**
	 * Vrai si cette page agit (en POST seulement) : elle exige alors le jeton CSRF.
	 */
	public static function agit(string $page): bool {
		return in_array($page, self::ACTIONS, true);
	}

	/**
	 * La route de cette page, ['classe', 'methode', 'droit'], ou null si la page n'existe pas.
	 */
	public static function trouver(string $page): ?array {
		if (!isset(self::PAGES[$page])) {
			return null;
		}
		[$classe, $methode, $droit] = self::PAGES[$page];
		return ['classe' => $classe, 'methode' => $methode, 'droit' => $droit];
	}
}
