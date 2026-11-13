<?php
ini_set('memory_limit', '512M');
// Affichage des erreurs : voir modeDebugActif() dans commun/lesconstantes.php (ci-dessous).
require_once dirname(__FILE__,1).'/commun/lesconstantes.php';

if(empty($_GET['token'])){
	echo "error token empty";
	return;
	
}
$token = $_GET['token'];
require_once PORTAIL_DIRECTORY.'tokenaccess.php';	
$tokenaccess = new TokenAccess();
include PORTAIL_DIRECTORY.'pages/jeton/header.php';
try {
	$tokenaccess->displayTokenValidationPage($token);
} catch (\Throwable $erreur) {
	echo "<div class='validation-warning'>".htmlspecialchars(TraitementComite::messageErreurPourPage($erreur))."</div>";
}
include PORTAIL_DIRECTORY.'pages/jeton/footer.php';

?>