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
if ($sbchatinfo == false) {
	$sbchatapitoken = "";
	$sbchathashedtoken = "";
	$sbchatcalendarurl = "";
	$sbchatserveraddress = "";
	$masurl = "";
	$masclientid = "";
	$massecret = "";
}else{
	$sbchatapitoken = $sbchatinfo['sbchatapitoken'];
	$sbchathashedtoken = hashToken($sbchatapitoken);
	$sbchatcalendarurl = $sbchatinfo['sbchatcalendarurl'];
	$sbchatserveraddress = $sbchatinfo['sbchatserveraddress'];
	$masurl = $sbchatinfo['masurl'];
	$masclientid = $sbchatinfo['masclientid'];
	$massecret = $sbchatinfo['massecret'];

}

// Ce que la vue montre en plus : le droit de demander un jeton, et les deux liens du hook SMS (seulement s'il y a un jeton)
$peutDemanderUnJeton = $dbmanager->getcurrentuserprivilegelevel() <= 0;
$voipmshook = $voipmshooktest = '';
if(!empty($sbchathashedtoken)){
	$voipmshook =  $sbchatserveraddress. "/voip_api?to={TO}&from={FROM}&message={MESSAGE}&files={MEDIA}&id={ID}&date={TIMESTAMP}&sbchatapikey=".$sbchathashedtoken;
	$userinfo = $dbmanager->getuserinfobyid($currentuserid);
	$voipmshooktest = $sbchatserveraddress. "/voip_api?to=".$userinfo['didnumber']. "&from=0000000000&message=".urlencode(_("Ceci est un test"))."&files=none&id=".random_int(1,100000)."&date=251200&sbchatapikey=".$sbchathashedtoken;
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/sbchatinfo/sbchatinfoform.vue.php';
