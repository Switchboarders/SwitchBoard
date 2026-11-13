<?php
require_once SRC_DIRECTORY.'utils/isHttps.php';
function hashToken($token) {
    // Use SHA-256 and return hexadecimal string
    return hash('sha256', $token);
}
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

$sbchatinfo = $dbmanager->getSbchatinfobyId($currentuserid);
if(!empty($sbchatinfo['sbchatapitoken'])){
$callcenterapitoken = $sbchatinfo['sbchatapitoken'];


}else {
	echo "<H1>"._("Cet utilisateur n'a pas de token SB Chat. Veuillez compléter la configuration du compte pour accéder aux macros.")."</H1>";
	exit(1);
}
if (empty($sbchatinfo['sbchatserveraddress'])){
	echo "<H1>"._("Aucun adresse de serveur SB Chat configurée !")."</H1>";
	exit(1);
}
$sbchat = new ClientSwitchBoardChat($sbchatinfo['sbchatserveraddress']);

if(!empty($_GET['id_macro']) && $_GET['id_macro']==-1) $_POST['id_macro'] = "-1";

if(!empty($_POST['delete']) && $_POST['delete']=='true'){
	requireCsrf();
	commencerEnregistrement();
    $macro_info = [
		"id_macro" => $_POST['id_macro'] ?? '',
		"macro_name" => "",
		"macro_text" => "",
		"macro_tags" => null,
		// Macro tags config non utilisé pour le moment.
		"macro_tags_config" => null,
	];
		$message = $sbchat->update_macro($callcenterapitoken,$macro_info);
		echo "<h2>$message</h2>";
		// Redirection vers la liste des macros (#77) : F5 ne supprimerait pas de nouveau
		terminerEnregistrement();
}elseif (!empty($_POST['id_macro']) && !empty($_POST['macro_name'])){
	requireCsrf();
	commencerEnregistrement();
	$macro_info = [
		"id_macro" => $_POST['id_macro'],
		"macro_name" => $_POST['macro_name'],
		"macro_text" => $_POST['macro_text'],
		"macro_tags" => $_POST['macro_tags'] !== '' ? $_POST['macro_tags'] : null,
		// Macro tags config non utilisé pour le moment.
		"macro_tags_config" => null,
	];
	$message =$sbchat->update_macro($callcenterapitoken,$macro_info);
	echo "<h2>$message</h2>";
	// Redirection vers la liste des macros (#77)
	terminerEnregistrement();
}

$sbchatcallcentermacroslist = $sbchat->list_macros($callcenterapitoken);
if ($sbchatcallcentermacroslist === null){
	// Vraie panne : pas de réponse du serveur SBChat, ou jeton refusé
	echo "<h2 class='alert-error'>"._("Erreur de configuration ou de connexion")."</h2>";
	exit(1);
}
// Un centre d'appel sans macro est normal : la page s'affiche avec « Nouveau macro » et un mot pour le dire
function get_macro_by_id_macro($macroslist,$id_macro){
foreach($macroslist as $macro){
	if($macro['id_macro']==$id_macro)
		return $macro;

}
if($id_macro == -1){
	$empty_macro = [];
	$empty_macro['id_macro'] = "-1";
	$empty_macro['id_call_center'] = null;
	$empty_macro['macro_name'] = "";
	$empty_macro['macro_text'] = "";
	$empty_macro['macro_tags'] = "";
	$empty_macro['macro_tags_config'] = null;

	return $empty_macro;
}
return null;
}

// La macro à modifier (ou à créer : id_macro=-1) ; null si aucune macro ne porte cet identifiant
$modifierUneMacro = !empty($_GET['id_macro']);
$macro = $modifierUneMacro ? get_macro_by_id_macro($sbchatcallcentermacroslist, $_GET['id_macro']) : null;

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/sbchatmacros/sbchatmacrosform.vue.php';
// Cette page ferme la réponse elle-même (comme avant la séparation) : ni pied de page ni rien d'autre après la vue
exit(0);
