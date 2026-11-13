<?php 
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';

class TokenAccess {
	public DatabaseManager $databasemanager;
	private $calendarmanager;
	private $languagemanager;
	function __construct(){
		$this->databasemanager = new DatabaseManager();	
	}
	public function assignTokenToUser($iduser){
		
		$this->databasemanager->createorupdateusertoken($iduser);
	}
	public function createTokenIfNotExist($iduser){
		// Si aucun token valide (non expiré) n'existe pour cet utilisateur, on en crée un.
		if(!$this->databasemanager->isUserTokenExist($iduser)){
			$this->databasemanager->createOrUpdateUserToken($iduser,$this->generateSecureToken());
		}

	}

	/**
	 * Génère un jeton d'accès sans authentification, à l'aide d'un générateur
	 * cryptographiquement sûr (contrairement à str_shuffle(), qui n'en est pas un et ne
	 * produit pas une distribution uniforme).
	 */
	public function generateSecureToken(int $longueurOctets = 32): string
	{
		return bin2hex(random_bytes($longueurOctets));
	}
	public function getIduserFromToken($token){
		return $this->databasemanager->getIduserFromToken($token);
		
	}
	public function getusertokenlink($iduser){
		$query = $_SERVER['PHP_SELF'];
		$path = pathinfo( $query );
		$token = $this->databasemanager->getUserTokenFromIduser($iduser);
		// HTTP_HOST plutôt que SERVER_NAME : sous Docker, nginx a « server_name localhost; »,
		// et SERVER_NAME donnait un lien vers https://localhost/... (même choix que les
		// redirections vers HTTPS des formulaires).
		$tokenurl = "https://".$_SERVER['HTTP_HOST'].$path['dirname']."/token.php?token=$token";
		// HTTP_HOST/PHP_SELF viennent de la requête ; on échappe systématiquement avant de
		// les insérer dans du HTML.
		$tokenurlEchappe = htmlspecialchars($tokenurl, ENT_QUOTES);
		echo "<div class='login-title'>"._("Lien de validation sans authentification (vous pouvez le copier-coller) :")."</div>";
		echo "<center><h3><A href='$tokenurlEchappe'>"._("Cliquez ici pour valider les numéros de téléphone de votre agenda")."</A></h3></center>";

	}
	public function displayTokenValidationPage($token){
		$this->languagemanager = new Langue();
		$this->languagemanager->setLanguage();
		$iduser = $this->getIduserFromToken($token);
		$userinformations = new InformationsUtilisateur($iduser,True);
		$emailmanager = new Courriel($userinformations->validationfeedbackemail);	
		$this->calendarmanager = new TraitementComite($userinformations,$emailmanager,new ClientVoipms($userinformations));
		
		echo htmlDeLaValidation($this->calendarmanager->validerAgenda());
	}
}
