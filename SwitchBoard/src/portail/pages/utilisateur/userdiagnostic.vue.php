<?php

echo "<br><br><br><br><br><section>";
echo boutonRetourConsole();
echo $ligneNomUtilisateur;
echo _("Id de l'utilisateur : ").$infos->iduser;

echo "<h2>"._("Tests liés à la plateforme voip.ms :")."</h2>";
echo _("Nom d'utilisateur voip.ms :").htmlspecialchars($infos->voipmsusername)."<br>";
if ($identifiantsVides) {
	echo _("Le nom d'utilisateur ou le mot de passe API voip.ms est vide : la connexion à voip.ms ne peut pas fonctionner. Remplissez les deux champs ici : ").$lienInfosUtilisateur."<br>";
} else {
	echo $diagnosticVoipms."<br>";
}
echo "<h2>"._("Tests liés à l'agenda :")."</h2>";
if ($agendaVide) {
	echo _("Aucune adresse d'agenda n'est configurée. Ajoutez-la ici : ").$lienInfosUtilisateur."<br>";
} elseif ($erreurAgenda === null) {
	echo _("L'agenda a été téléchargé correctement.")."<br>";
} else {
	echo _("L'adresse vers le calendrier ne répond pas ou n'est pas valide !")."<br>";
	echo _("Voici l'url :").htmlspecialchars($infos->calendarurl)."<br>";
	echo htmlspecialchars($erreurAgenda)."<br>";
}
echo "</section>";
?>
