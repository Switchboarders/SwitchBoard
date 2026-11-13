<section>
	<div class="alert alert-warning">
		<h2><?php echo(_("Vérification des mises à jour impossible"));?></h2>
		<p>
			<?php echo(_("GitHub n'a pas répondu à la demande de version. Le reste de l'application fonctionne normalement."));?>
		</p>
		<p>
			<?php echo(_("Le dépôt SwitchBoard est public : aucun jeton n'est nécessaire. Les causes habituelles sont une connexion Internet coupée, ou la limite de GitHub pour les demandes sans jeton (60 par heure et par adresse IP) : la vérification reprendra d'elle-même. Pour lever cette limite, ou pour suivre une copie privée du dépôt, fournissez un jeton GitHub en lecture seule de l'une des deux façons suivantes :"));?>
		</p>
		<ul>
			<li><?php echo(_('dans le fichier config/config.php, en donnant une valeur à la variable $githubtoken ;'));?></li>
			<li><?php echo(_("ou par la variable d'environnement SB_GITHUB_TOKEN."));?></li>
		</ul>
		<p>
			<strong><?php echo(_("Important :"));?></strong>
			<?php echo(_("ne replacez jamais un jeton directement dans le code source, et ne le publiez jamais dans un dépôt. Un jeton qui a déjà été publié doit être révoqué sur GitHub, puis remplacé par un nouveau."));?>
		</p>
	</div>
</section>
