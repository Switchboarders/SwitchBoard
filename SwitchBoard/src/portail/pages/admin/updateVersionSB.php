<?php
$this->title = _("Créer un nouveau compte");
$this->description = "";
$this->usage = "adduserloginandprivileges";

// Vérification si l'utilisateur a accepté la mise à jour. Pour rejouer une migration en
// développement, corrigez la ligne 'db version' de la table parameters.
if (isset($_POST['update_choice'])) {
    requireAdmin($this->webpageparent->databasemanager);
    requireCsrf();
    if ($_POST['update_choice'] === 'yes') {
        $this->webpageparent->lancerMiseAJour();
    }
    $this->webpageparent->displaysuccesspage("Update refused");
    $this->skipcontent = True;
}
?>
