<section>
<div class="login">
<div class="login-triangle"></div>
<?php echo boutonRetourConsole(); ?>
<h2 class="login-header"><?php echo(_("Surveillance (dead-man's-switch)"));?></h2>

<?php if ($messageErreur !== ''): ?>
<div class="login-title" style="color:red;"><?php echo htmlspecialchars($messageErreur);?></div>
<?php endif; ?>
<?php if ($messageSucces !== ''): ?>
<div class="login-title" style="color:green;"><?php echo htmlspecialchars($messageSucces);?></div>
<?php endif; ?>
<?php if ($messageTest !== ''): ?>
<div class="login-title" style="color:green;"><?php echo htmlspecialchars($messageTest);?></div>
<?php endif; ?>

<p><?php echo(_("Chaque ligne ci-dessous correspond à une chose critique à surveiller. Créez un compte gratuit sur un service de type dead-man's-switch (ex.: healthchecks.io), créez-y un check par ligne, collez son URL de ping ici, puis envoyez un ping de test. Le canal de notification (SMS, Telegram, ntfy, etc.) se configure entièrement chez ce fournisseur, pas ici - voir DEADMANSWITCH-SETUP.md pour le guide complet."));?></p>

<?php foreach ($checks as $check => $libelle): ?>
<?php
	$reglages = reglagesDeadManSwitch($check);
	$geeParEnv = reglageDeadManSwitchForceParEnv($check);
?>
<div style="border:1px solid #ccc;border-radius:6px;padding:1em;margin:1em 0;text-align:left;">
	<h3><?php echo htmlspecialchars($libelle);?></h3>
	<?php if ($reglages['success_url'] !== ''): ?>
	<p style="color:green;"><?php echo(_("Configuré."));?></p>
	<?php else: ?>
	<p style="color:orange;"><?php echo(_("Non configuré - aucune alerte ne sera envoyée pour ce check."));?></p>
	<?php endif; ?>

	<?php if ($geeParEnv): ?>
	<p><em><?php echo(_("Géré par une variable d'environnement (SB_DMS_").strtoupper($check)._("_SUCCESS_URL dans .env) - pour changer, modifiez .env puis redémarrez le conteneur."));?></em></p>
	<?php else: ?>
	<form method="post" action="?page=deadmanswitch">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="enregistrer_deadmanswitch">
		<input type="hidden" name="check" value="<?php echo htmlspecialchars($check);?>">
		<p><label><?php echo(_("URL de ping de succès :"));?><br>
		<input name="success_url" type="url" value="<?php echo htmlspecialchars($reglages['success_url']);?>" placeholder="https://hc-ping.com/xxxxxxxx" style="width:100%;"></label></p>
		<p><label><?php echo(_("URL de ping d'échec (optionnel - par défaut, l'URL ci-dessus + « /fail ») :"));?><br>
		<input name="fail_url" type="url" value="" placeholder="<?php echo($reglages['fail_url'] !== '' ? _('(déjà enregistré - laissez vide pour le conserver)') : '');?>" style="width:100%;"></label></p>
		<p><input type="submit" value="<?php echo(_("Enregistrer"));?>"></p>
	</form>
	<?php if ($reglages['success_url'] !== ''): ?>
	<form method="post" action="?page=deadmanswitch" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="supprimer_deadmanswitch">
		<input type="hidden" name="check" value="<?php echo htmlspecialchars($check);?>">
		<input type="submit" value="<?php echo(_("Retirer"));?>">
	</form>
	<?php endif; ?>
	<?php endif; ?>

	<?php if ($reglages['success_url'] !== ''): ?>
	<form method="post" action="?page=deadmanswitch" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="test_ping_deadmanswitch">
		<input type="hidden" name="check" value="<?php echo htmlspecialchars($check);?>">
		<input type="submit" value="<?php echo(_("Envoyer un ping de test maintenant"));?>">
	</form>
	<?php endif; ?>
</div>
<?php endforeach; ?>

</div>
</section>
