<section>
<div class="login">
<div class="login-triangle"></div>
<?php echo boutonRetourConsole(); ?>
<h2 class="login-header"><?php echo(_("Certificat SwitchBoard Chat / MAS"));?></h2>

<?php if ($messageErreur !== ''): ?>
<div class="login-title" style="color:red;"><?php echo htmlspecialchars($messageErreur);?></div>
<?php endif; ?>
<?php if ($messageSucces !== ''): ?>
<div class="login-title" style="color:green;"><?php echo htmlspecialchars($messageSucces);?></div>
<?php endif; ?>

<?php if ($envCaBundleActif): ?>
<div class="alert alert-warning">
	<strong><?php echo(_("Certificat imposé par une variable d'environnement"));?></strong><br>
	<?php echo sprintf(_("La variable d'environnement SB_SBCHAT_CA_BUNDLE est définie (%s) et prend toujours priorité sur tout certificat approuvé depuis cette page : les actions ci-dessous n'auraient aucun effet visible tant qu'elle reste définie, elles sont donc masquées."), "<code>".htmlspecialchars($envCaBundle)."</code>");?>
	<br><?php echo(_("Pour gérer le certificat depuis cette page à la place, retirez SB_SBCHAT_CA_BUNDLE : dans Docker/.env puis relancez ./up.sh si vous utilisez Docker, ou dans la configuration d'environnement de votre serveur web/PHP-FPM sinon."));?>
</div>
<?php endif; ?>

<?php if ($apercu): ?>
<h3><?php echo(_("Aperçu : certificat obtenu, pas encore approuvé"));?></h3>
<p><?php echo sprintf(_("Connexion effectuée à : %s"), "<code>".htmlspecialchars($apercu['hote'].($apercu['port'] ? ':'.$apercu['port'] : ''))."</code>");?></p>
<?php if ($apercu['correspond'] === false): ?>
<div class="alert alert-warning">
	<strong><?php echo(_("⚠ Ce certificat ne semble PAS correspondre au serveur attendu"));?></strong><br>
	<?php echo sprintf(_("Aucun des noms du certificat (sujet ou SAN ci-dessous) ne correspond à « %s ». Cela arrive typiquement quand l'adresse du serveur SwitchBoard Chat n'inclut pas le bon port et se connecte par erreur à un autre service sur le même nom de domaine. Vérifiez l'adresse du serveur (« Informations SwitchBoard Chat du compte ») avant de confirmer — n'approuvez ce certificat que si vous êtes certain qu'il s'agit bien du bon serveur."), htmlspecialchars($apercu['hote']));?>
</div>
<?php elseif ($apercu['correspond'] === true): ?>
<p style="color:green;"><?php echo(_("✔ Ce certificat correspond au nom d'hôte contacté."));?></p>
<?php endif; ?>
<?php afficherDetailsCertificat($apercu['details']); ?>
<form method="post" action="" style="display:inline;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="confirm">
	<input type="hidden" name="certificat_pem" value="<?php echo htmlspecialchars($apercu['pem'], ENT_QUOTES);?>">
	<p><input type="submit" value="<?php echo(_("Oui, approuver ce certificat"));?>"></p>
</form>
<p><a href="?page=sbchatcertificate"><?php echo(_("Annuler"));?></a></p>
<?php endif; ?>

<?php if (!$apercu): ?>
<?php
// Un certificat qui vient d'être approuvé devient forcément le certificat géré par cette page
// (le POST "confirm" n'aurait pas pu atteindre ce point si une variable d'environnement était
// active - voir plus haut). $certificatAffiche/$estGereAffiche unifient les deux cas pour éviter
// de dupliquer l'avertissement ci-dessous.
$certificatAffiche = $certificatRecu ?: $certificatActuel;
$estGereAffiche = $certificatRecu ? true : $estLeCertificatGere;
?>
<?php if ($certificatRecu): ?>
<h3><?php echo(_("Certificat qui vient d'être approuvé"));?></h3>
<?php elseif ($certificatActuel): ?>
<h3><?php echo(_("Certificat actuellement utilisé"));?></h3>
<?php else: ?>
<p><?php echo(_("Aucun certificat n'est actuellement approuvé."));?></p>
<?php endif; ?>
<?php if ($certificatAffiche): ?>
<?php afficherDetailsCertificat($certificatAffiche); ?>
<?php if ($estGereAffiche && !$certificatAffiche['auto_signe']): ?>
<div class="alert alert-warning">
	<strong><?php echo(_("Ce certificat n'est pas auto-signé : l'approbation manuelle n'est plus nécessaire"));?></strong><br>
	<?php echo sprintf(_("Il est émis par %s, une autorité de certification reconnue — c'est-à-dire qu'il serait déjà accepté normalement, sans rien approuver ici. Le garder approuvé manuellement est même contre-productif : approuver un certificat précis fait que SEUL CE FICHIER EXACT est accepté, à la place de la confiance normale du système. Au prochain renouvellement (habituellement 60 à 90 jours pour Let's Encrypt), le nouveau certificat ne correspondra plus à celui-ci et la connexion échouera de nouveau, même si le nouveau certificat est parfaitement valide."), htmlspecialchars($certificatAffiche['emetteur']));?>
	<br><?php echo(_("Il est recommandé de le retirer ci-dessous : SwitchBoard utilisera alors la confiance normale du système, qui continue de fonctionner automatiquement à travers les renouvellements."));?>
	<form method="post" action="" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="supprimer">
		<p><input type="submit" value="<?php echo(_("Supprimer le certificat approuvé"));?>"></p>
	</form>
</div>
<?php elseif ($estGereAffiche): ?>
<form method="post" action="" style="display:inline;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="supprimer">
	<p><input type="submit" value="<?php echo(_("Supprimer le certificat approuvé"));?>"></p>
</form>
<?php endif; ?>
<?php endif; ?>

<?php if (!$envCaBundleActif): ?>
<h3><?php echo(_("Récupérer automatiquement le certificat actuel du serveur"));?></h3>
<?php if (!empty($sbchatserveraddress)): ?>
<p><?php echo sprintf(_("SwitchBoard va se connecter à %s pour voir quel certificat il présente actuellement, et vous montrera ses détails avant de l'approuver — utile quand le certificat existant vient d'expirer et a été renouvelé sur le serveur SBChat/MAS."), htmlspecialchars($sbchatserveraddress));?></p>
<form method="post" action="">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="fetch">
	<p><input type="submit" value="<?php echo(_("Vérifier le certificat du serveur"));?>"></p>
</form>
<?php else: ?>
<p><?php echo(_("Configurez d'abord l'adresse du serveur SwitchBoard Chat (« Informations SwitchBoard Chat du compte ») pour utiliser cette option."));?></p>
<?php endif; ?>

<h3><?php echo(_("Ou coller le certificat manuellement"));?></h3>
<p><?php echo(_("À utiliser si SwitchBoard ne peut pas joindre directement le serveur SBChat/MAS (pare-feu). Collez le contenu du fichier .pem obtenu autrement (ex. : openssl s_client -connect votreserveur:port | openssl x509)."));?></p>
<form method="post" action="">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="paste">
	<p><textarea name="certificat_pem" rows="12" style="width:100%;font-family:monospace;" placeholder="-----BEGIN CERTIFICATE-----&#10;...&#10;-----END CERTIFICATE-----"></textarea></p>
	<p><input type="submit" value="<?php echo(_("Prévisualiser ce certificat"));?>"></p>
</form>
<?php endif; ?>
<?php endif; ?>

</div>
</section>
