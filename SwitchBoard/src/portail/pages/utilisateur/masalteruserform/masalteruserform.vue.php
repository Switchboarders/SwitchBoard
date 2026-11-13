    <script>
		function validateForm() {
		  // Le mot de passe tapé reste dans ce navigateur (sessionStorage, ce seul onglet, jamais envoyé au serveur) le temps de la
		  // redirection : la page qui suit le remet dans l'invitation, puis l'efface. Le serveur ne le garde pas en session.
		  try { sessionStorage.setItem('sb_mas_mot_de_passe', document.getElementById('masuserpassword').value); } catch (e) {}
		  return true;
		}
	
		
	</script>
		<section>
		<div class="login">
		<div class="login-triangle"></div>
		 <?php echo boutonRetour($this->webpageparent->databasemanager); ?>
		<h2 class="login-header"><?php echo(_("Ajout d'utilisateur MAS ou Changement de mot de passe."));?></h2>
		<form id="userinfoform" name="userinfoform" class="login-container" action=""  onsubmit="return validateForm()" method="post">
		<?php echo csrfField(); ?>
		<?php /* $masaction_checked : l'action choisie, préparée par le traitement */ ?>
		<p>
			<label><input type="radio" name="masaction" value="create" <?php echo $masaction_checked === 'create' ? 'checked' : ''; ?>> <?php echo(_("Créer un nouvel utilisateur"));?></label><br>
			<label><input type="radio" name="masaction" value="modify" <?php echo $masaction_checked === 'modify' ? 'checked' : ''; ?>> <?php echo(_("Modifier un utilisateur existant (mot de passe, courriel ou droits) ou refaire son invitation Element X"));?></label>
		</p>
		<div class="login-title"><?php echo(_("Partie locale du nom d'utilisateur matrix (pour @jdupont:matrix.org, jdupont est la partie locale) :"));?></div>
		<p><input name="localmasusername" value="<?php echo htmlspecialchars($field_cache['username']); ?>" type="text" placeholder="<?php echo(_("nom_utilisateur_local"));?>"></p>
			<div class="login-title"><?php echo(_("Mot de passe de l'utilisateur :"));?></div>
			<?php // Jamais repeuplé après soumission : un mot de passe n'a pas à réapparaître
			      // en clair dans le HTML de la page (ni dans un champ type="text"). ?>
			<p><input id="masuserpassword" name="masuserpassword" value="" type="password" autocomplete="new-password" placeholder="<?php echo(_("ex.monmotdepasse12345"));?>"></p>
			<p><button type="button" class="btn btn-secondary" id="masgeneratepassword"><?php echo(_("Générer un mot de passe facile à taper"));?></button></p>
			<p><input name="masuseremail" value="<?php echo htmlspecialchars($field_cache['email']); ?>" type="text" placeholder="<?php echo(_("ex.monemail@domaine.com"));?>"></p>
			<p><label><input name="masuseradmin" type="checkbox" value="1"> <?php echo(_("Administrateur du serveur Matrix (n'enlève pas les droits d'un administrateur existant)"));?></label></p>
			<p><input type="submit" value="<?php echo(_("Soumettre"));?>"></p>
		  </form>
		</div>
		</section>
	<script>
	// Pas d'invitation sur cette page (une erreur, ou la page ouverte seule) : le mot de passe gardé par validateForm() ne sert à rien, on l'efface
	if(!document.getElementById('masinvitationmessage')){
		try { sessionStorage.removeItem('sb_mas_mot_de_passe'); } catch (e) {}
	}
	</script>
	<script>
	// Mot de passe facile à taper sur un téléphone : quatre mots simples (sans accents) et un nombre,
	// p. ex. « pomme-nuage-tigre-lampe-42 » (environ 38 bits ; MAS limite les essais de connexion).
	// Tiré avec crypto.getRandomValues, sans biais (on rejette les valeurs hors du dernier multiple).
	(function(){
		var words = ["abri","acier","aigle","album","ami","ananas","ancre","arbre","argent","avion","avril","balle","banane","bateau","bijou","biscuit","bleu","bois","bonbon","bouton","brique","brume","bulle","cabane","cactus","cadeau","cafe","calme","camion","canard","canot","carte","castor","cerise","chaise","chalet","chameau","chapeau","chat","chemin","cheval","chien","citron","clef","clown","cobalt","coco","colibri","comete","concert","coq","corde","coton","coussin","crayon","cristal","cygne","dauphin","desert","diamant","domino","dragon","dune","eclair","ecureuil","encre","epice","erable","etoile","fable","falaise","farine","fenetre","feu","figue","flamme","fleur","flute","foret","fraise","frite","fromage","fusee","galet","gant","gateau","girafe","glace","gomme","gorille","grain","grenier","griffon","guitare","hamac","hibou","hiver","horloge","igloo","ile","jardin","jasmin","jaune","jeton","joie","jongleur","journal","judo","jungle","kayak","kiwi","koala","lac","lampe","lapin","laser","lavande","legume","lezard","lime","lion","livre","loup","lumiere","lune","lynx","macaron","magie","maison","mangue","manteau","marche","matin","melon","menthe","miel","miroir","montagne","moulin","mouton","muguet","musique","nacre","neige","nid","noisette","nuage","ocean","oiseau","olive","ombre","orange","orchidee","orge","otarie","ours","palmier","panda","papier","parc","pastel","patin","pelle","perle","phare","piano","pigeon","pinceau","pirate","pivoine","plage","plume","poire","poisson","pomme","pont","potiron","prairie","prune","puzzle","quai","radis","raisin","rameau","renard","requin","riviere","robot","rocher","rose","ruban","sable","safran","salade","sapin","satin","saumon","savon","sentier","serpent","sirop","soleil","sorbet","souris","stylo","sucre","tambour","tapis","tigre","tomate","tortue","toucan","train","tresor","tulipe","valise","vanille","velo","vent","verger","violon","volcan","wagon","yogourt","zebre","zeste"];
		function pick(n){
			var max = Math.floor(4294967296 / n) * n, v = new Uint32Array(1);
			do { crypto.getRandomValues(v); } while (v[0] >= max);
			return v[0] % n;
		}
		var button = document.getElementById('masgeneratepassword');
		var field = document.getElementById('masuserpassword');
		if(button && field && window.crypto && crypto.getRandomValues){
			button.addEventListener('click', function(){
				var parts = [];
				for(var i = 0; i < 4; i++){ parts.push(words[pick(words.length)]); }
				parts.push(String(10 + pick(90)));
				field.type = 'text';
				field.value = parts.join('-');
			});
		}
	})();
	</script>



