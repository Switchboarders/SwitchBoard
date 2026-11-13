<div class="sbagreement"><?php /* pas "container" : style.css en fait une case à cocher et cache ses <input> */ ?>
<?php echo boutonRetour($this->webpageparent->databasemanager); ?>
<h1><?php echo _("Entente de confidentialité des bénévoles"); ?></h1>
<?php echo ligneNomUtilisateur($this->webpageparent->databasemanager,$currentuserid); ?>
<?php
if($etat === 'injoignable'){
	echo "<div class='alert alert-error'>"._("Impossible de joindre SwitchBoard Chat. Vérifiez sa configuration et sa version (cette page demande une version récente).")."</div></div>";
	return;
}
if($etat === 'inactive'){
	echo "<div class='alert alert-warning'>"._("L'entente n'est pas encore activée sur le serveur SwitchBoard Chat. Il faut y définir PRIVACY_OFFICER (nom et courriel de la personne responsable de la protection des renseignements personnels) dans le fichier .env, puis redémarrer.")."</div></div>";
	return;
}
?>
<p class="description-header"><?php echo _("Les bénévoles signent directement dans Element, dans leur « Salle Utilisateur » : ils écrivent !entente pour lire l'entente, puis !signer suivi de leur prénom et nom. Ils reçoivent aussitôt un reçu. Ceux qui n'ont pas signé reçoivent l'entente chaque jour à midi."); ?></p>

<div class="<?php echo ($signed === $total && $total > 0) ? 'alert alert-success' : 'alert alert-warning'; ?>">
	<?php echo sprintf(_("%d bénévole(s) sur %d ont signé la version en vigueur (%s)."), $signed, $total, $h($data['version'])); ?>
</div>

<form method="post" action="managementportal.php?page=sbchatagreement&iduser=<?php echo intval($currentuserid); ?>">
	<?php echo csrfField(); ?>
	<input type="hidden" name="send_agreement" value="1">
	<p><button type="submit" class="btn btn-primary"><?php echo $h(_("Envoyer l'entente maintenant à ceux qui ne l'ont pas signée")); ?></button></p>
</form>

<table border="1">
	<tr>
		<th><?php echo _("Bénévole"); ?></th>
		<th><?php echo _("Statut"); ?></th>
		<th><?php echo _("Nom tapé"); ?></th>
		<th><?php echo _("Date de signature"); ?></th>
		<th><?php echo _("Appareil"); ?></th>
		<th><?php echo _("Version signée"); ?></th>
	</tr>
<?php
foreach($lignes as $ligne){
	echo "<tr><td>".$h($ligne[0])."</td><td>".$h($ligne[1])."</td><td>".$h($ligne[2])."</td><td>".$h($ligne[3])."</td><td>".$h($ligne[4])."</td><td>".$h($ligne[5])."</td></tr>\n";
}
?>
</table>
<p><a class="btn btn-secondary" download="<?php echo $h($filename); ?>" href="data:text/csv;charset=utf-8;base64,<?php echo base64_encode($csvdata); ?>"><?php echo _("Exporter le registre (CSV)"); ?></a></p>

<details>
	<summary><?php echo sprintf(_("Texte de l'entente en vigueur (version %s)"), $h($data['version'])); ?></summary>
	<p><?php echo _("Empreinte numérique (SHA-256) du texte :"); ?> <code><?php echo $h($data['sha256']); ?></code></p>
	<pre style="white-space: pre-wrap;"><?php echo $h($data['text_fr']); ?></pre>
	<hr>
	<pre style="white-space: pre-wrap;"><?php echo $h($data['text_en']); ?></pre>
</details>
</div>
