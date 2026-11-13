	<style>
	.copy-link {
		color: #0066cc;
		text-decoration: none;
		cursor: pointer;
		padding: 0px 10px;
		border-radius: 4px;
		transition: background 0.2s;
		}
		.copy-link:hover {
		background: #f0f0f0;
		}
		.copy-link.copied {
		color: #00aa00;
		}
	</style>
    <script>
		function copyText(text, element) {
      navigator.clipboard.writeText(text).then(() => {
        // Success feedback
        const original = element.textContent;
        element.textContent = '✓ Copied!';
        element.classList.add('copied');
        
        // Reset after 2 seconds
        setTimeout(() => {
          element.textContent = original;
          element.classList.remove('copied');
        }, 2000);
      }).catch(err => {
        console.error('Failed to copy:', err);
        alert('Failed to copy to clipboard');
      });
    }
		function validateForm() {
		  window.location.reload();

		} 
		
		
	</script>
		<section>
		<div class="login">
		<div class="login-triangle"></div>
		 <?php echo boutonRetour($this->webpageparent->databasemanager); ?>
		<h2 class="login-header"><?php echo(_("Informations SwitchBoard Chat"));?></h2>
		
		<form id="userinfoform" name="userinfoform" class="login-container" action=""  onsubmit="return validateForm()" method="post">
			<?php echo csrfField(); ?>
			<?php echo ligneNomUtilisateur($this->webpageparent->databasemanager,$currentuserid); ?>
			<div class="login-title"><?php echo(_("Token API SB Chat :"));
				echo $sbchatapitoken !== '' ? " <span class='configured'>&#10003; "._("configuré")."</span>" : " <span class='notconfigured'>"._("non configuré")."</span>";
				?></div>
			<p><input value="" name="sbchatapitoken" type="password" autocomplete="new-password" placeholder="<?php echo(_("Faire une requête à votre administrateur, service désactivé.")." (" ._("laisser vide pour conserver").")");?>"></p>
			<?php 
			if ($peutDemanderUnJeton){
				// Un bouton du formulaire « demande-jeton », placé hors de celui-ci (un formulaire ne s'imbrique pas dans un autre)
				echo("<div class='login-title'><button type='submit' form='demande-jeton' class='bouton-lien'>"._("Demander une token API.")."</button></div><br>");
			}

			if(!empty($sbchathashedtoken)){
				echo "<div class='login-title' onclick='copyToClipboard('Text to copy'); return false;'>". _("Hook SMS à copier/coller dans votre DID voip.ms :")."</div>";
				echo "<div class='login-title'>"._("Cliquez pour copier :")." </div>";
				?>
				<a href="#" class="copy-link" onclick="copyText('<?php echo htmlspecialchars(addslashes($voipmshook), ENT_QUOTES);?>', this); return false;"><?php echo htmlspecialchars($voipmshook);?></a>

				<div class="login-title"><?php echo("<A href ='".htmlspecialchars($voipmshooktest, ENT_QUOTES)."' target = '_blank'>"._("Tester le hook (la page devrait retourner ok)"));?></A></div>
				<?php
				// Example: https://mysite.com/sms.php?to={TO}&from={FROM}&message={MESSAGE}&files={MEDIA}&id={ID}&date={TIMESTAMP}


			}
			?>
			<div class="login-title"><?php echo(_("URL de l'agenda"));?></div>
			<p><input value="<?php echo htmlspecialchars($sbchatcalendarurl);?>" name="sbchatcalendarurl" type="text" placeholder="<?php echo(_("Url privé du calendrier ici"));?>"></p>
			<div class="login-title"><?php echo(_("Adresse du serveur SwitchBoardChat"));?></div>
			<p><input value="<?php echo htmlspecialchars($sbchatserveraddress);?>" name="sbchatserveraddress" type="text" placeholder="<?php echo(_("ex: https://monserveur.org:3443"));?>"></p>
			<p><input type="submit" value="<?php echo(_("Soumettre"));?>"></p>
			<h2 class="login-header"><?php echo(_("Informations Matrix Authentification Service (MAS)"));?></h2>
			<div class="login-title"><?php echo(_("URL du serveur MAS"));?></div>
			<p><input value="<?php echo htmlspecialchars($masurl);?>" name="masurl" type="text" ></p>
		
			<div class="login-title"><?php echo(_("ID du client MAS"));
				echo $masclientid !== '' ? " <span class='configured'>&#10003; "._("configuré")."</span>" : " <span class='notconfigured'>"._("non configuré")."</span>";
			?></div>
			<p><input value="" name="masclientid" type="password" autocomplete="new-password" placeholder="<?php echo(_("laisser vide pour conserver"));?>"></p>

			<div class="login-title"><?php echo(_("SECRET du client MAS"));
				echo $massecret !== '' ? " <span class='configured'>&#10003; "._("configuré")."</span>" : " <span class='notconfigured'>"._("non configuré")."</span>";
			?></div>
			<p><input value="" name="massecret" type="password" autocomplete="new-password" placeholder="<?php echo(_("laisser vide pour conserver"));?>"></p>
			

			<p><input type="submit" value="<?php echo(_("Soumettre"));?>"></p>
		  </form>
		<?php if ($peutDemanderUnJeton): ?>
		<form id="demande-jeton" method="post" action="managementportal.php?page=requestsbchattoken&amp;iduser=<?php echo intval($iduser); ?>"><?php echo csrfField(); ?></form>
		<?php endif; ?>
		
		</div>
		</section>



