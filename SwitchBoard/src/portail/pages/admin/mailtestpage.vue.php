<section>
<div class="login">
<div class="login-triangle"></div>
<?php echo boutonRetourConsole(); ?>
<h2 class="login-header"><?php echo(_("Courriel de test"));?></h2>

<p><?php echo(_("SwitchBoard se sert du courriel pour signaler les erreurs et avertissements affectant le fonctionnement du système (groupes de sonnerie, rappels, etc.). Utilisez ce formulaire pour vérifier que l'envoi fonctionne réellement, sans avoir à ouvrir de terminal."));?></p>

<?php if ($messageErreur !== ''): ?>
<div class="login-title" style="color:red;"><?php echo htmlspecialchars($messageErreur);?></div>
<?php endif; ?>
<?php if ($messageSucces !== ''): ?>
<div class="login-title" style="color:green;"><?php echo htmlspecialchars($messageSucces);?></div>
<?php endif; ?>
<?php if ($sortieDetail !== ''): ?>
<pre style="white-space:pre-wrap;background:#f5f5f5;padding:10px;border-radius:4px;"><?php echo htmlspecialchars($sortieDetail);?></pre>
<?php endif; ?>

<form method="post" action="">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="tester">
	<div class="login-title"><?php echo(_("Adresse de destination :"));?></div>
	<p><input value="<?php echo htmlspecialchars($adresseParDefaut);?>" name="adresse" type="text" placeholder="<?php echo(_("ex: moncourriel@exemple.com"));?>"></p>
	<p><input type="submit" value="<?php echo(_("Envoyer un courriel de test"));?>"></p>
</form>

</div>
</section>
