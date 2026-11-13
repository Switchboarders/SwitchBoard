<section>
<div class="login">
<div class="login-triangle"></div>
<?php echo boutonRetourConsole(); ?>
<h2 class="login-header"><?php echo(_("Courriel OAuth"));?></h2>

<?php if ($messageErreur !== ''): ?>
<div class="login-title" style="color:red;"><?php echo htmlspecialchars($messageErreur);?></div>
<?php endif; ?>
<?php if ($messageSucces !== ''): ?>
<div class="login-title" style="color:green;"><?php echo htmlspecialchars($messageSucces);?></div>
<?php endif; ?>

<?php if ($geeParEnv): ?>
<p><?php echo sprintf(_("Fournisseur configuré : %s. Statut : %s"), htmlspecialchars($fournisseur), $jetonPresent ? "<strong style='color:green;'>"._("autorisé")."</strong>" : "<strong style='color:orange;'>"._("non autorisé")."</strong>");?></p>
<?php if ($jetonPresent && $redemarrageRequis): ?>
<p style="color:orange;"><?php echo(_("Autorisé, mais l'envoi de courriel n'utilise pas encore ce mode OAuth sur ce conteneur : redémarrez-le une fois avec « docker compose restart php » (depuis le dossier Docker/) pour que ça prenne effet."));?></p>
<?php endif; ?>
<p><em><?php echo(_("Géré par la variable d'environnement SB_SMTP_OAUTH_PROVIDER (.env) - pour changer de fournisseur ou de Client ID/Secret, modifiez .env puis redémarrez le conteneur (docker compose restart php)."));?></em></p>

<?php elseif ($afficherFormulaireReglages): ?>

<?php if ($fournisseur !== ''): ?>
<p><a href="?page=mailoauth"><?php echo(_("&#8249; Annuler et revenir au statut"));?></a></p>
<?php endif; ?>

<p><?php echo(_("Choisissez le fournisseur de votre boîte courriel, puis suivez les étapes ci-dessous pour obtenir un Client ID (et un Client Secret pour Google) - une inscription unique à faire dans la console de ce fournisseur, hors de SwitchBoard."));?></p>

<details open style="margin:1em 0;text-align:left;">
<summary style="cursor:pointer;font-weight:bold;"><?php echo(_("Microsoft 365 : comment obtenir un Client ID"));?></summary>
<ol>
<li><?php echo(_("Allez sur")); ?> <a href="https://portal.azure.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade" target="_blank">portal.azure.com &rarr; Inscriptions d'applications</a> &rarr; <?php echo(_("Nouvelle inscription"));?>.</li>
<li><?php echo(_("Donnez-lui un nom (ex.: « SwitchBoard Mail »), laissez le reste par défaut, puis Enregistrer."));?></li>
<li><?php echo(_("Copiez l'« ID d'application (client) » affiché - c'est le Client ID à coller ci-dessous. Copiez aussi le « ID de l'annuaire (locataire) » (Tenant ID) affiché juste à côté sur cette même page - avec les réglages par défaut (« Comptes dans cet annuaire organisationnel uniquement »), ce Tenant ID est OBLIGATOIRE ci-dessous, PAS optionnel (voir la note Tenant plus bas - le laisser vide cause l'erreur AADSTS50059)."));?></li>
<li><?php echo(_("Authentification &rarr; Paramètres avancés &rarr; « Autoriser les flux clients publics » &rarr; Oui &rarr; Enregistrer."));?></li>
<li><?php echo(_("Rien à faire dans « Autorisations API » pour ce flux : SwitchBoard demande directement la permission SMTP.Send au moment de l'autorisation ci-dessous (c'est Microsoft qui affiche alors l'écran de consentement) - vous n'avez pas besoin de la chercher/ajouter vous-même dans le portail. Si votre tenant exige un consentement admin, l'écran de consentement vous le dira à ce moment-là plutôt qu'ici."));?></li>
<li><strong><?php echo(_("Étape séparée obligatoire : un administrateur Exchange doit aussi activer l'authentification SMTP sur la boîte courriel elle-même (désactivée par défaut). Voir MAIL-OAUTH-SETUP.md pour la commande exacte - sans cette étape, l'envoi échouera même une fois autorisé ici."));?></strong></li>
<li><strong><?php echo(_("Tenant : avec les réglages d'inscription par défaut (mono-tenant), ce champ est OBLIGATOIRE - collez l'ID de l'annuaire (locataire) copié à l'étape 3 (ou le nom de domaine de votre organisation, ex.: contoso.onmicrosoft.com). Le laisser vide envoie la demande à l'adresse générique « common », que Microsoft refuse pour une inscription mono-tenant avec l'erreur AADSTS50059 « No tenant-identifying information found »."));?></strong></li>
</ol>
</details>

<details style="margin:1em 0;text-align:left;">
<summary style="cursor:pointer;font-weight:bold;"><?php echo(_("Google : comment obtenir un Client ID et un Client Secret"));?></summary>
<ol>
<li><?php echo(_("Allez sur")); ?> <a href="https://console.cloud.google.com/apis/credentials" target="_blank">console.cloud.google.com &rarr; Identifiants</a> <?php echo(_("(créez un projet si vous n'en avez pas)."));?></li>
<li><?php echo(_("Écran de consentement OAuth : type Externe, remplissez le nom de l'app et un courriel de support, Enregistrer (le mode « Test » suffit - ajoutez votre propre adresse comme utilisateur de test)."));?></li>
<li><?php echo(_("Identifiants &rarr; Créer des identifiants &rarr; ID client OAuth &rarr; type d'application « Application Web »."));?></li>
<li><?php echo(_("Dans « URI de redirection autorisés », collez EXACTEMENT cette adresse :"));?>
<p><code style="user-select:all;word-break:break-all;"><?php echo htmlspecialchars($urlCettePage);?></code></p>
</li>
<li><?php echo(_("Créez, puis copiez le Client ID ET le Client Secret affichés - collez-les ci-dessous."));?></li>
</ol>
</details>

