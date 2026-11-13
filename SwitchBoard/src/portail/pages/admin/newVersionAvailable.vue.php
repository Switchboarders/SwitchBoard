
<!DOCTYPE html>
<html lang="fr">
<head>
	<title>Mise à jour disponible</title>
	<meta charset="UTF-8">
	<meta name="description" content="<?php echo("Mise à jour disponible");?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<link rel="stylesheet" type="text/css" href="portail/pages/utilisateur/style.css"  />
</head>
<body>
	<section>
		<div class="update-form">
			<h2>Mise à jour disponible</h2>
			<p>Une nouvelle version du logiciel est disponible. Voulez-vous procéder à la mise à jour ?</p>
			
			<form id="updateForm" method="POST" action="">
				<?php echo csrfField(); ?>
				<div class="btn-group">
					<button type="submit" name="update_choice" value="yes" class="btn btn-primary">
						Oui, mettre à jour
					</button>
					<button type="submit" name="update_choice" value="no" class="btn btn-secondary">
						Non, plus tard
					</button>
				</div>
			</form>
		</div>
	</section>
</body>
</html>
