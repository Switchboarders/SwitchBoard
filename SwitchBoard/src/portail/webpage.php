<?php
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';
class Webpage {
	private $headers;
	private $footers;
	private $page;
	private $webpageparent;
	private $jsfiles;
	private $jsfilesemplacement;
	private $cssfiles;
	private $cssfilesemplacement;
	private $contentfiles;
	private $skipcontent;
	private $title;
	private $description ;
	private $usage ;
	private $username;
	private $password;
	private $privilegelevel;
	private $passwordvalue;
	private $iduser;
	private $user;
	private $checkboxvalues;

	public function __construct($webpageparent) {
		$this->webpageparent = $webpageparent;
		$this->headers = array();
		$this->footers = array();
		$this->jsfiles = array();
		$this->jsfilesemplacement = array();
		$this->cssfiles = array();
		$this->cssfilesemplacement  = array();
		$this->contentfiles = array();
		$this->skipcontent = False;
	}

	public function __destruct() {
		// clean up here
	}

	public function render() {
		foreach($this->headers as $header) {
			include $this->cheminPage($header);
		}
		// Ce que l'enregistrement qui vient d'avoir lieu a dit (voir terminerEnregistrement()), une seule fois
		echo messagesDEnregistrement();
		foreach($this->contentfiles as $contentfile) {
			if($this->skipcontent) break;
			include $this->cheminPage($contentfile);
		}
		foreach($this->footers as $footer) {
			include $this->cheminPage($footer);
		}
	}

	// Les appelants nomment une page depuis portail/ ('pages/utilisateur/header.php') : le chemin complet se fait
	// ici seulement, pour qu'il ne dépende ni du dossier courant ni de l'emplacement de ce fichier.
	private function cheminPage(string $page): string {
		return PORTAIL_DIRECTORY.$page;
	}

	public function addHeader($file) {
		$this->headers[] = $file;
	}
	public function addContent($file) {
		$this->contentfiles[] = $file;
	}
	public function addFooter($file) {
		$this->footers[] = $file;
	}
	public function addJsfile($file,$beforebody=True) {
		$this->jsfiles[] = $file;
		$this->jsfilesemplacement[] = $beforebody;
	}
	public function addCssfile($file,$beforebody=True) {
		$this->cssfiles[] = $file;
		$this->cssfilesemplacement[] = $beforebody;
	}
	
}
?>