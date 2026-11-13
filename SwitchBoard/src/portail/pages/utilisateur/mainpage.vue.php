<?php
// Un journal d'exécution mis en page : le texte est échappé, les retours à la ligne et les tabulations deviennent du HTML
if (!function_exists('montrerLeJournal')) {
	function montrerLeJournal(JournalDeTache $journal): void {
		$texte = $journal->texteDuJournal();
		if ($texte === null) {
			echo _("Journal vide");
			return;
		}
		$nom = $journal->nom();
		echo "<br><A href='#".$nom."endlog' id='".$nom."'>"._("Aller à la fin du journal")."</A><br>";
		$string = htmlspecialchars($texte);
		$string = str_replace("\n","<br>\n",$string);
		$string = str_replace("\t","&nbsp;&nbsp;&nbsp;&nbsp",$string);
		echo $string;
		echo "<A id='".$nom."endlog'></A>";
		echo "<br><A href='#".$nom."' id='".$nom."'>"._("Aller au début du journal")."</A><br>";
	}
}
?>
<?php 
echo "<section><h1>"._("Page principale")."</h1>";
echo "<h4>"._("Adresses IP pour configurer la liste blanche de l'API voip.")."</h4><br>";
echo _("Adresse IP du serveur(selon voip.ms, cette adresse est toujours la bonne, mais necessite un compte voip.ms configuré pour fonctionner) : ").$ipSelonVoipms."<br>";
echo _("Adresse IP du serveur(selon le serveur lui-même, cette adresse est rarement valide) :").$ipSelonLeServeur. "<br>";
echo _("Adresse IP du serveur(selon un service externe, cette adresse est fiable, mais peut être en ipv6) :").$ipExterne. "<br>";
echo "<h2>"._("Numéros de transfert actuels (programmés sur voip.ms)")."</h2>";
if ($renvois === []) {
	echo _("Aucun forwarding"."<br>\n");
}
if (empty($renvois)) {
	echo _("Aucun forwardings trouvé.")."<br>\n";
}
foreach ($renvois ?? [] as $renvoi) {
	echo $renvoi['phone_number'] . "<br>\n";
}
echo "<h2>"._("Journaux d'exécution: ")."</h2>";
if($estAdmin){
echo boutonAction('mainpage', _("Effacer tous les journaux d'exécution"), ['champs' => ['action' => 'deletealllogs'], 'confirmation' => _("Ceci supprimera tous les journaux"), 'classe' => 'danger', 'titre' => 'h3']);
}
//<A href=''></A>
echo "<h3>"._("Aller à :")." <A href='#ringgroupupdate'>"._("Journal des groupes de sonneries pour l'utilisateur actuel")."</A></h3>";

if($estAdmin){
	echo "<h3>"._("Aller à :")."<A href='#refreshallringgroups'>"._("Journal des groupes de sonneries pour tous les utilisateurs")."</A></h3>";
}
if(!$remindersmsdaybefore->islogempty()) {
	echo "<h3>"._("Aller à : ")."<A href='#remindersmsdaybefore'>"._("Envoi de notifications SMS le jour d'avant")."</A></h3>";
	
}
if(!$remindersmshourbefore->islogempty()) {
	echo "<h3>"._("Aller à : ")."<A href='#remindersmshourbefore'>"._("Envoi de notifications SMS une heure avant")."</A></h3>";
	
}
echo "<h3>"._("Mise à jour des groupes de sonneries pour l'utilisateur actuel : ")."</h3>";
montrerLeJournal($journalUtilisateur);
// Montre tous les journaux si l'utilisateur est un administrateur
// Display all logs if user is an admin
if($estAdmin){
	echo "<h3>"._("Mise à jour des groupes de sonneries pour tous les utilisateurs: ")."</h3>";
	montrerLeJournal($refreshallringgroupslog);
	
}
if(!$remindersmsdaybefore->islogempty()) {
	echo "<h3>"._("Envoi de notifications SMS le jour d'avant :")."</h3>";
	montrerLeJournal($remindersmsdaybefore);
}
if(!$remindersmshourbefore->islogempty()) {
	echo "<h3>"._("Envoi de notifications SMS une heure avant :")."</h3>";
	montrerLeJournal($remindersmshourbefore);
}

echo "</section>";
?>