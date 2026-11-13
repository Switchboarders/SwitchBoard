<?php

class InformationsUtilisateur{
	public string $voipmsusername;
	public string $voipmsapipassword;
	public string $calendarurl;
	public string $calendartype;
	public string $calendarusername;
	public string $calendarpassword;
	public string $validationfeedbackemail;
	public string $ringgroupname;
	public string $didnumber;
	public string $language;
	// Réglages du comité pour le groupe de sonnerie ; null = valeur par défaut (voir GroupeSonnerie)
	public ?int $ringtime = null;
	public ?int $pressone = null;
	private $superprivilege;
	public $iduser;
	public $username;
	public string $defaultlanguage;
	public DatabaseManager $databasemanager;
	
	public function __construct(int $iduser=-1,bool $superprivilege=False){
		$this->superprivilege = $superprivilege;
		$this->defaultlanguage = 'fr';
		$this->iduser = $iduser;
		$this->username = "";
		$this->databasemanager = new DatabaseManager();
		$this->voipmsusername = "";
		$this->voipmsapipassword = "";
		$this->calendarurl = "";
		$this->calendartype = "secreturl";
		$this->calendarusername = "";
		$this->calendarpassword = "";
		$this->validationfeedbackemail = "";
		$this->ringgroupname = "";
		$this->didnumber = "";
		$this->language = $this->defaultlanguage;
		$this->getUserinfofromdatabase($iduser);

	}
	public function getUserinfofromdatabase(int $iduser=-1){
	
		$this->databasemanager->filluserinformationsfromdb($this, $iduser, $this->superprivilege);  
		$this->iduser = $iduser;

	}
	
	private function formatstring($astring){
		$returnvalue = str_replace("\n","",$astring);
		$returnvalue = str_replace("\r","",$returnvalue);
		return $returnvalue;
	}
 

}




?>