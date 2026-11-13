<?php
echo "<br><br><br><br><br><section>\n";
echo boutonRetourConsole();
echo "<h3>"._("Commande de tâche Cron suggèré (fonctionne avec WHC, il est possible que cela diffère) :")."</h3>";
echo "<h3>"._("Pour les mise à jour des groupes de sonnerie(mettre à tous les 10 minutes) :")."</h3>";
if ($dmsRinggroupConfigure) {
	echo "<p>"._("Le dead-man's-switch 'ringgroup' est déjà configuré : la commande ci-dessous n'enverra plus de courriel de sortie généré par cron à chaque exécution (les vrais courriels d'erreur pour un comité en particulier continuent d'être envoyés normalement, tout comme le ping de surveillance).")."</p>";
}
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."refreshallringgroups.php".$suffixeRinggroup."</h4>";
echo "<h3>"._("Pour les mise à jour des notification par texto la veille(mettre une fois par jour à midi) :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."sendnotificationsmss.php \"daybefore\" "."</h4>";
echo "<h3>"._("Pour les mise à jour des notification par texto peu de temps avant le début du chiffre(mettre à toute les heures aux demi-heures) :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."sendnotificationsmss.php \"hourbefore\" "."</h4>";
echo "<h3>"._("La production de statistiques chaque mois :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."genstatistics.php"."</h4>";
echo "<h3>"._("La production du rapport de coûts par DID chaque mois (même échéancier que les statistiques) :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."gendidcostreport.php"."</h4>";
echo "<h3>"._("Pour les mise à jour des groupes de switchboard chat(mettre à tous les 10 minutes) :")."</h3>";
if ($dmsSbchatConfigure) {
	echo "<p>"._("Le dead-man's-switch 'sbchat' est déjà configuré : la commande ci-dessous n'enverra plus de courriel de sortie généré par cron à chaque exécution (les vrais courriels d'erreur pour un utilisateur en particulier continuent d'être envoyés normalement, tout comme le ping de surveillance).")."</p>";
}
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."refreshsbchat.php".$suffixeSbchat."</h4>";
echo "<h3>"._("Pour les mise à jour des notification pour SwitchBoard Chat la veille(mettre une fois par jour à midi) :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."sendnotificationsbchat.php \"daybefore\" "."</h4>";
echo "<h3>"._("Pour les mise à jour des notification pour SwitchBoard Chat peu de temps avant le début du chiffre(mettre à toute les heures aux demi-heures) :")."</h3>";
echo "<h4> ".htmlspecialchars($php)." -q ".SRC_DIRECTORY."sendnotificationsbchat.php \"hourbefore\" "."</h4>";
echo "</section>\n";
