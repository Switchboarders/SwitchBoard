<?php
require_once dirname(__FILE__,2).'/commun/lesconstantes.php';

//Affiche, pour un $iduser donné, les statistiques d'appels et la répartition des coûts par DID
//du mois précédent, avec un bouton pour envoyer chaque rapport par courriel à l'utilisateur.
//Utilisé à la fois par la page "mes statistiques" (l'utilisateur courant consulte les siennes)
//et par la page admin équivalente (un admin consulte celles d'un autre utilisateur). Seule
//différence : pour un comité local membre, l'admin voit à l'écran tout le
//compte, alors que le comité (à l'écran comme par courriel) ne voit que son DID.
//
//Envoyer un rapport est un bouton (un POST avec le jeton CSRF), jamais un lien : la page répond par une redirection
//vers elle-même, qui dit une seule fois quel rapport est parti. F5 ou une adresse rouverte n'envoient rien.
function renderStatsReportSection(int $iduser, string $username, string $backLinkParams = ""){

	// Page admin : $backLinkParams est rempli (iduser=...) ; page « mes statistiques » : vide
	$vueAdmin = ($backLinkParams !== "");
	$page = $vueAdmin ? "userstatsreport" : "mystats";
	$optionsDuBouton = $vueAdmin ? ['iduser' => $iduser] : [];

	$dateFrom = date('Y-m-01', strtotime('first day of last month'));
	$dateTo = date('Y-m-t', strtotime('last day of last month'));

	// Envoyer un rapport : le jeton d'abord, l'envoi, puis la redirection vers la page (Post/Redirect/Get)
	$actionDemandee = (string)($_POST['reportaction'] ?? '');
	if (in_array($actionDemandee, ['sendstats', 'senddidcost'], true)) {
		requireCsrf();
		commencerEnregistrement();
		if ($actionDemandee === 'sendstats') {
			(new RapportStatistiques())->processUser($iduser, $dateFrom, $dateTo, true, null);
		} else {
			(new RapportCoutsDid())->processUser($iduser, $dateFrom, $dateTo, true, null, $vueAdmin);
		}
		terminerEnregistrement(['rapportenvoye' => $actionDemandee]);
	}
	// Le rapport que le bouton vient d'envoyer : dit une seule fois, sur la page qui suit la redirection
	$rapportEnvoye = (string)(valeursDEnregistrement()['rapportenvoye'] ?? '');

	echo "<section><h1>"._("Statistiques de ").htmlspecialchars($username)."</h1></section>";
	echo "<p>"._("Période : ").$dateFrom._(" au ").$dateTo."</p>";

	echo "<h2>"._("Statistiques d'appels")."</h2>";
	$statsreportmanager = new RapportStatistiques();
	$sendStats = ($rapportEnvoye === 'sendstats');
	$statsResult = $statsreportmanager->processUser($iduser, $dateFrom, $dateTo, false, null);
	$estComptePrincipal = ($statsResult['typeCompte'] === DatabaseManager::COMPTE_PRINCIPAL);
	if ($sendStats && !$estComptePrincipal):
		echo "<p><em>"._("Courriel envoyé.")."</em></p>";
	endif;
	echo "<div class='formated-output'>".nl2br(htmlspecialchars($statsResult['emailmanager']->getCorpsAffiche()))."</div>";
	// Un compte principal n'a pas de statistiques d'appels : rien à envoyer
	if (!$estComptePrincipal):
		echo boutonAction($page, _("Envoyer ce rapport par courriel"), $optionsDuBouton + ['champs' => ['reportaction' => 'sendstats']]);
	endif;

	echo "<h2>"._("Répartition des coûts par DID")."</h2>";
	$didcostreportmanager = new RapportCoutsDid();
	$sendDidCost = ($rapportEnvoye === 'senddidcost');
	// Juste après un envoi, l'écran montre ce que le comité a reçu : sa vue à lui, pas celle de tout le compte
	$didCostResult = $didcostreportmanager->processUser($iduser, $dateFrom, $dateTo, false, null, $vueAdmin && !$sendDidCost);
	$estMembre = ($didCostResult['typeCompte'] === DatabaseManager::COMITE_MEMBRE);
	if ($sendDidCost):
		echo "<p><em>"._("Courriel envoyé.")."</em></p>";
		if ($vueAdmin && $estMembre):
			echo "<p><em>"._("Voici ce que le comité a reçu (seulement son DID) :")."</em></p>";
		endif;
	elseif ($vueAdmin && $estMembre):
		echo "<p><em>"._("Ce comité local est membre d'un compte principal : vous voyez tout le compte, mais le courriel ne contient que son DID, sans le solde ni les totaux du compte.")."</em></p>";
	endif;
	echo "<div class='formated-output'>".nl2br(htmlspecialchars($didCostResult['emailmanager']->getCorpsAffiche()))."</div>";
	echo boutonAction($page, _("Envoyer ce rapport par courriel"), $optionsDuBouton + ['champs' => ['reportaction' => 'senddidcost']]);
}
?>
