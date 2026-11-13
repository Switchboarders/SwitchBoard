<?php
// version 2025-11-16: avec vraies constantes et try-catch

class DatabaseManager {
	// Types de compte, pour les rapports mensuels (voir getTypeCompte())
	const COMPTE_PRINCIPAL = 'principal'; // paie le compte voip.ms d'un regroupement : tous les DID et le solde
	const COMITE_MEMBRE = 'membre';       // comité local dont le compte voip.ms est payé par un compte principal
	const COMITE_AUTONOME = 'autonome';   // comité local seul sur son propre compte voip.ms

	private $servername;
	private $username;
	private $password;
	private $dbname;
	private $conn;
	private Chiffrement $cryptomanager;

	/**
	 * Construit un objet de la classe DatabaseManager; créé une connexion avec la BD,
	 * valide celle-ci et créé les tables si nécessaire
	 */
	function __construct() {
		include CONFIG_DIRECTORY.'config.php';
		$this->servername = $dbservername;
		$this->username = $dbusername;
		$this->password = $dbpassword;
		$this->dbname = $dbname ;
		$this->cryptomanager = new Chiffrement();
		// Create connection
		$this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);

		// Check connection
		if ($this->conn->connect_error) {
			die("<h1>Connection failed: " . messageErreurTechnique($this->conn->connect_error) . "</h1>");

		}
		if(!$this->doTablesExist()){
			echo "Création des tables de la base de données/Creating database tables";
			$this->createDatabase();
		}
	}
	function create_new_conn(){
		return new mysqli($this->servername, $this->username, $this->password, $this->dbname);
	}
	/**
	 * Read the table "parameters" to get the current version number
	 * of SwitchBoard database.
	 * 
	 * @return Database version number if reading is successful;
	 * False otherwise.
	 */
	public function getDbVersion() {
		$fatiguant = "db version";
		$sql = "SELECT paramvalue FROM parameters WHERE paramname = '$fatiguant'";
		$result = $this->conn->query($sql);

		$row = $result->fetch_assoc();
		if (! empty($row)) {
			return $row['paramvalue'];
		 }

		return False;
	}

	/**
	 * Applique une entrée de dbVersion.json et avance le compteur 'db version'. Une entrée vide ne fait
	 * qu'avancer le compteur. Le compteur ne bouge pas si le SQL échoue, y compris sur une instruction qui
	 * n'est pas la première d'une entrée qui en contient plusieurs.
	 *
	 * @throws mysqli_sql_exception
	 */
	public function appliquerMigration(string $sql, int $noVersion): void {
		if (trim($sql) !== '') {
			$this->executerInstructions($sql);
		}
		$version = (string)$noVersion;
		$requete = $this->conn->prepare("UPDATE parameters SET paramvalue = ? WHERE paramname = 'db version'");
		$requete->bind_param('s', $version);
		$requete->execute();

		// parameters n'a pas de clé : un ON DUPLICATE KEY ajouterait une ligne à chaque fois
		$this->conn->query("DELETE FROM parameters WHERE paramname = 'date maj version'");
		$date = date('Y-m-d');
		$requete = $this->conn->prepare("INSERT INTO parameters (paramname, paramvalue) VALUES ('date maj version', ?)");
		$requete->bind_param('s', $date);
		$requete->execute();
	}

	// Sans ce vidage, l'erreur d'une instruction après la première resterait invisible et la connexion inutilisable
	private function executerInstructions(string $sql): void {
		$this->conn->multi_query($sql);
		do {
			$resultat = $this->conn->store_result();
			if ($resultat instanceof mysqli_result) {
				$resultat->free();
			}
		} while ($this->conn->more_results() && $this->conn->next_result());
		if ($this->conn->errno !== 0) {
			throw new mysqli_sql_exception($this->conn->error, $this->conn->errno);
		}
	}

	/**
	 * Sauvegarde la BD dans le fichier $backupPath (ex. backups/<date>/BD.sql) avec DumpBD, sur une connexion à part.
	 * Le fichier se relit avec restoreDatabase().
	 *
	 * @throws Exception « Backup error: ... » si la sauvegarde échoue ; le fichier commencé est alors effacé.
	 */
	public function backupDatabase($backupPath){
		$connexion = null;
		try {
			$connexion = $this->create_new_conn();
			if ($connexion->connect_errno) {
				throw new RuntimeException($connexion->connect_error);
			}
			(new DumpBD($connexion))->ecrire($backupPath);
			return true;
		}
		catch (\Throwable $e) {
			throw new \Exception("Backup error: " . $e->getMessage());
		}
		finally {
			if ($connexion instanceof mysqli && !$connexion->connect_errno) {
				$connexion->close();
			}
		}
	}
	
	/**
	 * Remet la BD dans l'état du dump $backupPath (un BD.sql de backupDatabase()).
	 *
	 * Une table créée depuis le dump est retirée : le dump ne la connaît pas, et elle resterait alors que le
	 * compteur 'db version' recule, donc la migration qui l'a créée échouerait à la mise à jour suivante.
	 *
	 * @throws RuntimeException Si le fichier n'est pas un dump de la BD.
	 * @throws mysqli_sql_exception
	 */
	public function restoreDatabase(string $backupPath): void {
		$sql = (string)file_get_contents($backupPath);
		preg_match_all('/^DROP TABLE IF EXISTS `([^`]+)`;$/m', $sql, $tablesDuDump);
		if ($tablesDuDump[1] === []) {
			throw new RuntimeException("Aucune table dans le dump : $backupPath");
		}
		$this->executerInstructions($sql);
		$tablesActuelles = array_column($this->conn->query('SHOW TABLES')->fetch_all(), 0);
		foreach (array_diff($tablesActuelles, $tablesDuDump[1]) as $table) {
			$this->conn->query("DROP TABLE `$table`");
		}
	}
	
	/**
	 * valide la connexion DB et tente de la renouveler si besoin 
	 */
	public function testandrefreshdb(){
		if($this->conn->error == "MySQL server has gone away" ){
			echo "db connection problem, trying to renew now....";
			$this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);
		}
	}
	
	/**
	 * rafraichissement de la connexion DB 
	 */
	public function refreshdbconnexion(){
		$this->conn = new mysqli($this->servername, $this->username, $this->password, $this->dbname);
		if ($this->conn->connect_errno) {
			echo "Échec de la tentative de reconnexion MySQL : " . messageErreurTechnique($this->conn->connect_error);
			return false;
		}
		return true;
	}
	
	/**
	 * Authentifie le username et le password ; retourne la ligne de la table user
	 * (incluant iduser) si les identifiants sont valides, False autrement.
	 * Ne doit être appelée qu'une seule fois, lors de la connexion : le reste du code
	 * s'appuie ensuite sur $_SESSION['authenticated'] + $_SESSION['iduser'].
	 */
	public function authenticateuser($username,$password){
		if(is_null($username) or is_null($password) or $username === '' or $password === '')
			return False;

		$sql = "SELECT * FROM `user` WHERE username = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("s", $username);
		$stmt->execute();
		$result = $stmt->get_result();
		$row = $result->fetch_assoc();
		if ($row && password_verify($password, $row["password"])) {
			return $row;
		}
		return False;
	}

	/**
	 * Obtenir l'iduser correspondant à un username, sans vérification de mot de passe.
	 * Utilisé par les scripts batch qui agissent pour un compte donné (ex : rapports
	 * envoyés pour un compte de service) sans session utilisateur authentifiée.
	 */
	public function getiduserbyusername($username){
		if(is_null($username) or $username === '')
			return False;
		$sql = "SELECT iduser FROM `user` WHERE username = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("s", $username);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result->num_rows == 0) {
			return False;
		}
		return $result->fetch_assoc()['iduser'];
	}
	
	/**
	 * Obtenir de la BD (table user), toutes les entrées avec un niveau de privilège = -1 (admin en chef)
	 */
	public function getAdminId(){
		$sql = "SELECT iduser FROM `user` WHERE privilegelevel = -1";
		$result = $this->conn->query($sql);
		if ($result->num_rows == 0) {
		return False;	
			
		}
		return  $result->fetch_assoc()['iduser'];
	}
	
	/**
	 * Vérifie s'il existe des entrées dans la table user
	 */
	public function isadminaccountexist(){
		$sql = "SELECT * FROM `user`";
		$result = $this->conn->query($sql);
		
		if ($result->num_rows == 0) {
		return False;	
			
		}
		return True;
		
	}
	
	/**
	 * Obtenir de la BD (table userinfo), l'adresse-courriel de validationfeedback du iduser admin
	 */
	public function getAdminMail(){
		
		$adminiduser = $this->getAdminId();
		if ($adminiduser === False) {
			return False;
		}
		$sql = "SELECT validationfeedbackemail FROM `userinfo` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $adminiduser);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result->num_rows == 0) {
		return False;

		}
		return $result->fetch_assoc()['validationfeedbackemail'];
		
	}
	
	/**
	 * Ajouter/modifier les données d'un iduser (table user) selon qu'il existe déjà ou non
	 */
	public function createormodifyuser($username,$password,$privilegelevel=10,$iduser=null){
		if(empty($username)||empty($password)){
			echo _("Nom d'utilisateur ou mot de passe vide !!");
			return;
		}
		$privilegelevel = intval($privilegelevel);
		if (is_null($iduser))
		{
			$sql = "INSERT INTO user (username,password,privilegelevel) VALUES (?,?,?)";
			$stmt = $this->conn->prepare($sql);
			$stmt->bind_param("ssi", $username, $password, $privilegelevel);
		}else{
			$iduser = intval($iduser);
			$sql = "UPDATE user SET username = ?, password = ?, privilegelevel = ? WHERE iduser = ?";
			$stmt = $this->conn->prepare($sql);
			$stmt->bind_param("ssii", $username, $password, $privilegelevel, $iduser);
		}
		if($stmt->execute()){
			echo "Records inserted successfully.";
		} else{
			die (messageErreurTechnique("ERROR: Could not able to execute $sql. " . $this->conn->error));
		}

	}
	/**
	 * Mettre à jour le password d'un iduser 
	 */
	public function updateuserpassword($iduser,$password){
		$iduser = intval($iduser);
		$sql = "UPDATE user SET password = ? WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("si", $password, $iduser);
		if($stmt->execute()){
			echo _("Mot de passe mis à jour.")."<br>";
		} else{
			die (messageErreurTechnique("ERROR: Could not able to execute $sql. " . $this->conn->error));
		}

	}
	
	/**
	 * Mettre à jour le niveau de privilège d'un iduser 
	 */
	public function updateuserprivilegelevel($iduser,$privilegelevel){
		$iduser = intval($iduser);
		$privilegelevel = intval($privilegelevel);
		$currentuserprivilegelevel = $this->getcurrentuserprivilegelevel();
		if($currentuserprivilegelevel >= $privilegelevel){
			if($privilegelevel==-1){
			echo _("Il ne peut n'y avoir qu'un seul administrateur principal.");
			}else{
			echo _("Vous ne pouvez pas changer le niveau de privilège de l'utilisateur, votre propre niveau de privilège(niveau=").$currentuserprivilegelevel._(" ne le permet pas.");
			}
			return;
		}

		$sql = "UPDATE user SET privilegelevel = ? WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("ii", $privilegelevel, $iduser);
		if($stmt->execute()){
			echo _("Niveau de privilege mis à jour.")."<br>";
		} else{
			die (messageErreurTechnique("ERROR: Could not able to execute $sql. " . $this->conn->error));
		}

	}
	
	
	/**
	 * Supprimer les entrées d'un iduser des tables user, userinfo, userservice, usertokenaccess
	 * (et sa liste conservée du groupe de sonnerie)
	 */
	public function deleteuserbyid($iduser){
		if($this->getcurrentuserprivilegelevel()>0){
			echo _("Non autorisé.");
			return;
		}

		$iduser = intval($iduser);
		if($this->getcurrentuserprivilegelevel() == '-1' and $iduser == $this->getcurrentuserid()){
			die(_("Il est impossible de supprimer le compte administrateur !"));
		}

		foreach (["user", "userinfo", "userservice", "usertokenaccess"] as $table) {
			$sql = "DELETE FROM $table WHERE iduser = ?";
			$stmt = $this->conn->prepare($sql);
			$stmt->bind_param("i", $iduser);
			if ($stmt->execute()) {
				echo _("Utilisateur ").$iduser._(" effacé de la table ").$table."<br>";
			} else {
				echo _("Erreur pour effacer l'utilisateur : ") . messageErreurTechnique($this->conn->error);
			}
		}
		$this->oublierListeConservee($iduser);
		$this->oublierAlertesComite($iduser);

	}
	// drop_all_tables() (outil de test qui effaçait TOUTES les tables) a été retiré : son
	// seul appel était déjà commenté, et une fonction de destruction totale de la base n'a
	// pas sa place dans du code déployé en production, même inatteignable.
	/**
	 * Obtenir le iduser de la session courante (utilisateur authentifié)
	 */
	public function getcurrentuserid(){
		if(!isset($_SESSION["iduser"]))
			return False;
		return intval($_SESSION["iduser"]);
	}
	/**
	 * Obtenir de la BD (table user), les données de l'utilisateur de la session courante
	 */
	public function getcurrentuser(){
		$iduser = $this->getcurrentuserid();
		if ($iduser === False)
			return False;
		$sql = "SELECT * FROM `user` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result->num_rows == 0) {
		return False;

		}
		return $result->fetch_assoc();

	}
	/**
	 * Obtenir de la BD (table user), les données du $iduser fourni
	 */
	public function getuserbyid($iduser){
		$iduser = intval($iduser);
		if(!$iduser) return;
		$sql = "SELECT * FROM `user` WHERE iduser = $iduser";
		
		$result = $this->conn->query($sql);
		if ($result->num_rows == 0) {
		return False;	
			
		}
		return $result->fetch_assoc();
		
	}
	/**
	 * Obtenir de la BD (table userinfo), les données du $iduser fourni
	 */
	public function getuserinfobyid($iduser){
		if(!$iduser) return;
		$iduser = intval($iduser);
		$sql = "SELECT * FROM `userinfo` WHERE iduser = ?";
		try {
			$stmt = $this->conn->prepare($sql);
			$stmt->bind_param("i", $iduser);
			$stmt->execute();
			$result = $stmt->get_result();
		}
		catch (mysqli_sql_exception $e) {
			echo "Tentative de reconnexion suite à l'exception : " . $e->getMessage() . "<br>\n";
			if ($this->refreshdbconnexion()) {
				try {
						$stmt = $this->conn->prepare($sql);
						$stmt->bind_param("i", $iduser);
						$stmt->execute();
						$result = $stmt->get_result();
				}
				catch (mysqli_sql_exception $e2) {
					echo "Échec de la requête après reconnexion : " . $e2->getMessage() . "<br>\n";
					return False;
				}
			}
			else return False;
		}
		if (!isset($result) || $result->num_rows == 0) {
				return False;
		}
		$row = $result->fetch_assoc();
		$row['voipmsapipassword'] = $this->cryptomanager->decrypt($row['voipmsapipassword']);
		$row['calendarpassword'] = $this->cryptomanager->decrypt($row['calendarpassword']);
		return $row;
	}

	/**
	 * Obtenir de la BD, le niveau de privilège du user courant
	 */
	public function getcurrentuserprivilegelevel(){
		if(empty($_SESSION['authenticated']) || !isset($_SESSION['iduser']))
			die(_("Utilisateur non Authentifié !"));

		$iduser = intval($_SESSION["iduser"]);
		$sql = "SELECT privilegelevel FROM `user` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result->num_rows == 0) {
			return False;
		}
		return $result->fetch_assoc()['privilegelevel'];
	}

	/**
	 * Obtenir de la BD, la table userservice
	 */
	public function getuserservicetable(){
		$sql = "SELECT * FROM `userservice`";
		$result = $this->conn->query($sql);
		if ($result->num_rows == 0) {
			return False;	
		}
		return $result;
		
	}
	/**
	 * Extraire de la BD, les informations du user pour affichage à l'écran éventuellement
	 */
	public function filluserinformationsfromdb($userinformations,$iduser=-1,$superprivilege=False){
		$iduser = intval($iduser);
		$is_user_itself = $iduser == $this->getcurrentuserid();
		if (!$superprivilege){
			if (empty($_SESSION['authenticated'])){
				return ;
			}
			if($iduser==-1){
				$iduser = $this->getcurrentuserid();	
			}else{
				if(!$is_user_itself && $this->getcurrentuserprivilegelevel()>0){
					echo "Vous n'êtes pas alloué à faire les changements d'information d'autres utilisateurs.";
					return;
				}
			}
		} else if ($iduser==-1){
			echo "Identifiant utilisateur non spécifié !";
			return;
		}
		
		$sql = "SELECT * FROM `userinfo` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();
		// S'il n'y a aucun résultat, on créé une entrée vide
		// If there is no result, create an empty entry
		if ($result->num_rows == 0) {
			$sql = "INSERT INTO `userinfo` (iduser, voipmsusername, voipmsapipassword,calendarurl,validationfeedbackemail,ringgroupname,didnumber,language)
			VALUES (?,'','','','','','',?)";
			$insertstmt = $this->conn->prepare($sql);
			$insertstmt->bind_param("is", $iduser, $userinformations->defaultlanguage);
			$insertstmt->execute();

			$userinformations->voipmsusername = "";
			$userinformations->voipmsapipassword = "";
			$userinformations->calendarurl = "";
			$userinformations->calendartype = "secreturl";
			$userinformations->calendarusername = "";
			$userinformations->calendarpassword = "";
			$userinformations->validationfeedbackemail = "";
			$userinformations->ringgroupname = "";
			$userinformations->didnumber = "";
			$userinformations->language = $userinformations->defaultlanguage;
			$userinformations->ringtime = null;
			$userinformations->pressone = null;
			return ;	
		}
		$row = $result->fetch_assoc();

		$userinformations->voipmsusername = $row['voipmsusername'];
		$userinformations->voipmsapipassword = $this->cryptomanager->decrypt($row['voipmsapipassword']);
		$userinformations->calendarurl = $row['calendarurl'];
		$userinformations->calendartype = $row['calendartype'] ?? 'secreturl';
		$userinformations->calendarusername = $row['calendarusername'] ?? '';
		$userinformations->calendarpassword = $this->cryptomanager->decrypt($row['calendarpassword']) ?? '';
		$userinformations->validationfeedbackemail = $row['validationfeedbackemail'];
		$userinformations->ringgroupname = $row['ringgroupname'];
		$userinformations->didnumber = $row['didnumber'];
		$userinformations->language = $row['language'];
		$userinformations->ringtime = isset($row['ringtime']) ? intval($row['ringtime']) : null;
		$userinformations->pressone = isset($row['pressone']) ? intval($row['pressone']) : null;
		$user = $this->getuserbyid($iduser);
		$userinformations->username = $user['username'];
		
		
	}
	/**
	 * Inscrire les informations du user dans la BD à partir des données saisies à l'écran
	 */
	public function filldbfromuserinformations($userinformations,$iduser=-1){
		if (empty($_SESSION['authenticated'])){
			die(_("Utilisateur non-authentifié"));
		}
		$iduser = intval($iduser);
		$is_user_itself = $iduser == $this->getcurrentuserid();

		if($iduser==-1){
			$iduser = $this->getcurrentuserid();
		}else{
			if(!$is_user_itself && $this->getcurrentuserprivilegelevel()>0){
				echo _("Vous n'êtes pas alloué à faire les changements d'information d'autres utilisateurs.");
				return;
			}
		}

		// bind_param exige des variables (passage par référence) : on chiffre d'abord
		// dans une variable locale plutôt que d'appeler encrypt() directement en argument.
		$voipmsapipasswordChiffre = $this->cryptomanager->encrypt($userinformations->voipmsapipassword);
		$calendarpasswordChiffre = $this->cryptomanager->encrypt($userinformations->calendarpassword);

		$sql = "UPDATE `userinfo`
			SET voipmsusername = ? ,
				voipmsapipassword = ?,
				calendarurl = ?,
				calendartype = ?,
				calendarusername = ?,
				calendarpassword = ?,
				validationfeedbackemail = ?,
				ringgroupname = ?,
				didnumber = ?,
				language = ?,
				ringtime = ?,
				pressone = ?
			WHERE iduser=?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("ssssssssssiii",
			$userinformations->voipmsusername,
			$voipmsapipasswordChiffre,
			$userinformations->calendarurl,
			$userinformations->calendartype,
			$userinformations->calendarusername,
			$calendarpasswordChiffre,
			$userinformations->validationfeedbackemail,
			$userinformations->ringgroupname,
			$userinformations->didnumber,
			$userinformations->language,
			$userinformations->ringtime,
			$userinformations->pressone,
			$iduser
		);
		$stmt->execute();
		// Compte voip.ms, nom du groupe, DID ou langue ont pu changer : le prochain passage
		// renvoie tout à voip.ms plutôt que de se fier à la liste conservée.
		$this->oublierListeConservee($iduser);
		echo "<div class='updatemessage'>"._("Base de données mise à jour")."</div>";

	}
	/**
	 * Obtenir la liste des users
	 */
	public function getuserslist(){
		$sql = "SELECT iduser,username,privilegelevel FROM `user`";
		$result = $this->conn->query($sql);

		if ($result->num_rows > 0) {
			return $result;
		} 
		else {
			echo _("Aucun utilisateur");
		}
		
	}
	/**
	 * Obtenir les abonnements aux services de la table userservice pour le $iduser recu en paramètres
	 * si le user n'existe pas dans la table, alors on créé une entrée pour ce user avec la valeur False 
	 * pour chacun des services
	 * paramètres: $iduser
	 * 
	 */
	public function getUserServiceValuesByIduser($iduser){
		$iduser = intval($iduser);
		// Créé une entrée vide s'il y en a aucune
		// Create a new entry if there isn't any
		$sql = "INSERT IGNORE INTO userservice
				SET iduser = ?,
				ringgroupupdate = false,
				smsdaybefore = false,
				smshourbefore = false,
				sendstats = false";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		// Va chercher le contenu de userservice et retourne sour forme de array associatif
		// Fetch userservice content has a associative array
		$sql = "SELECT * FROM userservice WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();

		if ($result->num_rows > 0) {
			return $result->fetch_assoc();
			
		} else {
			echo _("Aucun utilisateur");
		}
		
	}
	/**
	 * Inscrit les abonnements aux services à la table userservice pour le $iduser recu en paramètres
	 * les valeurs à inscrire pour chaque service sont reçus en paramètre
	 * Un compte principal n'a pas d'agenda ni de groupe de sonnerie : seules les statistiques
	 * mensuelles lui sont permises, les autres services sont donc mis à 0 ici, quoi que le
	 * formulaire ait envoyé.
	 */
	public function setUserServiceValuesByIduser($iduser,$ringgroupupdate,$smsdaybefore,$smshourbefore,$sendstats,$sbchatupdate,$mainaccount){
		$iduser = intval($iduser);
		$ringgroupupdate = intval($ringgroupupdate);
		$smsdaybefore = intval($smsdaybefore);
		$smshourbefore = intval($smshourbefore);
		$sendstats = intval($sendstats);
		$sbchatupdate = intval($sbchatupdate);
		$mainaccount = intval($mainaccount);
		if ($mainaccount == 1) {
			$ringgroupupdate = 0;
			$smsdaybefore = 0;
			$smshourbefore = 0;
			$sbchatupdate = 0;
		}
		$sql = "UPDATE userservice
				SET ringgroupupdate = ?,
				smsdaybefore = ?,
				smshourbefore = ?,
				sendstats = ?,
				sbchatupdate = ?,
				mainaccount = ?
				WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("iiiiiii", $ringgroupupdate, $smsdaybefore, $smshourbefore, $sendstats, $sbchatupdate, $mainaccount, $iduser);
		if($stmt->execute()){
			// Pendant une pause du service, voip.ms a pu être modifié à la main : à sa reprise,
			// tout renvoyer plutôt que de se fier à la liste conservée.
			$this->oublierListeConservee($iduser);
			echo _("Mis à jour avec succès.");
		} else{
			die (_("Erreur : incapable d'exécuter ").messageErreurTechnique($sql. " ". $this->conn->error));
		}


	}
	/**
	 * Type de compte de $iduser, pour savoir quoi mettre dans ses rapports mensuels (voir
	 * typeDeCompte() pour la règle).
	 */
	public function getTypeCompte(int $iduser): string {
		$stmt = $this->conn->prepare("SELECT mainaccount FROM userservice WHERE iduser = ?");
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$ligne = $stmt->get_result()->fetch_assoc();
		$estComptePrincipal = ($ligne !== null && $ligne['mainaccount'] == 1);

		// Autres users (comités locaux ou compte principal) qui ont le même nom d'utilisateur
		// voip.ms. La collation de la table ignore les majuscules ; TRIM ignore les espaces
		// saisis par erreur.
		$sql = "SELECT COUNT(*) AS nombre
				FROM userinfo AS moi
				JOIN userinfo AS autre
					ON TRIM(autre.voipmsusername) = TRIM(moi.voipmsusername)
					AND autre.iduser <> moi.iduser
				WHERE moi.iduser = ?
					AND TRIM(moi.voipmsusername) <> ''";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$nombreAutresUsers = $stmt->get_result()->fetch_assoc()['nombre'];

		return self::typeDeCompte($estComptePrincipal, $nombreAutresUsers);
	}
	/**
	 * La règle des types de compte (lexique choisi le 2026-11-02) :
	 * - COMPTE_PRINCIPAL : la case « Compte principal » est cochée (page des services) ;
	 * - COMITE_MEMBRE : un autre user, comité local ou compte principal, utilise le même compte
	 *   voip.ms ;
	 * - COMITE_AUTONOME : aucun autre user n'utilise ce compte voip.ms.
	 * Un comité local est donc membre ou autonome, jamais les deux. Séparée de getTypeCompte()
	 * pour être testée sans base de données (tests/rapportcomite_test.php).
	 */
	public static function typeDeCompte(bool $estComptePrincipal, int $nombreAutresUsers): string {
		if ($estComptePrincipal) {
			return self::COMPTE_PRINCIPAL;
		}
		return $nombreAutresUsers > 0 ? self::COMITE_MEMBRE : self::COMITE_AUTONOME;
	}
	/**
	 * Liste conservée du groupe de sonnerie d'un comité (issue #63) : ce qui a été envoyé avec
	 * succès à voip.ms au dernier passage. Retourne null s'il n'y en a pas (premier passage,
	 * réglages modifiés) : l'appelant fait alors une resynchronisation complète.
	 * Les erreurs SQL retournent aussi null, p. ex. si le code est à jour mais pas encore la BD :
	 * on retombe sur le comportement d'avant la liste conservée, sans bloquer le comité.
	 */
	public function getListeConservee($iduser){
		$iduser = intval($iduser);
		try {
			$stmt = $this->conn->prepare("SELECT * FROM ringgroupstate WHERE iduser = ?");
			$stmt->bind_param("i", $iduser);
			$stmt->execute();
			$etat = $stmt->get_result()->fetch_assoc();
			if ($etat === null) {
				return null;
			}
			$stmt = $this->conn->prepare("SELECT phonenumber, forwardingid, ringtime, pressone FROM ringgroupmember WHERE iduser = ? ORDER BY position");
			$stmt->bind_param("i", $iduser);
			$stmt->execute();
			$etat['membres'] = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
			foreach ($etat['membres'] as &$membre) {
				$membre['ringtime'] = intval($membre['ringtime']);
				$membre['pressone'] = intval($membre['pressone']);
			}
			unset($membre);
			return $etat;
		} catch (mysqli_sql_exception $e) {
			echo _("Liste conservée illisible : ").messageErreurTechnique($e->getMessage())."\n";
			return null;
		}
	}
	/**
	 * Remplace la liste conservée d'un comité, en une transaction. $membres est une liste
	 * ordonnée de ['phonenumber' => ..., 'forwardingid' => ..., 'ringtime' => ..., 'pressone' => ...]. Si $resynchronisationComplete
	 * est faux, la date de la dernière resynchronisation complète est gardée telle quelle.
	 */
	public function enregistrerListeConservee($iduser, $voipmsusername, $ringgroupname, array $membres, bool $resynchronisationComplete){
		$iduser = intval($iduser);
		$maintenant = date("Y-m-d H:i:s");
		try {
			$this->conn->begin_transaction();
			if ($resynchronisationComplete) {
				$sql = "REPLACE INTO ringgroupstate (iduser, voipmsusername, ringgroupname, lastupdate, lastfullsync) VALUES (?, ?, ?, ?, ?)";
				$stmt = $this->conn->prepare($sql);
				$stmt->bind_param("issss", $iduser, $voipmsusername, $ringgroupname, $maintenant, $maintenant);
			} else {
				$sql = "UPDATE ringgroupstate SET lastupdate = ? WHERE iduser = ?";
				$stmt = $this->conn->prepare($sql);
				$stmt->bind_param("si", $maintenant, $iduser);
			}
			$stmt->execute();

			$stmt = $this->conn->prepare("DELETE FROM ringgroupmember WHERE iduser = ?");
			$stmt->bind_param("i", $iduser);
			$stmt->execute();

			$stmt = $this->conn->prepare("INSERT INTO ringgroupmember (iduser, position, phonenumber, forwardingid, ringtime, pressone) VALUES (?, ?, ?, ?, ?, ?)");
			foreach ($membres as $position => $membre) {
				$stmt->bind_param("iissii", $iduser, $position, $membre['phonenumber'], $membre['forwardingid'], $membre['ringtime'], $membre['pressone']);
				$stmt->execute();
			}
			$this->conn->commit();
		} catch (mysqli_sql_exception $e) {
			// Sans liste conservée, le prochain passage fera une resynchronisation complète
			$this->conn->rollback();
			$this->oublierListeConservee($iduser);
			echo _("Liste conservée non enregistrée : ").messageErreurTechnique($e->getMessage())."\n";
		}
	}
	/**
	 * Efface la liste conservée d'un comité : son prochain passage fera une resynchronisation
	 * complète. À appeler dès qu'un réglage qui touche voip.ms change.
	 */
	public function oublierListeConservee($iduser){
		$iduser = intval($iduser);
		foreach (["ringgroupstate", "ringgroupmember"] as $table) {
			try {
				$stmt = $this->conn->prepare("DELETE FROM $table WHERE iduser = ?");
				$stmt->bind_param("i", $iduser);
				$stmt->execute();
			} catch (mysqli_sql_exception $e) {
				// Table absente (BD pas encore à jour) : il n'y a rien à oublier
			}
		}
	}
	/**
	 * La mémoire des alertes d'un comité, pour un problème (voir MemoireAlertes) : ['passagesdesuite', 'dernierenvoi'].
	 * Sans ligne, le compteur vaut 0 et la date null. Retourne null si la lecture échoue : BD en panne, ou code à jour
	 * mais pas encore la BD. Une alerte ne doit jamais bloquer un comité, d'où le catch ici et dans les méthodes suivantes.
	 */
	public function lireAlerteComite(int $iduser, string $probleme): ?array {
		try {
			$stmt = $this->conn->prepare("SELECT passagesdesuite, dernierenvoi FROM alertecomite WHERE iduser = ? AND probleme = ?");
			$stmt->bind_param("is", $iduser, $probleme);
			$stmt->execute();
			return $stmt->get_result()->fetch_assoc() ?? ['passagesdesuite' => 0, 'dernierenvoi' => null];
		} catch (mysqli_sql_exception $e) {
			error_log("Mémoire des alertes illisible : ".$e->getMessage());
			return null;
		}
	}
	public function noterEnvoiAlerteComite(int $iduser, string $probleme, string $date): void {
		try {
			$stmt = $this->conn->prepare("INSERT INTO alertecomite (iduser, probleme, dernierenvoi) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE dernierenvoi = VALUES(dernierenvoi)");
			$stmt->bind_param("iss", $iduser, $probleme, $date);
			$stmt->execute();
		} catch (mysqli_sql_exception $e) {
			error_log("Mémoire des alertes non écrite : ".$e->getMessage());
		}
	}
	/**
	 * Ajoute un passage en échec et retourne le nombre de passages de suite, ou null si la mémoire est illisible.
	 */
	public function compterPassageAlerteComite(int $iduser, string $probleme): ?int {
		try {
			$stmt = $this->conn->prepare("INSERT INTO alertecomite (iduser, probleme, passagesdesuite) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE passagesdesuite = passagesdesuite + 1");
			$stmt->bind_param("is", $iduser, $probleme);
			$stmt->execute();
		} catch (mysqli_sql_exception $e) {
			error_log("Mémoire des alertes non écrite : ".$e->getMessage());
			return null;
		}
		$alerte = $this->lireAlerteComite($iduser, $probleme);
		return $alerte === null ? null : intval($alerte['passagesdesuite']);
	}
	public function remettreAlerteComiteAZero(int $iduser, string $probleme): void {
		try {
			$stmt = $this->conn->prepare("UPDATE alertecomite SET passagesdesuite = 0 WHERE iduser = ? AND probleme = ?");
			$stmt->bind_param("is", $iduser, $probleme);
			$stmt->execute();
		} catch (mysqli_sql_exception $e) {
			error_log("Mémoire des alertes non écrite : ".$e->getMessage());
		}
	}
	private function oublierAlertesComite(int $iduser): void {
		try {
			$stmt = $this->conn->prepare("DELETE FROM alertecomite WHERE iduser = ?");
			$stmt->bind_param("i", $iduser);
			$stmt->execute();
		} catch (mysqli_sql_exception $e) {
			// Table absente (BD pas encore à jour) : il n'y a rien à oublier
		}
	}
	/**
	 * Identifiants des renvois que les AUTRES comités du même compte voip.ms ont dans leur liste
	 * conservée. Un même numéro n'a qu'un renvoi par compte voip.ms : quand deux comités ont le
	 * même répondant, ils partagent ce renvoi, et aucun des deux ne doit l'effacer tant que
	 * l'autre s'en sert.
	 */
	public function getRenvoisUtilisesAilleurs($iduser, $voipmsusername){
		$iduser = intval($iduser);
		try {
			$sql = "SELECT DISTINCT m.forwardingid
				FROM ringgroupmember m
				JOIN ringgroupstate s ON s.iduser = m.iduser
				WHERE s.voipmsusername = ? AND m.iduser <> ?";
			$stmt = $this->conn->prepare($sql);
			$stmt->bind_param("si", $voipmsusername, $iduser);
			$stmt->execute();
			return array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'forwardingid');
		} catch (mysqli_sql_exception $e) {
			return [];
		}
	}
	public function createOrUpdateUserToken($iduser,$token){
		$iduser = intval($iduser);
		// Validité d'un an : ce lien est destiné à un usage répété sans authentification
		// (validation de calendrier), pas à une action ponctuelle ; un an borne le risque
		// d'un lien oublié/fuité sans casser l'usage normal. createTokenIfNotExist() en
		// génère un nouveau (avec une nouvelle expiration) une fois celle-ci passée.
		$expiration = date('Y-m-d H:i:s', strtotime('+1 year'));
		$sql = "REPLACE INTO usertokenaccess
				SET iduser = ?,
				token = ?,
				expiration = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("iss", $iduser, $token, $expiration);
		if($stmt->execute()){
			echo _("Token créé ou mis à jour.");
		} else{
			die (_("Erreur : incapable d'exécuter ").messageErreurTechnique($sql." " . $this->conn->error));
		}

	}


	public function getUserTokenFromIduser($iduser){
		$iduser = intval($iduser);
		$sql = "SELECT token FROM `usertokenaccess` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();

		if ($result->num_rows == 0) {
		return False;

		}
		return $result->fetch_assoc()['token'];
	}

	/**
	 * Retourne True si un token valide (non expiré) existe pour cet utilisateur. Un token
	 * expiré est traité comme inexistant, pour que createTokenIfNotExist() en génère un
	 * nouveau plutôt que de laisser l'utilisateur bloqué avec un lien mort.
	 */
	public function isUserTokenExist($iduser){
		$iduser = intval($iduser);
		$sql = "SELECT expiration FROM `usertokenaccess` WHERE iduser = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("i", $iduser);
		$stmt->execute();
		$result = $stmt->get_result();

		if ($result->num_rows == 0) {
		return False;

		}
		$row = $result->fetch_assoc();
		// expiration NULL = token créé avant l'ajout de cette colonne (déploiement
		// existant) : traité comme valide jusqu'à sa prochaine régénération.
		if ($row['expiration'] !== null && strtotime($row['expiration']) < time()) {
			return False;
		}
		return True;


	}
	public function getIduserFromToken($token){
		$sql = "SELECT iduser, expiration FROM `usertokenaccess` WHERE token = ?";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("s", $token);
		$stmt->execute();
		$result = $stmt->get_result();
		if ($result->num_rows == 0) {
		echo _("Aucune correspondance avec le token.");
		return False;

		}
		$row = $result->fetch_assoc();
		// expiration NULL = token créé avant l'ajout de cette colonne : traité comme
		// valide jusqu'à sa prochaine régénération (voir isUserTokenExist()).
		if ($row['expiration'] !== null && strtotime($row['expiration']) < time()) {
			echo _("Ce lien de validation a expiré. Veuillez vous reconnecter pour en obtenir un nouveau.");
			return False;
		}
		return $row['iduser'];

	}
	// SbChat ici
	public function getSbchatinfobyId($iduser){
		if(!$iduser) return;
		$iduser = intval($iduser);
		$conn = $this->create_new_conn();
		$sql = "SELECT * FROM `sbchat` WHERE iduser = ?";
		try {
			$stmt = $conn->prepare($sql);
			$stmt->bind_param("i", $iduser);
			$stmt->execute();
			$result = $stmt->get_result();
		}
		catch (mysqli_sql_exception $e) {
			echo "Tentative de reconnexion suite à l'exception : " . $e->getMessage() . "<br>\n";
			if ($this->refreshdbconnexion()) {
				try {
						$stmt = $this->conn->prepare($sql);
						$stmt->bind_param("i", $iduser);
						$stmt->execute();
						$result = $stmt->get_result();
				}
				catch (mysqli_sql_exception $e2) {
					echo "Deuxième essai.<br>\n";
					echo "Échec de la requête après reconnexion : " . $e2->getMessage() . "<br>\n";
					return False;
				}
			}
			else return False;
		}
		if (!isset($result) || $result->num_rows == 0) {
				return False;
		}
		$row = $result->fetch_assoc();
		$row['sbchatapitoken'] = $this->cryptomanager->decrypt($row['sbchatapitoken']);
		$row['masclientid'] = $this->cryptomanager->decrypt($row['masclientid']);
		$row['massecret'] = $this->cryptomanager->decrypt($row['massecret']);
		return $row;

	}
	public function setsbchatinfobyuserid($iduser,$infoarray){
		if (empty($_SESSION['authenticated'])){
			die(_("Utilisateur non-authentifié"));
		}
		$iduser = intval($iduser);
		$is_user_itself = $iduser == $this->getcurrentuserid();

		if($iduser==-1){
			$iduser = $this->getcurrentuserid();
		}else{
			if(!$is_user_itself && $this->getcurrentuserprivilegelevel()>0){
				echo _("Vous n'êtes pas alloué à faire les changements d'information d'autres utilisateurs.");
				return;
			}
		}
		// Chiffrement des secrets avant écriture (les autres champs restent en clair).
		$infoarray['sbchatapitoken'] = $this->cryptomanager->encrypt($infoarray['sbchatapitoken']);
		$infoarray['masclientid'] = $this->cryptomanager->encrypt($infoarray['masclientid']);
		$infoarray['massecret'] = $this->cryptomanager->encrypt($infoarray['massecret']);

		$sql = "INSERT INTO sbchat (iduser, sbchatapitoken, sbchatcalendarurl,sbchatserveraddress,masurl,masclientid,massecret)
				VALUES (?, ?, ?, ?, ?, ?, ?)
				ON DUPLICATE KEY UPDATE
				sbchatapitoken = VALUES(sbchatapitoken),
				sbchatcalendarurl = VALUES(sbchatcalendarurl),
				sbchatserveraddress = VALUES(sbchatserveraddress),
				masurl = VALUES(masurl),
				masclientid = VALUES(masclientid),
				massecret = VALUES(massecret);";
		$stmt = $this->conn->prepare($sql);
		$stmt->bind_param("issssss", $iduser,
			$infoarray['sbchatapitoken'],
			$infoarray['sbchatcalendarurl'],
			$infoarray['sbchatserveraddress'],
			$infoarray['masurl'],
			$infoarray['masclientid'],
			$infoarray['massecret']
		);
		$stmt->execute();
		echo "<div class='updatemessage'>"._("Base de données mise à jour")."</div>";

	}

	/**
	 * Repasse en chiffré toute valeur encore en clair dans userinfo.voipmsapipassword et
	 * sbchat.{sbchatapitoken,masclientid,massecret}. Sans effet si aucune clé n'est configurée.
	 * Prévu pour être appelé une seule fois par le script CLI src/reencryptsecrets.php après
	 * avoir configuré APP_ENCRYPTION_KEY / $appencryptionkey sur un déploiement existant (les
	 * lectures/écritures normales chiffrent déjà au fil de l'eau ; ce script n'est qu'un moyen
	 * de le faire immédiatement pour toutes les lignes plutôt que d'attendre chaque sauvegarde).
	 *
	 * @return int Nombre de lignes mises à jour.
	 */
	public function reencryptAllSecrets(): int {
		if (!$this->cryptomanager->isConfigured()) {
			return 0;
		}
		$nbModifiees = 0;

		$result = $this->conn->query("SELECT iduser, voipmsapipassword, calendarpassword FROM userinfo");
		while ($row = $result->fetch_assoc()) {
			$voipmsapipassword = $row['voipmsapipassword'];
			$calendarpassword = $row['calendarpassword'];
			$modifie = false;
			if ($voipmsapipassword !== '' && !$this->cryptomanager->isEncrypted($voipmsapipassword)) {
				$voipmsapipassword = $this->cryptomanager->encrypt($voipmsapipassword);
				$modifie = true;
			}
			if (!empty($calendarpassword) && !$this->cryptomanager->isEncrypted($calendarpassword)) {
				$calendarpassword = $this->cryptomanager->encrypt($calendarpassword);
				$modifie = true;
			}
			if ($modifie) {
				$iduser = intval($row['iduser']);
				$stmt = $this->conn->prepare("UPDATE userinfo SET voipmsapipassword = ?, calendarpassword = ? WHERE iduser = ?");
				$stmt->bind_param("ssi", $voipmsapipassword, $calendarpassword, $iduser);
				$stmt->execute();
				$nbModifiees++;
			}
		}

		$result = $this->conn->query("SELECT iduser, sbchatapitoken, masclientid, massecret FROM sbchat");
		while ($row = $result->fetch_assoc()) {
			$sbchatapitoken = $row['sbchatapitoken'];
			$masclientid = $row['masclientid'];
			$massecret = $row['massecret'];
			$modifie = false;
			if (!empty($sbchatapitoken) && !$this->cryptomanager->isEncrypted($sbchatapitoken)) {
				$sbchatapitoken = $this->cryptomanager->encrypt($sbchatapitoken);
				$modifie = true;
			}
			if (!empty($masclientid) && !$this->cryptomanager->isEncrypted($masclientid)) {
				$masclientid = $this->cryptomanager->encrypt($masclientid);
				$modifie = true;
			}
			if (!empty($massecret) && !$this->cryptomanager->isEncrypted($massecret)) {
				$massecret = $this->cryptomanager->encrypt($massecret);
				$modifie = true;
			}
			if ($modifie) {
				$iduser = intval($row['iduser']);
				$stmt = $this->conn->prepare("UPDATE sbchat SET sbchatapitoken = ?, masclientid = ?, massecret = ? WHERE iduser = ?");
				$stmt->bind_param("sssi", $sbchatapitoken, $masclientid, $massecret, $iduser);
				$stmt->execute();
				$nbModifiees++;
			}
		}

		return $nbModifiees;
	}

	/**
	 * Vérifie si les tables user* existent !
	 */
	public function doTablesExist(){
		$sql = "SHOW TABLES LIKE 'user'";
		$result = $this->conn->query($sql);
		if (mysqli_fetch_array($result)):
			// La table existe
			return True;
		else:
			return False;
	 	endif;
	}
	/**
	 * Le fichier de la structure figée de la BD (commun/structure-figee-<version>.sql) : sa version, et son SQL.
	 * Une installation neuve part de là, puis reçoit les migrations de dbVersion.json au-dessus de cette version.
	 * Le fichier ne change jamais : un changement de structure s'ajoute comme migration.
	 */
	public static function structureFigee(): array {
		$fichiers = glob(SRC_DIRECTORY.'commun/structure-figee-*.sql') ?: [];
		if (count($fichiers) !== 1 || !preg_match('/structure-figee-(\d+)\.sql$/', $fichiers[0], $morceaux)) {
			throw new RuntimeException("Structure figée de la BD introuvable ou ambiguë (un seul fichier commun/structure-figee-<version>.sql est attendu).");
		}
		return ['version' => (int)$morceaux[1], 'sql' => (string)file_get_contents($fichiers[0])];
	}
	/**
	 * Crée les tables d'une installation neuve : la structure figée, le compteur de BD à sa version, puis les migrations
	 * de dbVersion.json au-dessus de cette version : le code installé attend la BD de sa propre version, et la mise à jour
	 * ne rattrape rien (le code est déjà à jour).
	 * Une erreur n'est pas avalée : une installation à moitié créée doit se voir tout de suite.
	 */
	public function createDatabase(){
		$structure = self::structureFigee();
		// La boucle consomme tous les jeux de résultats (obligatoire avec multi_query pour que la connexion reste utilisable)
		$this->conn->multi_query($structure['sql']);
		do {
			if ($resultat = $this->conn->store_result()) {
				$resultat->free();
			}
		} while ($this->conn->next_result());
		$requete = $this->conn->prepare("INSERT INTO parameters (paramname, paramvalue) VALUES ('db version', ?)");
		$version = (string)$structure['version'];
		$requete->bind_param('s', $version);
		$requete->execute();
		$this->appliquerMigrationsDeLInstallation((int)$structure['version']);
	}

	private function appliquerMigrationsDeLInstallation(int $versionFigee): void {
		$contenu = json_decode((string)file_get_contents(DOSSIER_UPDATEJSON.NOM_FICHIER_DB_VERSION), true);
		if (!is_array($contenu) || !is_array($contenu['versions'] ?? null)) {
			throw new RuntimeException(NOM_FICHIER_DB_VERSION." est illisible : l'installation neuve ne peut pas être terminée.");
		}
		foreach ($contenu['versions'] as $entree) {
			$noVersion = (int)($entree['noVersion'] ?? 0);
			if ($noVersion <= $versionFigee) {
				continue;
			}
			$sql = $entree['sql'] ?? (string)file_get_contents(DOSSIER_UPDATEJSON.($entree['file'] ?? ''));
			$this->appliquerMigration((string)$sql, $noVersion);
		}
	}

}
