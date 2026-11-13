<?php
// Seul un POST agit (voir Routes::ACTIONS ; managementportal.php a exigé le jeton). Sa sortie est gardée, puis la page redirige
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
	commencerEnregistrement();
	$this->webpageparent->initiatecalendar();
	ob_start();
	try {
		echo $this->webpageparent->calendarmanager->sendremindersbchat($this->webpageparent->databasemanager->getcurrentuserid(),"hourbefore");
	} catch (\Throwable $erreur) {
		echo htmlspecialchars(TraitementComite::messageErreurPourPage($erreur));
	}
	terminerEnregistrement(['sortie' => ob_get_clean()]);
}

// Un GET n'agit jamais : il montre ce que la dernière action a dit, ou renvoie au tableau de bord
$sortie = resultatDeLAction()['sortie'];

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/remindersbchatjustbeforepage.vue.php';
