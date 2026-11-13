<?php
// Bascule du mode debug (affichage des erreurs PHP à l'écran) : voir modeDebugActif() dans
// commun/lesconstantes.php pour le mécanisme complet. Le changement s'applique à la
// PROCHAINE requête (ce fichier a déjà décidé, plus haut dans lesconstantes.php, s'il fallait
// afficher les erreurs pour LA PAGE COURANTE), d'où l'avertissement ci-dessous.
//
// Toggling debug mode (displaying PHP errors on screen): see modeDebugActif() in
// commun/lesconstantes.php for the full mechanism. The change applies on the NEXT request
// (this file's own lesconstantes.php already decided, earlier, whether to display errors for
// THE CURRENT PAGE), hence the note below.
$modeDebugForce = modeDebugForceParEnv();
$modeDebugEstActif = modeDebugActif();

// La liste des utilisateurs (une carte par comité) : préparée ici, la vue ne fait que l'afficher
ob_start();
$this->webpageparent->displayusers();
$listeDesUtilisateurs = ob_get_clean();

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/admin/adminpanelpage.vue.php';
