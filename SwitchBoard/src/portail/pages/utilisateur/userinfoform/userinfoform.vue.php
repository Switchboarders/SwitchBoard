    <script>
		function validateForm() {
		  window.location.reload();

		} 
		
		
	</script>
		<section>
		<div class="login">
		<div class="login-triangle"></div>
		 <?php echo boutonRetour($this->webpageparent->databasemanager); ?>
		<h2 class="login-header"><?php echo(_("Informations du compte"));?></h2>
		<form id="userinfoform" name="userinfoform" class="login-container" action=""  onsubmit="return validateForm()" method="post">
			<?php echo csrfField(); ?>
			<?php echo ligneNomUtilisateur($this->webpageparent->databasemanager,$currentuserid); ?>
			<div class="login-title"><?php echo(_("Nom d'utilisateur voip.ms :"));?></div>
			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->voipmsusername);?>" name="voipmsusername" type="text" placeholder="<?php echo(_("ex.monadressedecomptevoip@gmail.com"));?>"></p>
			<div class="login-title"><?php echo(_("Mot de passe API voip.ms (pas le mot de passe du compte, celui de l'api !!):"));
				echo $this->webpageparent->userinformations->voipmsapipassword !== '' ? " <span class='configured'>&#10003; "._("configuré")."</span>" : " <span class='notconfigured'>"._("non configuré")."</span>";
			?></div>
			<p><input value="" name="voipmsapipassword" type="password" autocomplete="new-password" placeholder="<?php echo(_("laisser vide pour conserver"));?>"></p>
			<div class="login-title"><?php echo(_("URL de l'agenda :"));?></div>
			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->calendarurl);?>" name="calendarurl" type="text" placeholder="ex.https://calendar.google.com/calendar/ical/..."></p>
			<p> <label for="calendartype" class="login-title"><?php echo(_("Type de calendrier :"));?></label>
			<select name="calendartype" id="calendartype" >
			  <option value="secreturl" <?php if ($this->webpageparent->userinformations->calendartype == 'secreturl') echo "selected";?>><?php echo(_("URL secrète (Google / Outlook)"));?></option>
			  <option value="caldav" <?php if ($this->webpageparent->userinformations->calendartype == 'caldav') echo "selected";?>><?php echo(_("CalDAV (compte cPanel avec authentification)"));?></option>
			</select> </p>
			<div class="login-title"><?php echo(_("Nom d'utilisateur CalDAV (requis seulement si Type de calendrier = CalDAV) :"));?></div>
			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->calendarusername);?>" name="calendarusername" type="text" placeholder="<?php echo(_("agenda@exemple.org"));?>"></p>
			<div class="login-title"><?php echo(_("Mot de passe CalDAV (requis seulement si Type de calendrier = CalDAV) :"));
				echo $this->webpageparent->userinformations->calendarpassword !== '' ? " <span class='configured'>&#10003; "._("configuré")."</span>" : " <span class='notconfigured'>"._("non configuré")."</span>";
			?></div>
			<p><input value="" name="calendarpassword" type="password" autocomplete="new-password" placeholder="<?php echo(_("laisser vide pour conserver"));?>"></p>
			<div class="login-title"><?php echo(_("Courriel de notification/validation :"));?></div>
			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->validationfeedbackemail);?>" name="validationfeedbackemail" type="text" placeholder="<?php echo(_("ex.moncourriel@gmail.com (plusieurs adresses séparées par des virgules sont acceptées)"));?>"></p>
			<div class="login-title"><?php echo(_("Nom du groupe de sonnerie (Maximum 15 caractères):"));?>
				<div class="tooltip-container">
				<span class="exclamation-mark">!</span>
				<div class="tooltip-text"><?php echo(_("À chaque mise à jour, le groupe de sonnerie se réinitialisera à votre boîte vocale par défaut. Cependant, si vous avez besoin d'une boîte spécifique à un groupe de sonnerie, vous pouvez créer un boîte vocale ayant pour nom votre DID. Celle-ci sera utilisée à la place de la boîte vocale principale."));?></div>
				</div>
			</div>
				

			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->ringgroupname);?>" name="ringgroupname" maxlength="15" type="text" placeholder="<?php echo(_("ex.groupedesonnerie1"));?>"></p>
			<?php $ui = $this->webpageparent->userinformations; ?>
			<div class="login-title"><?php echo(_("Durée de sonnerie (secondes, 1 à 60) :"));?>
				<div class="tooltip-container">
				<span class="exclamation-mark">!</span>
				<div class="tooltip-text"><?php echo(_("Vide : 25 secondes. Pour un seul répondant, le repère nbsec->20 dans la description de son événement de l'agenda a priorité sur ce réglage."));?></div>
				</div>
			</div>
			<p><input value="<?php echo $ui->ringtime === null ? "" : intval($ui->ringtime);?>" name="ringtime" type="number" min="1" max="60" placeholder="<?php echo(_("par défaut : 25"));?>"></p>
			<div class="login-title"><label for="pressone"><?php echo(_("Faire le 1 pour accepter l'appel :"));?></label>
				<div class="tooltip-container">
				<span class="exclamation-mark">!</span>
				<div class="tooltip-text"><?php echo(_("Pour un seul répondant, le repère faire1->o ou faire1->n dans la description de son événement de l'agenda a priorité sur ce réglage."));?></div>
				</div>
			</div>
			<p><select name="pressone" id="pressone" >
			  <option value="" <?php if ($ui->pressone === null) echo "selected";?>><?php echo(_("par défaut (oui)"));?></option>
			  <option value="1" <?php if ($ui->pressone === 1) echo "selected";?>><?php echo(_("oui"));?></option>
			  <option value="0" <?php if ($ui->pressone === 0) echo "selected";?>><?php echo(_("non"));?></option>
			</select> </p>
			<div class="login-title"><?php echo(_("Numéro de DID :"));?></div>
			<p><input value="<?php echo htmlspecialchars($this->webpageparent->userinformations->didnumber);?>" name="didnumber" type="text" placeholder="ex.4188882323"></p>
			<p> <label for="language" class="login-title"><?php echo(_("Langue :"));?></label>
			<select name="language" id="language" >
			  <option value="fr" <?php if ($this->webpageparent->userinformations->language == 'fr') echo "selected";?>>Français</option>
			  <option value="en" <?php if ($this->webpageparent->userinformations->language == 'en') echo "selected";?>>English</option>
			</select> </p>
			<?php echo $lienDeValidation;?>
			
			
			<p><input type="submit" value="<?php echo(_("Soumettre"));?>"></p>
		  </form>
		</div>
		</section>



