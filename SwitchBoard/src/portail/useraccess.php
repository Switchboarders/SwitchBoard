<?php
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';
require_once PORTAIL_DIRECTORY.'tokenaccess.php';

class UserAccess{
	public TraitementComite $calendarmanager;
	public ClientVoipms $voipms;
	public DatabaseManager $databasemanager;
	public InformationsUtilisateur $userinformations;
	public JournalDeTache $logmanager;
	public TokenAccess $tokenaccess;
	public Courriel $emailmanager;
	private $username;
	private $password;
	private $privilegelevel;
	private $passwordvalue;
	private $title;
	function __construct(){
		$this->databasemanager = new DatabaseManager();
		$this->userinformations = new InformationsUtilisateur();
		$this->logmanager = new JournalDeTache("ringgroupupdate",$this->databasemanager->getcurrentuserid());
		$this->tokenaccess = new TokenAccess();
	}
	
	public function displayloginpage(){
		
		include PORTAIL_DIRECTORY.'pages/utilisateur/loginform/loginform.php';
		
		
	}
	public function displayusermodificationpage($title,$description){
		
		include PORTAIL_DIRECTORY.'pages/utilisateur/usermodificationform/usermodificationform.php';
		
	} 
	public function testifauthorized(){
		if (session_status() != PHP_SESSION_ACTIVE){
			return False;
		}
		if(empty($_SESSION["authenticated"])||empty($_SESSION["iduser"])){
			return False;
		}
		return True;
	}
	
	public function displaymainuserpage(){
		$this->initiatecalendar();
		$displaymainuserpage = new Webpage($this);
		$displaymainuserpage->addHeader('pages/utilisateur/header.php');
		$displaymainuserpage->addContent('pages/utilisateur/mainpage.php');
		$displaymainuserpage->addFooter('pages/utilisateur/footer.php');
		$displaymainuserpage->render();
		
	}
	public function displayvalidationpage(){
		$displayvalidationpage = new Webpage($this);
		$displayvalidationpage->addHeader('pages/utilisateur/header.php');
		$displayvalidationpage->addContent('pages/utilisateur/validationpage.php');
		$displayvalidationpage->addFooter('pages/utilisateur/footer.php');
		$displayvalidationpage->render();
		
	}
	
	public function displayrefreshringgrouppage(){
		ini_set('memory_limit', '512M');
		$refreshringgrouppage = new Webpage($this);
		$refreshringgrouppage->addHeader('pages/utilisateur/header.php');
		$refreshringgrouppage->addContent('pages/utilisateur/refreshringgrouppage.php');
		$refreshringgrouppage->addFooter('pages/utilisateur/footer.php');
		$refreshringgrouppage->render();
		
		
	}
	
	
	public function displayremindersmspage(){
		ini_set('memory_limit', '512M');
		$remindersmspage = new Webpage($this);
		$remindersmspage->addHeader('pages/utilisateur/header.php');
		$remindersmspage->addContent('pages/utilisateur/remindersmspage.php');
		$remindersmspage->addFooter('pages/utilisateur/footer.php');
		$remindersmspage->render();
		
	}
	
	public function displayremindersbchatjustbeforepage(){
		ini_set('memory_limit', '512M');		
		$remindersmsjustbeforepage = new Webpage($this);
		$remindersmsjustbeforepage->addHeader('pages/utilisateur/header.php');
		$remindersmsjustbeforepage->addContent('pages/utilisateur/remindersbchatjustbeforepage.php');
		$remindersmsjustbeforepage->addFooter('pages/utilisateur/footer.php');
		$remindersmsjustbeforepage->render();
		
	}
	public function displayremindersbchatpage(){
		ini_set('memory_limit', '512M');
		$remindersmspage = new Webpage($this);
		$remindersmspage->addHeader('pages/utilisateur/header.php');
		$remindersmspage->addContent('pages/utilisateur/remindersbchatpage.php');
		$remindersmspage->addFooter('pages/utilisateur/footer.php');
		$remindersmspage->render();
		
	}
	
