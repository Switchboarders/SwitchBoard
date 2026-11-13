<?php
// Invitation Element X pour un utilisateur MAS : un lien personnel (lien de « provisioning » d'Element X,
// iOS 25.05.2+ et Android 25.06.0+) ouvre l'application avec le serveur choisi et le nom d'utilisateur déjà
// rempli sur la page de connexion MAS. La personne n'a plus qu'à installer l'application, toucher le lien
// et taper son mot de passe. Le lien n'ouvre pas l'inscription : seuls les comptes créés ici peuvent entrer.
// Sur ordinateur (Windows, Mac, Linux), Element n'accepte aucun lien de ce genre : le message donne les
// étapes à suivre (choisir le serveur une fois sur l'écran de connexion).

const MAS_INVITE_IOS_URL = "https://apps.apple.com/app/element-x-secure-chat-call/id1631335820";
const MAS_INVITE_ANDROID_URL = "https://play.google.com/store/apps/details?id=io.element.android.x";
const MAS_INVITE_DESKTOP_URL = "https://element.io/download";
const MAS_INVITE_WEB_URL = "https://app.element.io";

function mas_invitation_link($username, $servername){
	return "https://mobile.element.io/element/?".http_build_query([
		'account_provider' => $servername,
		'login_hint' => "mxid:@".$username.":".$servername,
	], '', '&', PHP_QUERY_RFC3986);
}

// Là où le message met le mot de passe quand il vient d'être choisi dans ce formulaire : le serveur ne le connaît plus après
// la redirection (jamais en session), le navigateur le remet à cette place (voir render_mas_invitation()).
const MAS_INVITE_MARQUE_MOT_DE_PASSE = "@@MOT_DE_PASSE@@";

// $password : seulement s'il vient d'être choisi dans ce formulaire (sinon le message n'en parle pas).
function mas_invitation_message($username, $servername, $password){
	$link = mas_invitation_link($username, $servername);
	$lines = [
		_("Bonjour,"),
		"",
		_("Voici comment installer Element X, l'application de clavardage de SwitchBoard. Cela prend deux minutes."),
		"",
		_("1. Installez l'application Element X :"),
		"   iPhone : ".MAS_INVITE_IOS_URL,
		"   Android : ".MAS_INVITE_ANDROID_URL,
		"",
		_("2. Sur votre téléphone, touchez votre lien personnel ci-dessous. Il ouvre Element X avec le bon serveur et votre nom d'utilisateur déjà remplis. Si l'application n'était pas encore installée, installez-la puis touchez le lien de nouveau."),
		"   ".$link,
		"",
	];
	if($password !== null && $password !== ''){
		$lines[] = _("3. Touchez « Continuer », puis entrez votre mot de passe :");
		$lines[] = "   ".$password;
	}else{
		$lines[] = _("3. Touchez « Continuer », puis entrez le mot de passe que nous vous avons donné.");
	}
	$lines[] = "";
	$lines[] = _("Sur un ordinateur (Windows, Mac ou Linux) :");
	$lines[] = _("1. Installez Element pour ordinateur (une autre application qu'Element X) :");
	$lines[] = "   ".MAS_INVITE_DESKTOP_URL;
	$lines[] = sprintf(_("2. Ouvrez Element et cliquez sur « Se connecter ». À côté du nom du serveur, cliquez sur « Modifier », entrez %s, puis cliquez sur « Continuer »."), $servername);
	$lines[] = _("3. Une page de connexion s'ouvre : entrez votre nom d'utilisateur et votre mot de passe.");
	$lines[] = sprintf(_("Sans rien installer, vous pouvez aussi utiliser Element dans votre navigateur : %s (mêmes étapes 2 et 3)."), MAS_INVITE_WEB_URL);
	$lines[] = "";
	$lines[] = sprintf(_("Votre nom d'utilisateur : %s"), "@".$username.":".$servername);
	return implode("\n", $lines);
}

