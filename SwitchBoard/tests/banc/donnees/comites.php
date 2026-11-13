<?php
// Les comités inventés du banc : aucune donnée réelle. Les numéros de téléphone sont dans le bloc
// 555-01xx réservé aux fictions, les courriels dans le domaine .test, et chaque mot de passe voip.ms
// est « banc-<compte> », comme l'attend faux/voipms.php.
const MOT_DE_PASSE_PORTAIL = 'Banc-motdepasse-1';

const COMITES = [
	1 => ['nom' => 'admin', 'privilege' => -1, 'courriel' => 'admin@banc.test', 'compte' => '', 'groupe' => '', 'did' => '', 'services' => []],
	// Comité local autonome : seul sur son compte voip.ms
	2 => ['nom' => 'autonome', 'privilege' => 10, 'courriel' => 'autonome@banc.test', 'compte' => 'banc-autonome', 'groupe' => 'Groupe autonome', 'did' => '5145550101',
		'services' => ['ringgroupupdate', 'smsdaybefore', 'smshourbefore', 'sendstats']],
	// Compte principal : paie le compte voip.ms de la région, sans agenda ni DID
	3 => ['nom' => 'region', 'privilege' => 10, 'courriel' => 'region@banc.test', 'compte' => 'banc-region', 'groupe' => '', 'did' => '', 'services' => ['sendstats', 'mainaccount']],
	// Comités locaux membres : même compte voip.ms que le compte principal
	4 => ['nom' => 'membre1', 'privilege' => 10, 'courriel' => 'membre1@banc.test', 'compte' => 'banc-region', 'groupe' => 'Groupe membre 1', 'did' => '5145550102',
		'services' => ['ringgroupupdate', 'sendstats']],
	5 => ['nom' => 'membre2', 'privilege' => 10, 'courriel' => 'membre2@banc.test', 'compte' => 'banc-region', 'groupe' => 'Groupe membre 2', 'did' => '5145550103',
		'services' => ['ringgroupupdate', 'sendstats']],
	// Comité qui utilise SwitchBoard Chat
	6 => ['nom' => 'chat', 'privilege' => 10, 'courriel' => 'chat@banc.test', 'compte' => 'banc-chat', 'groupe' => 'Groupe chat', 'did' => '5145550104', 'services' => ['sbchatupdate']],
	// Le compte de service que genstatsLAA.php cherche par son nom
	7 => ['nom' => 'LigneProvinciale', 'privilege' => 10, 'courriel' => 'provincial@banc.test', 'compte' => 'banc-laa', 'groupe' => '', 'did' => '', 'services' => []],
];

function chargerComites(): void {
	$bd = connexionBD();
	$crypto = new Chiffrement();
	$hachage = password_hash(MOT_DE_PASSE_PORTAIL, PASSWORD_DEFAULT);

	$user = $bd->prepare('INSERT INTO `user` (iduser, username, password, privilegelevel) VALUES (?, ?, ?, ?)');
	$info = $bd->prepare('INSERT INTO `userinfo` (iduser, voipmsusername, voipmsapipassword, calendarurl, validationfeedbackemail, ringgroupname, didnumber, language)'
		.' VALUES (?, ?, ?, ?, ?, ?, ?, "fr")');
	$service = $bd->prepare('INSERT INTO `userservice` (iduser, ringgroupupdate, smsdaybefore, smshourbefore, sendstats, sbchatupdate, mainaccount) VALUES (?, ?, ?, ?, ?, ?, ?)');

	foreach (COMITES as $id => $comite) {
		$user->bind_param('issi', $id, $comite['nom'], $hachage, $comite['privilege']);
		$user->execute();

		$motDePasse = $comite['compte'] === '' ? '' : $crypto->encrypt('banc-'.$comite['compte']);
		$agenda = $comite['groupe'] === '' ? '' : "https://agendas.banc.test/c/{$comite['nom']}.ics";
		$info->bind_param('issssss', $id, $comite['compte'], $motDePasse, $agenda, $comite['courriel'], $comite['groupe'], $comite['did']);
		$info->execute();

		$actif = fn(string $nom) => (int)in_array($nom, $comite['services'], true);
		$valeurs = [$id, $actif('ringgroupupdate'), $actif('smsdaybefore'), $actif('smshourbefore'), $actif('sendstats'), $actif('sbchatupdate'), $actif('mainaccount')];
		$service->bind_param('iiiiiii', ...$valeurs);
		$service->execute();
	}

	$jeton = $crypto->encrypt('jeton-banc-chat');
	$chat = $bd->prepare('INSERT INTO `sbchat` (iduser, sbchatapitoken, sbchatcalendarurl, sbchatdidnumber, sbchatserveraddress) VALUES (6, ?, "https://agendas.banc.test/c/chat-sbchat.ics", "5145550104", "https://sbchat.banc.test")');
	$chat->bind_param('s', $jeton);
	$chat->execute();
}
