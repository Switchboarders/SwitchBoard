<?php
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/newVersionAvailable.vue.php';
