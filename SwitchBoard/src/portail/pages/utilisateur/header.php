<?php 
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}

?>
<!DOCTYPE html>
  <html>
    <head>
        <title></title>
        <meta charset="utf-8">
        <meta name="description" content="">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" type="text/css" href="portail/pages/utilisateur/style.css"  />
		<script src="//cdnjs.cloudflare.com/ajax/libs/jquery/2.1.3/jquery.min.js"></script>
		<script src="portail/pages/utilisateur/script.js"></script>
    </head>
    <body>
		<!-- Return to Top Arrow-->
		<a href="#pagetop" id="return-to-top"><i class="icon-chevron-up"></i></a>
		<!-- ICON NEEDS FONT AWESOME FOR CHEVRON UP ICON -->
		<link href="//netdna.bootstrapcdn.com/font-awesome/3.2.1/css/font-awesome.css" rel="stylesheet">

		<?php if($this->webpageparent->databasemanager->getcurrentuserprivilegelevel()<1): ?>
		<a href="managementportal.php?page=adminpanel" id="admin-topright-button"><?php echo(_("Console d'administration"));?></a>
		<?php endif; ?>

		
		  <div class="hamburger">
		  <div class="line"></div>
		  <div class="line"></div>
		  <div class="line"></div>
		</div>
		<div class="menu">
		  <A href="managementportal.php"><div class="menu-item"><?php echo(_("Page Principale"));?></div></A>
		  <div class="menu-item has-submenu"><?php echo(_("Groupes de sonneries"));?></div>
		  	<div class="submenu">
			<A href="managementportal.php?page=validatecalendar"><div class="submenu-item"><?php echo(_("Validation du calendrier"));?></div></A>
		  	<?php echo boutonAction('refreshringgroup', _("Forcer le changement des répondants"), ['forme' => 'menu'])."\n";?>
		  	<?php echo boutonAction('remindersms', _("Rappels par SMS"), ['forme' => 'menu'])."\n";?>
		  	<?php echo boutonAction('remindersmsjustbefore', _("Rappels par SMS 15 minutes avant"), ['forme' => 'menu'])."\n";?>
			</div>
		  <div class="menu-item has-submenu"><?php echo(_("SwitchBoard Chat"));?></div>
		    <div class="submenu">
		    <?php echo boutonAction('refreshsbchat', _("Rafraîchir SBChat"), ['forme' => 'menu'])."\n";?>
		    <?php echo boutonAction('remindersbchat', _("Rappels SwitchBoard Chat pour demain"), ['forme' => 'menu'])."\n";?>
		    <?php echo boutonAction('remindersbchatjustbefore', _("Rappels SwitchBoard Chat dans l'heure d'avant"), ['forme' => 'menu'])."\n";?>
		    <A href="managementportal.php?page=manageuserssbchat"><div class="submenu-item"><?php echo(_("Gérer les utilisateurs du serveur Matrix"));?></div></A>
		  	<A href="managementportal.php?page=sbchatmacros"><div class="submenu-item"><?php echo(_("Macros SwitchBoard Chat du compte"));?></div></A>
		  	<A href="managementportal.php?page=sbchatagreement"><div class="submenu-item"><?php echo(_("Entente de confidentialité des bénévoles"));?></div></A>
		  	<?php if($this->webpageparent->databasemanager->getcurrentuserprivilegelevel()<1): ?>
		  	<A href="managementportal.php?page=sbchatcertificate"><div class="submenu-item"><?php echo(_("Certificat SwitchBoard Chat / MAS"));?></div></A>
		  	<?php endif; ?>

		</div>
		  <A href="managementportal.php?page=mystats"><div class="menu-item"><?php echo(_("Mes statistiques"));?></div></A>
		  <A href="managementportal.php?page=userinfo"><div class="menu-item"><?php echo(_("Informations du compte"));?></div></A>
		  <A href="managementportal.php?page=sbchatinfo"><div class="menu-item"><?php echo(_("Informations SwitchBoard Chat du compte"));?></div></A>

		  <?php if($this->webpageparent->databasemanager->getcurrentuserprivilegelevel()<1): ?>
		  <div class='menu-item'><A href='managementportal.php?page=adminpanel'><?php echo(_("Console d'administration"));?></A></div>
		  <A href="managementportal.php?page=mailtest"><div class="menu-item"><?php echo(_("Courriel de test"));?></div></A>
		  <A href="managementportal.php?page=mailoauth"><div class="menu-item"><?php echo(_("Courriel OAuth"));?></div></A>
		  <A href="managementportal.php?page=deadmanswitch"><div class="menu-item"><?php echo(_("Surveillance (dead-man's-switch)"));?></div></A>
		  <?php endif; ?>
		  <A href="managementportal.php?page=logout"><div class="menu-item"><?php echo _("Se deconnecter");?></div></A>
		  
		</div>
	<div id='pagetop'></div>
		

