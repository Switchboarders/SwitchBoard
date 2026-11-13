<?php 
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

// Le lien de validation sans authentification (cherche ou crée le jeton du comité) : préparé ici, la vue ne fait que l'afficher
ob_start();
$this->webpageparent->tokenaccess->getusertokenlink($currentuserid);
$lienDeValidation = ob_get_clean();

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/userinfoform/userinfoform.vue.php';
