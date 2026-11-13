<?php 
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

// La ligne qui nomme l'utilisateur visé (cherche son nom dans la BD) : préparée ici, la vue ne fait que l'afficher
$ligneNomUtilisateur = ligneNomUtilisateur($this->webpageparent->databasemanager,$_GET['iduser']);

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/userserviceform/userserviceform.vue.php';
