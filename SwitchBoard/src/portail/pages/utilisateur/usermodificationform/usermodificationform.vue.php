<!DOCTYPE html>
  <html>
    <head>
        <title><?php echo $this->title; ?></title>
        <meta charset="utf-8">
        <meta name="description" content="<?php echo(_("Nouvelle Authentification"));?>">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link rel="stylesheet" type="text/css" href="portail/pages/utilisateur/style.css"  />
    <script>
		function checkPwd(str) {
			if (str.length < 8) {
				return("<?php echo(_("Le mot de passe est trop court. Il doit contenir un minimum de 8 caractères"));?>");
			} else if (str.length > 50) {
				return("<?php echo(_("Le mot de passe est trop long."));?>");
			} else if (str.search(/\d/) == -1) {
				return("<?php echo(_("Le mot de passe doit contenir un chiffre."));?>");
			} else if (str.search(/[a-zA-Z]/) == -1) {
				return("<?php echo(_("Le mot de passe doit contenir des lettres."));?>");
			} 
			return("ok");
		}
		function validateForm(usage) {
		  let username = document.forms["usermodificationform"]["createormodifyusername"].value;
		  let password = document.forms["usermodificationform"]["createormodifypassword"].value;
		  let passwordverification = document.forms["usermodificationform"]["createormodifypasswordverification"].value;
		  
		  if (username.length < 8) {
			alert("<?php echo(_("Le nom d'utilisateur doit contenir au moins 8 caractères"));?>");
			return false;
		  }
		  if (password != passwordverification) {
			alert("<?php echo(_("Les mots de passe ne sont pas identiques."));?>");
			return false;
		  }
		  if (password == "Passwordnotfilled" && usage == "changeuserloginandprivileges"){
			  return true;
		  }
		  let passwordcheck = checkPwd(password);
		 
		  if(passwordcheck != "ok"){
			 alert(passwordcheck);
			return false; 
			  
		  }
		} 
	</script>
	</head>
    	
	<body>
		<div class="login">
		<div class="login-triangle"></div>
		<?php if(isset($this->usage)) echo boutonRetourConsole(); ?>
		<h2 class="login-header"><?php echo $this->title; ?></h2>
		<?php  
		if(isset($this->description)){
			echo "<div class='description-header'><h3>".$this->description."</h3><h5>"._("Un mot de passe valide doit contenir au moins 8 caractères avec des lettres et des chiffres. Le nom d'utilisateur doit aussi contenir 8 caractères.")."</h5></div>\n";
			
			
		}
		
		?>
		<form name="usermodificationform" class="login-container" action=""  onsubmit="return validateForm(<?php if(isset($this->usage)){echo "'".$this->usage."'";}else{echo "''";}?>)" method="post">
			<?php echo csrfField(); ?>
			<p><input name="createormodifyusername" type="text" placeholder="<?php echo(_("Nom d'utilisateur"));?>" value = "<?php echo $this->username;?>" <?php if(isset($this->usage) && $this->usage=="changeuserloginandprivileges") echo "disabled";?>></p>
			<p><input name="createormodifypassword" type="password" placeholder="<?php echo(_("Mot de passe"));?>" value = "<?php echo $this->passwordvalue;?>"></p>
			
			
			
			<p><input name='createormodifypasswordverification' type='password' placeholder='<?php echo(_("Réécrire votre mot de passe"));?>' value = "<?php echo $this->passwordvalue;?>"></p>	
				
			<?php
			if(isset($this->usage) && ($this->usage == "changeuserloginandprivileges" || $this->usage == "adduserloginandprivileges")){
					echo "<p><select name='privilegelevel'>
					<option value='0' ". ($this->privilegelevel == 0 ? "selected" : "" ).">"._("Co-administrateur")."</option>
					<option value='10' " . ($this->privilegelevel == 10 ? "selected" : "").">"._("Utilisateur régulier")."</option>
					<option value='-1' ". ($this->privilegelevel == -1 ? "selected" : "" ).">"._("Administrateur principal")."</option>
					</select></p>";
					
				}
			?>
			
			<p><input type="submit" value="submit"></p>
		  </form>
		</div>

    </body>
  </html>



