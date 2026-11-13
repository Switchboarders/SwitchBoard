<?php 
require_once SRC_DIRECTORY.'utils/isHttps.php';
if(!isHttps())
{
    header("Location: https://" . $_SERVER["HTTP_HOST"] . $_SERVER["REQUEST_URI"]);
    exit();
}
echo "<br><br><br>";
require_once PORTAIL_DIRECTORY.'pages/utilisateur/masalteruserform/masinvitation.php';
// Après une création ou une modification réussie : l'invitation Element X à envoyer à la personne
$mas_invite = null;
$field_cache = ['username' => "",
			'password' => "",
			'email' => "",
		];
// Ce que l'enregistrement qui vient d'avoir lieu a gardé pour cette page (voir terminerEnregistrement()) : le nom et le courriel
// à remettre dans le formulaire après une erreur, et l'action choisie. Jamais le mot de passe : il n'est pas gardé en session.
$suite = valeursDEnregistrement();
if (is_array($suite['champs'] ?? null)) {
	$field_cache = array_merge($field_cache, array_intersect_key($suite['champs'], ['username' => 1, 'email' => 1]));
}
function set_cached_fields_variable(){
	if(!empty($_POST['localmasusername'])){
		$cachedusername = $_POST['localmasusername'];
	}else{
		$cachedusername = "";
	}
	if(!empty($_POST['masuserpassword'])){
		$cachedpassword = $_POST['masuserpassword'];
	}else{
		$cachedpassword = "";
	}
	if(!empty($_POST['masuseremail'])){
		$cachedemail = $_POST['masuseremail'];
	}else{
		$cachedemail = "";
	}
	return ['username' => $cachedusername,
			'password' => $cachedpassword,
			'email' => $cachedemail,
		];
}

