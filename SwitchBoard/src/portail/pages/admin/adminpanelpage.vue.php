<section>
<h1><?php echo(_("Console d'administration"));?></h1>
<p><a href="managementportal.php?page=mainpage"><?php echo(_("Voir ma page principale (tableau de bord personnel)"));?></a></p>

<div class="alert <?php echo $modeDebugEstActif ? 'alert-warning' : 'alert-success'; ?>">
	<strong><?php echo(_("Mode debug : "));?></strong>
	<?php echo $modeDebugEstActif ? _("ACTIVÉ - les erreurs PHP (chemins serveur, requêtes SQL) sont affichées à l'écran.") : _("désactivé (recommandé en production)."); ?>
	<?php if ($modeDebugForce): ?>
		<br><?php echo(_("Imposé par la variable d'environnement SB_DEBUG : le bouton ci-dessous est sans effet tant qu'elle est définie."));?>
	<?php else: ?>
		<br>
		<?php echo boutonAction('adminpanel', $modeDebugEstActif ? _("Désactiver le mode debug") : _("Activer le mode debug"), ['champs' => ['action' => 'toggledebug'], 'classe' => $modeDebugEstActif ? 'danger' : '']); ?>
		<br><small><?php echo(_("Le changement prendra effet à partir du prochain chargement de page."));?></small>
	<?php endif; ?>
</div>

<h2><?php echo(_("Liste des utilisateurs :"));?></h2>
<h3><A href='?page=modifyuser'><?php echo(_("Ajouter un utilisateur"));?></A></h3>
<h3><A href='?page=croncommands'><?php echo(_("Suggestions de commandes Cron"));?></A></h3>
<h3><A href='?page=sbchatcertificate'><?php echo(_("Certificat SwitchBoard Chat / MAS"));?></A></h3>
<h3><A href='?page=mailtest'><?php echo(_("Courriel de test"));?></A></h3>
<h3><A href='?page=mailoauth'><?php echo(_("Courriel OAuth"));?></A></h3>
<h3><A href='?page=deadmanswitch'><?php echo(_("Surveillance (dead-man's-switch)"));?></A></h3>
<h3><A href='?page=multiaccountcostreport'><?php echo(_("Comparaison des coûts entre comptes voip.ms"));?></A></h3>
<?php echo $listeDesUtilisateurs; ?>

</section>