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
        <title>Authentification</title>
        <meta charset="utf-8">
        <meta name="description" content="<?php echo(_("Authentification"));?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" type="text/css" href="portail/pages/utilisateur/style.css"  />
    </head>
    <body>
		<div class="login">
		<div class="login-triangle"></div>
		  
		<h2 class="login-header"><?php echo(_("Se connecter"));?></h2>

		<form class="login-container" action=""  method="post">
			<p><input name="username" type="text" placeholder="<?php echo(_("Nom d'utilisateur"));?>"></p>
			<p><input name="password" type="password" placeholder="<?php echo(_("Mot de passe"));?>"></p>
			<p><input type="submit" value="<?php echo(_("Se connecter"));?>"></p>
		  </form>
		</div>

    </body>
  </html>



