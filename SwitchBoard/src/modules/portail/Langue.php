<?php
require_once PORTAIL_DIRECTORY.'useraccess.php';
class Langue{
	public DatabaseManager $databasemanager;
	private UserAccess $useraccess;
	function __construct()
	{
		$this->databasemanager = new DatabaseManager();
		$this->useraccess = new UserAccess();
		// Set default language has the admin language
		$adminid = $this->databasemanager->getAdminId();
		$this->setLanguage($adminid);

	}
	function setLanguage($id=null) {
		// Définir la langue
		if($id === null){
			// In case nobody is registered, the system will just use admin langage has specified in this constructor
			if(!$this->useraccess->testifauthorized())
				return;
			$language = $this->databasemanager->getuserinfobyid($this->databasemanager->getcurrentuserid())['language'];
		}else{
			$userinfoarray = $this->databasemanager->getuserinfobyid($id);
			// Here the database is still empty
			if($userinfoarray === null){
				$language = 'fr';
			}else{
				$language = $userinfoarray['language'];
			}
			
		}
		
		switch ($language){
			case 'fr':
				$lang = 'fr_CA.utf8';
				break;
			case 'en':
				$lang = 'en_US.utf8';
				break;
			default:
				$lang = 'fr_CA.utf8';
				break;
		}
	   
		// TEST IF getText Extension is set
		if(!function_exists('_')){
			echo "Assurez vous que l'extension getText est activee dans php.ini et redemarrez votre serveur.<br>";
			echo "Please insure that getText extension is enabled in php.ini and restart your server.";
			
			exit();
		}    
		putenv("LANG=$lang");
		putenv("LANGUAGE={$lang}");
		if(!setlocale(LC_ALL, "")){
			if($language == "fr")
				$lang = 'en_US.utf8';
			else
				$lang = 'fr_CA.utf8';
			putenv("LANG=$lang");
			putenv("LANGUAGE={$lang}");
			if(!setlocale(LC_ALL, "")){
				echo "Please install en_US or fr_CA locale or ask your system admin. <br> SVP installer la locale fr_CA ou en_US ou demander à votre administrateur système.";
			}
		}
		// Spécifier l'emplacement des fichiers de traduction
		/// NOTE ! Require gettext extension, uncomment
		// extension=gettext
		// in php.ini or in your cpanel php manager
		bindtextdomain("switchboard", DOSSIER_TRADUCTIONS);
		bind_textdomain_codeset('switchboard', 'UTF-8');
		textdomain("switchboard");
		
		
		
		
	}
	
}