	public function displayremindersmsjustbeforepage(){
		ini_set('memory_limit', '512M');		
		$remindersmsjustbeforepage = new Webpage($this);
		$remindersmsjustbeforepage->addHeader('pages/utilisateur/header.php');
		$remindersmsjustbeforepage->addContent('pages/utilisateur/remindersmsjustbeforepage.php');
		$remindersmsjustbeforepage->addFooter('pages/utilisateur/footer.php');
		$remindersmsjustbeforepage->render();
		
	}
	
	public function refreshsbchat(){
		ini_set('memory_limit', '512M');		
		$remindersmsjustbeforepage = new Webpage($this);
		$remindersmsjustbeforepage->addHeader('pages/utilisateur/header.php');
		$remindersmsjustbeforepage->addContent('pages/utilisateur/refreshsbchat.php');
		$remindersmsjustbeforepage->addFooter('pages/utilisateur/footer.php');
		$remindersmsjustbeforepage->render();
		
	}
	
	public function displaymystatspage(){
		ini_set('memory_limit', '512M');
		$mystatspage = new Webpage($this);
		$mystatspage->addHeader('pages/utilisateur/header.php');
		$mystatspage->addContent('pages/utilisateur/mystatspage.php');
		$mystatspage->addFooter('pages/utilisateur/footer.php');
		$mystatspage->render();
	}

	public function userinformationspage(){
		ini_set('memory_limit', '512M');
		
		$userinformationspage = new Webpage($this);
		$userinformationspage->addHeader('pages/utilisateur/header.php');
		$userinformationspage->addContent('pages/utilisateur/userinformationspage.php');
		$userinformationspage->addContent('pages/utilisateur/userinfoform/userinfoform.php');
		$userinformationspage->addFooter('pages/utilisateur/footer.php');
		$userinformationspage->render();
	}
	public function sbchatinformationspage(){
		ini_set('memory_limit', '512M');
		
		$userinformationspage = new Webpage($this);
		$userinformationspage->addHeader('pages/utilisateur/header.php');
		$userinformationspage->addContent('pages/utilisateur/sbchatinformationspage.php');
		$userinformationspage->addContent('pages/utilisateur/sbchatinfo/sbchatinfoform.php');
		$userinformationspage->addFooter('pages/utilisateur/footer.php');
		$userinformationspage->render();
	}
	public function sbchatagreementpage(){
		$page = new Webpage($this);
		$page->addHeader('pages/utilisateur/header.php');
		$page->addContent('pages/utilisateur/sbchatmacrospage.php');
		$page->addContent('pages/utilisateur/sbchatagreement/sbchatagreementform.php');
		$page->addFooter('pages/utilisateur/footer.php');
		$page->render();
	}
	public function sbchatmacrospage(){
		ini_set('memory_limit', '512M');
		
		$userinformationspage = new Webpage($this);
		$userinformationspage->addHeader('pages/utilisateur/header.php');
		$userinformationspage->addContent('pages/utilisateur/sbchatmacrospage.php');
		$userinformationspage->addContent('pages/utilisateur/sbchatmacros/sbchatmacrosform.php');
		$userinformationspage->addFooter('pages/utilisateur/footer.php');
		$userinformationspage->render();
	}
	
	/**
	 * Prépare l'agenda, le client voip.ms et le groupe de sonnerie d'un comité : celui de la session si $iduser est -1,
	 * sinon celui qu'un admin a choisi (le droit a été contrôlé avant la page).
	 */
	public function initiatecalendar(int $iduser = -1){
		$userinformations = new InformationsUtilisateur($iduser);
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);	
		$this->voipms = new ClientVoipms($userinformations);
		$this->calendarmanager = new TraitementComite($userinformations,$emailmanager,$this->voipms);
		
	}
	public function logout(){
		$_SESSION = [];
		session_destroy();
		echo _("Vous êtes maintenant déconnecté.")." <A href='managementportal.php'>"._("Se reconnecter")."</A>";

	}
	
}

?>