if (!empty($_POST['localmasusername'])){
	requireCsrf();
	// Une création ou une modification répond par une redirection vers la page en GET (#77) : F5 ne la refait pas. Ce que le serveur
	// MAS a répondu, et l'invitation, s'affichent une fois, au-dessus du formulaire
	commencerEnregistrement();
	//&& !empty($_POST['masuserpassword'])
	$sbchat = new ClientSwitchBoardChat("");
	// Seul un administrateur (privilegelevel <= 0) peut créer ou promouvoir un administrateur
	// Matrix. La page est déjà réservée aux administrateurs (requireAdmin), on revérifie ici.
	$wants_mas_admin = !empty($_POST['masuseradmin']);
	$can_grant_mas_admin = $this->webpageparent->databasemanager->getcurrentuserprivilegelevel() <= 0;
	if($wants_mas_admin && !$can_grant_mas_admin){
		$wants_mas_admin = false;
		echo "<h2 style='color:red;'>"._("Seul un administrateur peut créer un administrateur Matrix.")."</h2>";
	}
	// Garde-fou : créer un utilisateur dont le nom existe déjà modifiait en silence le courriel ou le mot de
	// passe de la personne existante. L'action est maintenant choisie explicitement (création par défaut) et
	// rien n'est changé si elle ne correspond pas à ce qui existe sur le serveur MAS.
	$masaction = (($_POST['masaction'] ?? 'create') === 'modify') ? 'modify' : 'create';
	// Même nom malgré les majuscules, les espaces ou un identifiant complet (@nom:serveur) collé par erreur
	$masusername = strtolower(trim($_POST['localmasusername']));
	$masusername = ltrim($masusername, '@');
	if(str_contains($masusername, ':')){
		$masusername = strstr($masusername, ':', true);
	}
	$_POST['localmasusername'] = $masusername;
	$mas_abort = null;
	$mas_lookup = ['status' => 'error', 'id' => null, 'detail' => ''];
	if(!preg_match('/^[a-z0-9._=\/+-]+$/', $masusername)){
		$mas_abort = _("Nom d'utilisateur invalide : seulement des lettres minuscules, des chiffres et . _ = / + -");
	}else{
		$mas_lookup = $sbchat->lookup_mas_user($masusername);
		if($mas_lookup['status'] === 'error'){
			$mas_abort = _("Impossible de vérifier si cet utilisateur existe sur le serveur MAS.")." ".htmlspecialchars($mas_lookup['detail']);
		}elseif($masaction === 'create' && $mas_lookup['status'] === 'found'){
			$mas_abort = sprintf(_("L'utilisateur « %s » existe déjà. Pour changer son mot de passe ou son courriel, choisissez « Modifier un utilisateur existant ». Sinon, choisissez un autre nom d'utilisateur."), htmlspecialchars($masusername));
		}elseif($masaction === 'modify' && $mas_lookup['status'] === 'notfound'){
			$mas_abort = sprintf(_("L'utilisateur « %s » n'existe pas. Pour le créer, choisissez « Créer un nouvel utilisateur »."), htmlspecialchars($masusername));
		}
	}
	if($mas_abort !== null){
		$field_cache = set_cached_fields_variable();
		echo "<h2 style='color:red;'>".$mas_abort._("<br>Aucune information enregistrée.")."</h2>";
	}elseif($mas_lookup['status'] === 'found'){
		$user_id = $mas_lookup['id'];
		// User Exist
		// Both great for debugging
		
		echo "<br>";
		// Le courriel et le mot de passe se changent ensemble ou séparément. Si le changement de
		// courriel échoue, le mot de passe n'est pas touché non plus (« Aucune information enregistrée »).
		$email_failed = false;
		if(!empty($_POST['masuseremail'])){
			if($user_email = $sbchat->get_mas_user_email($user_id)){
				// User has an email
				if($user_email['email'] !=$_POST['masuseremail']){
					// User want to change email
					if($new_email_id = $sbchat->set_mas_user_email($user_id,$_POST['masuseremail'])){
						// User email change was a success
						// Delete other email for this user :
						$sbchat->delete_mas_user_email_except($user_id,$new_email_id);
						echo "<h2>"._("Adresse courriel changée avec succès.")."</h2>";
					}else {
						// User email change failed (previous functions gave email username) Abort
						$email_failed = true;
						$field_cache = set_cached_fields_variable();
						echo "<h2 style='color:red;'>"._("Erreur pour changer l'adresse courriel.")._("<br>Aucune information enregistrée.")."</h2>";
					}
				}else {
					// User already has that email: nothing to change, the password can still change
					echo "<h2>"._("Adresse courriel déjà inscrite.")."</h2>";
				}
			}else{
				// User has no email yet or email did not check, try create one
				$result = $sbchat->set_mas_user_email($user_id,$_POST['masuseremail']);
				if(!$result){
					$email_failed = true;
					$field_cache = set_cached_fields_variable();
					echo "<h2 style='color:red;'>"._("Impossible de créer l'adresse courriel.")._("<br>Aucune information enregistrée.")."</h2>";
				}
			}
		}
		$password_ok = false;
		if(!$email_failed && !empty($_POST['masuserpassword'])){
			$password_ok = $sbchat->set_mas_user_password($_POST['localmasusername'],$_POST['masuserpassword'],$user_id) === true;
			if($password_ok){
				echo "<h2>"._("Nouvel mot de passe enregistré.")."</h2>";
			}
		}
		// Utilisateur existant : « Modifier » sans rien changer sert aussi à refaire son invitation
		if(!$email_failed){
			$mas_invite = ['username' => $masusername, 'motdepasse' => $password_ok];
		}
		// Utilisateur existant : la case cochée le promeut. Une case décochée ne retire
		// rien, pour ne pas rétrograder un administrateur par simple oubli.
		if($wants_mas_admin){
			$sbchat->set_mas_user_admin($user_id,true);
		}


	}else{
		// User does not exist
		echo "<br>User does not exist<br>";
		if(empty($_POST['masuserpassword'])){
			// User has not entered a password
			echo "<h2 style='color:red;'>"._("L'utilisateur n'existe pas, pour entrer un nouvel utilisateur, veuillez entrer un mot de passe valide."._("<br>Aucune information enregistrée.")."</h2>");
			$field_cache = set_cached_fields_variable();
		}else{
			// User has entered a password, check email and then enter password
			if(!empty($_POST['masuseremail'])){
				// An email is entered
				echo "<br>An email is entered<br>";
				if($user_email = $sbchat->get_mas_user_by_email($_POST['masuseremail'])){
					// If the email already exist, it must be for another user, abort
					echo "<br>user_email:$user_email<br>";
					$username = $sbchat->get_mas_username_by_id($user_email);
					echo "<h2 style='color:red;'>"._("Cette adresse courriel est déjà utilisée par un autre utilisateur : ").$username._("<br>Aucune information enregistrée."). "</h2>";
					$field_cache = set_cached_fields_variable();

				}else{
					// New user
					$new_user_id = $sbchat->add_mas_user($_POST['localmasusername']);
					if($new_user_id && $wants_mas_admin){
						$sbchat->set_mas_user_admin($new_user_id,true);
					}
					// User has no email yet or email did not check, try create one
					$result = $sbchat->set_mas_user_email($new_user_id,$_POST['masuseremail']);
					if(!$result){
						$field_cache = set_cached_fields_variable();						
						echo "<h2 style='color:red;'>"._("Impossible de créer l'adresse courriel.")._("<br>Aucune information enregistrée.")."</h2>";
					}
					// Change the password if it need to
					if($new_user_id){
						$password_ok = $sbchat->set_mas_user_password($_POST['localmasusername'],$_POST['masuserpassword'],$new_user_id) === true;
						$mas_invite = ['username' => $masusername, 'motdepasse' => $password_ok];
					}
				}
			}else{
				// No email was entered, go on with user creation by setting user and password
				// username and password are not empty
				$new_user_id = $sbchat->add_mas_user($_POST['localmasusername']);
				if($new_user_id && $wants_mas_admin){
					$sbchat->set_mas_user_admin($new_user_id,true);
				}
				if($new_user_id){
					$password_ok = $sbchat->set_mas_user_password($_POST['localmasusername'],$_POST['masuserpassword'],$new_user_id) === true;
					if($password_ok){
						echo "<h2>"._("Nouvel utilisateur et mot de passe enregistrés.")."</h2>";
					}
					$mas_invite = ['username' => $masusername, 'motdepasse' => $password_ok];
				}

			}

		}

	}
	if($mas_invite !== null){
		// Sans le mot de passe : le navigateur le remet dans le message (voir render_mas_invitation())
		render_mas_invitation($mas_invite['username'], $sbchat->get_matrix_server_name(), $mas_invite['motdepasse']);
	}
	// Le mot de passe n'est jamais gardé : seulement ce qu'il faut pour remettre le formulaire comme il était
	terminerEnregistrement(['champs' => ['username' => $field_cache['username'], 'email' => $field_cache['email']], 'masaction' => $masaction]);
}

// L'action cochée dans le formulaire : celle de l'envoi qui vient d'avoir lieu, sinon « créer »
$masaction_checked = (($suite['masaction'] ?? 'create') === 'modify') ? 'modify' : 'create';

// La vue : ce que la personne voit, à partir des variables ci-dessus
include PORTAIL_DIRECTORY.'pages/utilisateur/masalteruserform/masalteruserform.vue.php';
