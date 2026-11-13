<section>
<div class="login">
<div class="login-triangle"></div>
<?php echo boutonRetourConsole(); ?>
<h2 class="login-header"><?php echo(_("Comparaison des coûts entre comptes voip.ms"));?></h2>

<p><?php echo(_("Choisissez une période et les comptes à comparer. Le coût de chaque compte est réparti mois par mois, avec son solde actuel (indépendant de la période choisie). Utile pour évaluer une consolidation de plusieurs comptes voip.ms en un seul."));?></p>

<form id="rapport-formulaire" method="post" action="?page=multiaccountcostreport">
	<?php echo csrfField(); ?>
	<p><label><?php echo(_("Date de début :"));?>
	<input type="date" name="dateFrom" required></label>
	<label><?php echo(_("Date de fin :"));?>
	<input type="date" name="dateTo" required></label></p>

	<p>
	<a href="#" onclick="document.querySelectorAll('.compte-checkbox').forEach(c => c.checked = true); return false;"><?php echo(_("Tout sélectionner"));?></a>
	 |
	<a href="#" onclick="document.querySelectorAll('.compte-checkbox').forEach(c => c.checked = false); return false;"><?php echo(_("Tout désélectionner"));?></a>
	</p>

	<?php if (empty($comptesAdmissibles)): ?>
	<p style="color:orange;"><?php echo(_("Aucun compte avec un compte voip.ms configuré n'a été trouvé."));?></p>
	<?php else: ?>
	<table style="border-collapse:collapse;">
		<?php foreach ($comptesAdmissibles as $compte): ?>
		<tr>
			<td style="padding:0.2rem 0.5rem 0.2rem 0;vertical-align:middle;">
				<input type="checkbox" class="compte-checkbox" id="compte-<?php echo (int)$compte['iduser'];?>" name="idusers[]" value="<?php echo (int)$compte['iduser'];?>">
			</td>
			<td style="padding:0.2rem 0;vertical-align:middle;">
				<label for="compte-<?php echo (int)$compte['iduser'];?>"><?php echo htmlspecialchars($compte['username']);?></label>
			</td>
		</tr>
		<?php endforeach; ?>
	</table>
	<?php endif; ?>

	<p><input type="submit" value="<?php echo(_("Générer l'aperçu"));?>"></p>
</form>

<div id="rapport-erreur" style="color:red;"></div>
<div id="rapport-progression"></div>
<div id="rapport-resultat"></div>

</div>
</section>

