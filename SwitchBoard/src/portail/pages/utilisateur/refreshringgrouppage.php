<?php
require_once SRC_DIRECTORY.'utils/verrouexclusif.php';
// Le comité de la session, ou, pour un admin, celui qu'il a choisi dans la console (?iduser=) : managementportal.php a déjà contrôlé le droit
$iduser = empty($_GET['iduser']) ? -1 : intval($_GET['iduser']);

// Seul un POST agit (voir Routes::ACTIONS ; managementportal.php a exigé le jeton). Sa sortie est gardée, puis la page redirige
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	commencerEnregistrement();
	$this->webpageparent->initiatecalendar($iduser);
	ob_start();
	// « Forcer » : on ignore la liste conservée et on renvoie tout à voip.ms
	$verrouComite = null;
	try {
		$cible = $iduser === -1 ? $this->webpageparent->databasemanager->getcurrentuserid() : $iduser;
		// Un seul passage à la fois pour ce comité : le cron (ou un autre clic) le tient peut-être en ce moment
		$verrouComite = VerrouExclusif::pourComite(intval($cible));
		if (!$verrouComite->acquerirSansAttendre()) {
			$verrouComite = null;
			echo _("Une mise à jour de ce groupe de sonnerie est déjà en cours (passage automatique ou autre clic). Réessayez dans une minute.");
		} else {
			$this->webpageparent->calendarmanager->refreshringgroup($cible, true);
		}
	} catch (\Throwable $erreur) {
		echo htmlspecialchars(TraitementComite::messageErreurPourPage($erreur));
	} finally {
		if ($verrouComite !== null) {
			$verrouComite->liberer();
		}
	}
	terminerEnregistrement(['sortie' => str_replace("\n", "<br>\n", ob_get_clean())]);
}

// Un GET n'agit jamais : il montre ce que le dernier « Forcer » a dit, ou renvoie au tableau de bord
$sortie = resultatDeLAction()['sortie'];
// La ligne du nom du comité visé (un admin qui force un autre comité) : préparée ici
$ligneNomUtilisateur = $iduser !== -1 ? ligneNomUtilisateur($this->webpageparent->databasemanager, $iduser) : '';

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/refreshringgrouppage.vue.php';