// $motDePasseDefini : un mot de passe vient d'être choisi dans ce formulaire. Il n'est jamais passé à cette fonction : la page qui
// suit la redirection n'a que le message sans mot de passe (« le mot de passe que nous vous avons donné »), plus la même version
// avec la marque. Le navigateur, qui a gardé le mot de passe tapé le temps de la redirection (sessionStorage, un seul onglet,
// jamais envoyé au serveur), remplace la marque, affiche l'avertissement et efface sa copie.
function render_mas_invitation($username, $servername, $motDePasseDefini = false){
	$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
	if(empty($servername)){
		echo "<div class='alert alert-warning'>"._("Invitation Element X impossible : l'adresse du serveur MAS n'est pas configurée.")."</div>";
		return;
	}
	$link = mas_invitation_link($username, $servername);
	$message = mas_invitation_message($username, $servername, null);
	$messageAvecMotDePasse = $motDePasseDefini ? mas_invitation_message($username, $servername, MAS_INVITE_MARQUE_MOT_DE_PASSE) : '';
	?>
	<div class="masinvitation"><?php /* pas "container" : style.css cache les <input> de .container */ ?>
		<h2><?php echo $h(sprintf(_("Invitation Element X pour %s"), "@".$username.":".$servername)); ?></h2>
		<p><?php echo $h(_("Envoyez ce message à la personne (courriel ou texto). Elle installe Element X, touche son lien personnel et tape son mot de passe : le serveur et son nom d'utilisateur sont déjà remplis.")); ?></p>
		<p><?php echo $h(_("Le message explique aussi comment se connecter sur un ordinateur (Windows, Mac ou Linux) avec Element. Sur ordinateur, il n'existe pas de lien qui remplit le serveur : la personne l'entre une fois sur l'écran de connexion.")); ?></p>
		<p id="masinvitationavertissement" hidden><strong><?php echo $h(_("Le message contient le mot de passe : il n'est affiché qu'une fois. Pour plus de sécurité, vous pouvez l'enlever du message et l'envoyer par un autre moyen (texto, téléphone).")); ?></strong></p>
		<textarea id="masinvitationmessage" readonly rows="24" style="width:100%; max-width:46em; font-family:inherit;"<?php if($motDePasseDefini){ ?> data-avec-mot-de-passe="<?php echo $h($messageAvecMotDePasse); ?>" data-marque="<?php echo $h(MAS_INVITE_MARQUE_MOT_DE_PASSE); ?>"<?php } ?>><?php echo $h($message); ?></textarea>
		<p>
			<button type="button" class="btn btn-primary" id="masinvitationcopy"><?php echo $h(_("Copier le message")); ?></button>
			<span id="masinvitationcopied" hidden><?php echo $h(_("Copié !")); ?></span>
		</p>
		<p><?php echo $h(_("Lien personnel :")); ?> <a href="<?php echo $h($link); ?>" target="_blank" rel="noopener"><?php echo $h($link); ?></a></p>
		<p><?php echo $h(_("Si la personne lit le message sur un ordinateur, elle peut aussi scanner ce code avec l'appareil photo de son téléphone :")); ?></p>
		<div id="masinvitationqr" data-link="<?php echo $h($link); ?>" style="background:#fff; display:inline-block; padding:8px;"></div>
		<p>
			<button type="button" class="btn btn-secondary" id="masinvitationqrcopy" hidden><?php echo $h(_("Copier l'image du code QR")); ?></button>
			<a class="btn btn-secondary" id="masinvitationqrdownload" download="<?php echo $h("invitation-element-x-".$username.".png"); ?>" hidden><?php echo $h(_("Télécharger l'image du code QR")); ?></a>
			<span id="masinvitationqrcopied" hidden><?php echo $h(_("Image copiée ! Collez-la dans votre courriel.")); ?></span>
		</p>
		<p><?php echo $h(_("Vous pouvez aussi faire un clic droit sur l'image, puis « Copier l'image ».")); ?></p>
	</div>
	<script src="portail/pages/utilisateur/qrcode.js"></script>
	<script>
	(function(){
		var box = document.getElementById('masinvitationqr');
		if(box && typeof qrcode === 'function'){
			var qr = qrcode(0, 'M');
			qr.addData(box.getAttribute('data-link'));
			qr.make();
			// Une vraie image PNG (pas un SVG) : elle se copie et se colle dans un courriel
			var cell = 6, margin = 4, count = qr.getModuleCount(), size = (count + 2 * margin) * cell;
			var canvas = document.createElement('canvas');
			canvas.width = size; canvas.height = size;
			var ctx = canvas.getContext('2d');
			ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, size, size);
			ctx.fillStyle = '#000000';
			for(var r = 0; r < count; r++){
				for(var c = 0; c < count; c++){
					if(qr.isDark(r, c)){ ctx.fillRect((c + margin) * cell, (r + margin) * cell, cell, cell); }
				}
			}
			var png = canvas.toDataURL('image/png');
			var img = document.createElement('img');
			img.src = png; img.width = size; img.height = size;
			img.alt = <?php echo json_encode(_("Code QR de l'invitation Element X"), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
			box.innerHTML = '';
			box.appendChild(img);
			var download = document.getElementById('masinvitationqrdownload');
			download.href = png; download.hidden = false;
			var qrcopy = document.getElementById('masinvitationqrcopy');
			if(navigator.clipboard && window.ClipboardItem){
				qrcopy.hidden = false;
				qrcopy.addEventListener('click', function(){
					canvas.toBlob(function(blob){
						navigator.clipboard.write([new ClipboardItem({'image/png': blob})]).then(function(){
							document.getElementById('masinvitationqrcopied').hidden = false;
						}, function(){ qrcopy.hidden = true; });
					}, 'image/png');
				});
			}
		}
		var button = document.getElementById('masinvitationcopy');
		var text = document.getElementById('masinvitationmessage');
		var done = document.getElementById('masinvitationcopied');
		// Le mot de passe tapé avant l'envoi du formulaire : gardé par le navigateur seulement, lu une fois, puis effacé
		var motDePasse = null;
		try{ motDePasse = sessionStorage.getItem('sb_mas_mot_de_passe'); sessionStorage.removeItem('sb_mas_mot_de_passe'); }catch(e){}
		if(text && motDePasse && text.getAttribute('data-avec-mot-de-passe')){
			text.value = text.getAttribute('data-avec-mot-de-passe').split(text.getAttribute('data-marque')).join(motDePasse);
			document.getElementById('masinvitationavertissement').hidden = false;
		}
		if(button && text){
			button.addEventListener('click', function(){
				var shown = function(){ done.hidden = false; };
				var fallback = function(){ text.select(); try{ document.execCommand('copy'); shown(); }catch(e){} };
				if(navigator.clipboard && navigator.clipboard.writeText){
					navigator.clipboard.writeText(text.value).then(shown, fallback);
				}else{
					fallback();
				}
			});
		}
	})();
	</script>
	<?php
}
