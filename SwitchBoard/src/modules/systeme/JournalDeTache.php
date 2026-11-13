<?php

// Le journal d'une tâche, tel que l'ancien code s'en sert : Journal écrit le fichier, et cette classe ajoute les lignes
// gardées en mémoire, l'indentation et le rognage.
class JournalDeTache{
	private $currentlogcontent;
	private $logfor;
	private $databasemanager;
	private $tablevel;
	private $logmanagerpath;
	private Journal $journal;


	function __construct(string $logfor,int $iduser=-1){
		$this->currentlogcontent = "";
		$this->logfor = $logfor;
		$this->databasemanager = new DatabaseManager();
		$this->tablevel = 0;
		// Si aucun utilisateur n'est spécifié ex.: Tâche générale comme refreshallringgroups
		// If no user is specified eg. general task like refreshallringgroups
		if($iduser==-1){
			$username = "";
		}else{
			$user = $this->databasemanager->getuserbyid($iduser);
			// Utilisateur introuvable (ex.: supprimé pendant une tâche batch qui boucle sur plusieurs
			// utilisateurs) : on se rabat sur un nom vide, comme pour iduser=-1, et on continue.
			$username = $user ? $user['username'] : "";
		}
		$this->journal = new Journal($logfor, $iduser, $username);
		$this->logmanagerpath = $this->journal->getChemin();
	}

	function getCurrentlog(){
		return $this->currentlogcontent;
	}
	function islogempty(){
		if(!file_exists($this->logmanagerpath))
			return True;

		if(filesize($this->logmanagerpath)==0)
			return True;

		return False;
	}
	// Le motif couvre aussi les .old.txt de la rotation. Le message de confirmation est celui de l'appelant (la page).
	public static function deletealllogs() {
		array_map('unlink', glob(DOSSIER_JOURNAUX."*.txt") ?: []);
	}

	// Le nom du journal (la tâche qu'il raconte) : la vue s'en sert pour les ancres de sa page
	public function nom(): string {
		return $this->logfor;
	}
	// Le contenu brut du journal, ou null s'il est vide : la vue l'échappe et le met en page. Jamais include()é : un include()
	// exécuterait comme du PHP toute ligne de journal qui contiendrait littéralement "<?php" (ex.: une valeur saisie par un
	// utilisateur ou une réponse d'API externe reprise au journal).
	public function texteDuJournal(): ?string {
		if($this->islogempty()){
			return null;
		}
		return (string)@file_get_contents($this->logmanagerpath);
	}
	function writelinetolog($line){
		$this->currentlogcontent .= $line."\n";
		$this->journal->ecrire($this->displaytabs().$line);
		if ($this->logfor == "genstatistics"):
			echo $this->displaytabs().$line."\n";
		endif;
		return $line;
	}
	function writeseparator(){
		$this->writelinetolog("_______________________________________________________\n\n");
	}
	function trimandcloselog(){
		$this->writeseparator();
		$maxlines = 10000;
		$this->journal->fermer();
		// Coupe le log à la longueur maxlines en enlevant les lignes au début du fichier
		// Trim log at maxlines length deleting lines at the beginning of the file
		//
		// file() peut renvoyer false même si le fichier existait à l'ouverture : il a pu être effacé
		// pendant l'exécution, ou ne jamais s'ouvrir. count(false) lèverait alors une TypeError
		// dans la boucle du cron, et les groupes de sonnerie suivants ne seraient pas traités.
		$lines = @file($this->logmanagerpath);
		if($lines !== false && count($lines)> $maxlines){
			$linesover = abs($maxlines - count($lines));
			$trimmedlines = array_slice($lines,$linesover,count($lines));
			@file_put_contents($this->logmanagerpath, implode("", $trimmedlines));

		}
	}
	function displaytabs(){
		$returnstring = "";
		for ($x = 0; $x < $this->tablevel; $x++){
			$returnstring .= "\t";

		}
		return $returnstring;

	}
	function incrementtab(){
		$this->tablevel += 1;
	}
	function decrementtab(){
		$this->tablevel -= 1;
		if ($this->tablevel < 0)  $this->tablevel = 0;
	}
}