<form method="post" action="?page=mailoauth&amp;modifier=1" style="text-align:left;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="enregistrer_reglages">
	<p>
		<label><input type="radio" name="fournisseur" value="microsoft365" <?php echo($fournisseur === 'microsoft365' ? 'checked' : '');?>> Microsoft 365</label><br>
		<label><input type="radio" name="fournisseur" value="google" <?php echo($fournisseur === 'google' ? 'checked' : '');?>> Google</label>
	</p>
	<p><label><?php echo(_("Adresse courriel du compte à autoriser :"));?><br>
	<input name="mailbox_user" type="email" value="<?php echo htmlspecialchars($mailboxUser);?>" placeholder="exemple@domaine.com" style="width:100%;"></label></p>
	<p><label>Client ID :<br>
	<input name="client_id" type="text" value="<?php echo htmlspecialchars($clientId);?>" style="width:100%;"></label></p>
	<p><label>Client Secret (<?php echo(_("Google seulement"));?>) :<br>
	<input name="client_secret" type="password" value="" placeholder="<?php echo($clientSecret !== '' ? _('(déjà enregistré - laissez vide pour le conserver)') : '');?>" style="width:100%;"></label></p>
	<p><label>Tenant (<?php echo(_("Microsoft 365 seulement - laissez vide UNIQUEMENT si votre inscription Azure est multi-tenant ; requis dans la plupart des cas, voir le guide ci-dessus"));?>) :<br>
	<input name="tenant" type="text" value="<?php echo htmlspecialchars($tenant === 'common' ? '' : $tenant);?>" placeholder="ID de l'annuaire (locataire) ou contoso.onmicrosoft.com" style="width:100%;"></label></p>
	<p><input type="submit" value="<?php echo(_("Enregistrer"));?>"></p>
</form>

<?php else: ?>

<p><?php echo sprintf(_("Fournisseur configuré : %s (%s). Statut : %s"), htmlspecialchars($fournisseur), htmlspecialchars($mailboxUser), $jetonPresent ? "<strong style='color:green;'>"._("autorisé")."</strong>" : "<strong style='color:orange;'>"._("non autorisé")."</strong>");?></p>

<?php if ($jetonPresent && $redemarrageRequis): ?>
<p style="color:orange;"><?php echo(_("Autorisé, mais l'envoi de courriel n'utilise pas encore ce mode OAuth sur ce conteneur : redémarrez-le une fois avec « docker compose restart php » (depuis le dossier Docker/) pour que ça prenne effet."));?></p>
<?php endif; ?>

<?php if ($jetonPresent): ?>
<form method="post" action="?page=mailoauth" style="display:inline;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="supprimer">
	<p><input type="submit" value="<?php echo(_("Retirer l'autorisation"));?>"></p>
</form>
<?php endif; ?>

	<?php if ($fournisseur === 'microsoft365'): ?>
	<?php if ($codeEnCours && $codeEnCours['user_code'] !== ''): ?>
	<h3><?php echo(_("Autorisation en cours"));?></h3>
	<p><?php echo sprintf(_("Allez sur %s et entrez ce code :"), "<a href='".htmlspecialchars($codeEnCours['verification_uri'], ENT_QUOTES)."' target='_blank'>".htmlspecialchars($codeEnCours['verification_uri'])."</a>");?></p>
	<p style="font-size:1.5em;font-weight:bold;"><?php echo htmlspecialchars($codeEnCours['user_code']);?></p>
	<form method="post" action="?page=mailoauth" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="verifier">
		<p><input type="submit" value="<?php echo(_("J'ai autorisé, continuer"));?>"></p>
	</form>
	<form method="post" action="?page=mailoauth" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="annuler_device_code">
		<p><input type="submit" value="<?php echo(_("Annuler et recommencer (nouveau code)"));?>"></p>
	</form>
	<?php elseif ($codeEnSession): ?>
	<form method="post" action="?page=mailoauth" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="verifier">
		<p><input type="submit" value="<?php echo(_("J'ai autorisé, continuer"));?>"></p>
	</form>
	<form method="post" action="?page=mailoauth" style="display:inline;">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="annuler_device_code">
		<p><input type="submit" value="<?php echo(_("Annuler et recommencer (nouveau code)"));?>"></p>
	</form>
	<?php else: ?>
	<form method="post" action="?page=mailoauth">
		<?php echo csrfField(); ?>
		<input type="hidden" name="action" value="demarrer">
		<p><input type="submit" value="<?php echo(_("Démarrer l'autorisation Microsoft 365"));?>"></p>
	</form>
	<?php endif; ?>
<?php elseif ($fournisseur === 'google'): ?>
		<p><a href="<?php echo htmlspecialchars($urlGoogle, ENT_QUOTES);?>"><?php echo(_("Autoriser Google"));?></a></p>
<?php endif; ?>

<p style="margin-top:1.5em;">
<a href="?page=mailoauth&modifier=1"><?php echo(_("Modifier les réglages (fournisseur, Client ID/Secret)"));?></a>
</p>
<form method="post" action="?page=mailoauth" style="display:inline;" onsubmit="return confirm('<?php echo htmlspecialchars(_("Retirer complètement les réglages OAuth (fournisseur, Client ID/Secret et autorisation) ?"), ENT_QUOTES);?>');">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="supprimer_reglages">
	<p><input type="submit" value="<?php echo(_("Retirer les réglages et recommencer"));?>"></p>
</form>

<?php endif; ?>

</div>
</section>