<script>
(function() {
	var formulaire = document.getElementById('rapport-formulaire');
	var zoneErreur = document.getElementById('rapport-erreur');
	var zoneProgression = document.getElementById('rapport-progression');
	var zoneResultat = document.getElementById('rapport-resultat');

	function appelJson(donnees) {
		return fetch('?page=multiaccountcostreport', {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: new URLSearchParams(donnees).toString()
		}).then(function(reponse) { return reponse.json(); });
	}

	var NB_TENTATIVES_AUTOMATIQUES = 3;
	var DELAI_ENTRE_TENTATIVES_MS = 3000;

	// Chaque étape ne fait avancer le compteur $_POST['jobToken'] côté serveur QUE si elle a
	// réussi (voir TacheComparaisonCouts::traiterEtape()) : un appel qui échoue en cours de route
	// (panne réseau ponctuelle, ex. vers voip.ms) peut donc toujours être réessayé sans perdre le
	// travail déjà fait ni en refaire - le serveur reprend exactement où il était rendu. D'abord
	// quelques essais automatiques silencieux (le cas le plus fréquent : un simple ralentissement
	// passager), puis un bouton "Reprendre" si ça persiste, plutôt que de tout faire recommencer
	// depuis le formulaire.
	function traiterProchaineEtape(jobToken, tentative) {
		tentative = tentative || 0;
		appelJson({
			action: 'process_step',
			csrf_token: '<?php echo $jetonCsrf; ?>',
			jobToken: jobToken
		}).then(function(resultat) {
			if (resultat.erreur) {
				zoneErreur.textContent = resultat.erreur;
				zoneProgression.textContent = '';
				return;
			}
			zoneProgression.textContent = '<?php echo addslashes(_("Calcul en cours...")); ?> ' + resultat.progress + ' / ' + resultat.total;
			if (resultat.done) {
				afficherResultat(jobToken);
			} else {
				traiterProchaineEtape(jobToken, 0);
			}
		}).catch(function() {
			if (tentative < NB_TENTATIVES_AUTOMATIQUES) {
				zoneProgression.textContent = '<?php echo addslashes(_("Communication interrompue, nouvel essai...")); ?>';
				setTimeout(function() {
					traiterProchaineEtape(jobToken, tentative + 1);
				}, DELAI_ENTRE_TENTATIVES_MS);
				return;
			}
			zoneProgression.textContent = '';
			zoneErreur.innerHTML = '';
			var messageErreur = document.createTextNode('<?php echo addslashes(_("Erreur de communication avec le serveur.")); ?> ');
			var boutonReprendre = document.createElement('button');
			boutonReprendre.type = 'button';
			boutonReprendre.textContent = '<?php echo addslashes(_("Reprendre")); ?>';
			boutonReprendre.onclick = function() {
				zoneErreur.textContent = '';
				traiterProchaineEtape(jobToken, 0);
			};
			zoneErreur.appendChild(messageErreur);
			zoneErreur.appendChild(boutonReprendre);
		});
	}

	function afficherResultat(jobToken) {
		// Le calcul est déjà terminé côté serveur à ce stade - seul l'affichage a pu échouer -
		// donc un simple bouton "Reprendre" (qui redemande juste l'affichage, sans rien
		// recalculer) suffit ici, pas besoin de la boucle de tentatives automatiques.
		appelJson({
			action: 'render_result',
			csrf_token: '<?php echo $jetonCsrf; ?>',
			jobToken: jobToken
		}).then(function(resultat) {
			zoneProgression.textContent = '';
			if (resultat.erreur) {
				zoneErreur.textContent = resultat.erreur;
				return;
			}
			zoneResultat.innerHTML = resultat.html;
		}).catch(function() {
			zoneProgression.textContent = '';
			zoneErreur.innerHTML = '';
			var messageErreur = document.createTextNode('<?php echo addslashes(_("Erreur de communication avec le serveur.")); ?> ');
			var boutonReprendre = document.createElement('button');
			boutonReprendre.type = 'button';
			boutonReprendre.textContent = '<?php echo addslashes(_("Reprendre")); ?>';
			boutonReprendre.onclick = function() {
				zoneErreur.textContent = '';
				afficherResultat(jobToken);
			};
			zoneErreur.appendChild(messageErreur);
			zoneErreur.appendChild(boutonReprendre);
		});
	}

	formulaire.addEventListener('submit', function(e) {
		e.preventDefault();
		zoneErreur.textContent = '';
		zoneResultat.innerHTML = '';
		zoneProgression.textContent = '<?php echo addslashes(_("Démarrage...")); ?>';

		var idusers = Array.prototype.map.call(
			formulaire.querySelectorAll('.compte-checkbox:checked'),
			function(c) { return c.value; }
		);

		var corps = new URLSearchParams();
		corps.append('action', 'init_job');
		corps.append('csrf_token', '<?php echo $jetonCsrf; ?>');
		corps.append('dateFrom', formulaire.dateFrom.value);
		corps.append('dateTo', formulaire.dateTo.value);
		idusers.forEach(function(id) { corps.append('idusers[]', id); });

		fetch('?page=multiaccountcostreport', {
			method: 'POST',
			headers: {'Content-Type': 'application/x-www-form-urlencoded'},
			body: corps.toString()
		}).then(function(reponse) { return reponse.json(); }).then(function(resultat) {
			if (resultat.erreur) {
				zoneErreur.textContent = resultat.erreur;
				zoneProgression.textContent = '';
				return;
			}
			if (resultat.total === 0) {
				zoneProgression.textContent = '';
				zoneErreur.textContent = '<?php echo addslashes(_("Aucune étape à traiter.")); ?>';
				return;
			}
			traiterProchaineEtape(resultat.token);
		}).catch(function() {
			zoneErreur.textContent = '<?php echo addslashes(_("Erreur de communication avec le serveur - réessayez.")); ?>';
		});
	});
})();
</script>
