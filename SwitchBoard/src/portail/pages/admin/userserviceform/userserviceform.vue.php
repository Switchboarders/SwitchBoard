
	<script>
		// Compte principal : seules les statistiques mensuelles sont permises. Les autres cases
		// sont décochées et grisées ; en décochant « Compte principal » avant de soumettre, elles
		// retrouvent leur état d'avant. Le serveur fait la même règle à l'enregistrement
		// (setUserServiceValuesByIduser), ce script n'est qu'un confort pour l'admin.
		const servicesNonPermis = ["ringgroupupdate", "smsdaybefore", "smshourbefore", "sbchatupdate"];
		const etatAvantComptePrincipal = {};

		function appliquerComptePrincipal() {
			const formulaire = document.forms["userinfoform"];
			const estComptePrincipal = formulaire["mainaccount"].checked;
			for (const nom of servicesNonPermis) {
				const caseService = formulaire[nom];
				if (estComptePrincipal) {
					etatAvantComptePrincipal[nom] = caseService.checked;
					caseService.checked = false;
				} else if (nom in etatAvantComptePrincipal) {
					caseService.checked = etatAvantComptePrincipal[nom];
				}
				caseService.disabled = estComptePrincipal;
				caseService.closest("label").style.opacity = estComptePrincipal ? "0.4" : "";
			}
			document.getElementById("notecompteprincipal").style.display = estComptePrincipal ? "" : "none";
		}

		// Confirmation seulement quand le compte DEVIENT principal
		function confirmerComptePrincipal() {
			const casePrincipal = document.forms["userinfoform"]["mainaccount"];
			if (!casePrincipal.checked || casePrincipal.defaultChecked) {
				return true;
			}
			return confirm(<?php echo json_encode(_("Ce compte deviendra un compte principal : les services de groupe de sonnerie, de SMS et de SwitchBoard Chat seront désactivés. Continuer ?")); ?>);
		}

		document.addEventListener("DOMContentLoaded", appliquerComptePrincipal);

		function validateForm() {
			if (!confirmerComptePrincipal()) {
				return false;
			}
			window.location.reload();

		} 
		
		
	</script>
	<section>
		<div class="login">
			<div class="login-triangle"></div>
			<?php echo boutonRetourConsole(); ?>
			<h2 class="login-header"><?php echo _("Services de l'utilisateur");?></h2>
			
			<form id="userinfoform" name="userinfoform" class="login-container" action=""  onsubmit="return validateForm()" method="post">
				<?php echo csrfField(); ?>
				<?php echo $ligneNomUtilisateur; ?>
				
				
				<div class="login-title"><?php echo _("Type de compte :");?></div>
				<div class="checkboxcentered">
					<label class="container"><?php echo _("Compte principal (paie le compte voip.ms : reçoit le rapport de coûts complet de tous ses DID)");?>
						<input type="checkbox" name="mainaccount" value="True" onchange="appliquerComptePrincipal()" <?php if ($this->checkboxvalues['mainaccount']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
				</div>
				<div class="login-title"><?php echo _("Services :");?></div>
				<p id="notecompteprincipal" style="display:none;"><i><?php echo _("Un compte principal n'a que les statistiques mensuelles : les autres services ne sont pas disponibles.");?></i></p>
				<div class="checkboxcentered">
					<label class="container"><?php echo _("Mise à jour des groupes de sonneries");?>
						<input type="checkbox" name="ringgroupupdate" value="True" <?php if ($this->checkboxvalues['ringgroupupdate']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
					<label class="container"><?php echo _("SMS le jour d'avant");?>
						<input type="checkbox" name="smsdaybefore" value="True" <?php if ($this->checkboxvalues['smsdaybefore']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
					<label class="container"><?php echo _("SMS une demi-heure avant");?>
						<input type="checkbox" name="smshourbefore" value="True" <?php if ($this->checkboxvalues['smshourbefore']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
					<label class="container"><?php echo _("Envoyer des statistiques mensuellement");?>
						<input type="checkbox" name="sendstats" value="True" <?php if ($this->checkboxvalues['sendstats']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
					<label class="container"><?php echo _("Mise à jour de SwitchBoard Chat");?>
						<input type="checkbox" name="sbchatupdate" value="True" <?php if ($this->checkboxvalues['sbchatupdate']) echo "checked"; ?>>
						<span class="checkmark"></span>
					</label><br><br>
				
				</div>
				<input type="hidden" name="haschanged" value="opened">
				<p><input type="submit" value="<?php echo _("Soumettre");?>"></p>
			</form>
		</div>
	</section>
		



