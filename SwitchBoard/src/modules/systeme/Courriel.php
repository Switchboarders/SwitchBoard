<?php
class Courriel{
	// Le second essai a déjà raté une fois dans ce processus : le serveur de courriel est en panne, et attendre encore
	// pour chaque courriel allongerait le passage du cron sans rien régler.
	private static bool $serveurEnPanne = false;

	protected string $defaultemailaddress;
	protected string $messagebody;
	protected string $dernierCorps;
	public DatabaseManager $databasemanager;

	public function __construct(string $emailaddress){
		$this->defaultemailaddress = $emailaddress;
		$this->messagebody = "";
		$this->dernierCorps = "";
		$this->databasemanager = new DatabaseManager();
	}
	public function addlinetomessagebody(string $text,bool $echotext=False){
		$this->messagebody .= $text."\n";
		if($echotext){
			echo $text."\n";
		}
	}
	// Retourne si l'envoi a réussi
	public function sendemail(string $emailtitle): bool {
		return $this->envoyer($this->defaultemailaddress, $emailtitle, $this->messagebody);
	}
	// Retourne si l'envoi a réussi. Une BD en panne ne doit pas arrêter celui qui signale un problème.
	public function sendemailtoadmin(string $emailtitle, int $iduser): bool {
		try {
			$adresse = (string)$this->databasemanager->getAdminMail();
			$username = "Admin";
			if($iduser != -1){
				$username = $this->databasemanager->getuserbyid($iduser)['username'] ?? "";
			}
		} catch (mysqli_sql_exception $erreur) {
			$this->viderCorps();
			$this->noterEchec("", $emailtitle);
			return false;
		}
		return $this->envoyer($adresse, _("Important ! ").$emailtitle, _("Pour l'utilisateur : ").$username."\n".$this->messagebody);
	}

	public function getMessagebody(){
		return $this->messagebody;
	}
	// Une page montre le rapport qu'elle prépare, ou celui qu'elle vient d'envoyer
	public function getCorpsAffiche(): string {
		return $this->messagebody !== "" ? $this->messagebody : $this->dernierCorps;
	}

	// Le corps est vidé dans tous les cas : un courriel ne doit jamais répéter le précédent
	private function envoyer(string $adresse, string $titre, string $corps): bool {
		$this->viderCorps();
		if ($adresse === "") {
			$this->noterEchec($adresse, $titre);
			return false;
		}
		if (@mail($adresse, $titre, $corps)) {
			return true;
		}
		if (!self::$serveurEnPanne) {
			sleep(DELAI_SECOND_ESSAI_COURRIEL);
			if (@mail($adresse, $titre, $corps)) {
				return true;
			}
			self::$serveurEnPanne = true;
		}
		$this->noterEchec($adresse, $titre);
		return false;
	}
	private function viderCorps(): void {
		$this->dernierCorps = $this->messagebody;
		$this->messagebody = "";
	}
	// Jamais le corps du message
	private function noterEchec(string $adresse, string $titre): void {
		(new Journal("courriel"))->ecrire(date('d-m-Y H:i:s')." : envoi impossible à « ".$adresse." » : ".$titre);
	}
}
