<?php
// Test de la liste conservée du groupe de sonnerie (issue #63) : GroupeSonnerie::
// mettreAJour() contre le faux compte voip.ms stubvoipmscompte.php, avec une
// base de données en mémoire. Aucun secret réel, aucun réseau au-delà de 127.0.0.1.
//
// Usage : php -S 127.0.0.1:8996 stubvoipmscompte.php & puis php listeconservee_test.php

// Versions minimales des classes dont GroupeSonnerie a besoin, pour ne charger ni la vraie BD ni
// l'envoi de courriels. La fausse BD imite les méthodes de la liste conservée de DatabaseManager.
class DatabaseManager {
	public array $etats = [];
	public function getListeConservee($iduser) {
		return $this->etats[$iduser] ?? null;
	}
	public function enregistrerListeConservee($iduser, $voipmsusername, $ringgroupname, array $membres, bool $resynchronisationComplete) {
		$maintenant = date("Y-m-d H:i:s");
		$this->etats[$iduser] = [
			'voipmsusername' => $voipmsusername,
			'ringgroupname' => $ringgroupname,
			'lastupdate' => $maintenant,
			'lastfullsync' => $resynchronisationComplete ? $maintenant : $this->etats[$iduser]['lastfullsync'],
			'membres' => array_values($membres),
		];
	}
	public function getRenvoisUtilisesAilleurs($iduser, $voipmsusername) {
		$ids = [];
		foreach ($this->etats as $autre => $etat) {
			if ($autre != $iduser && $etat['voipmsusername'] === $voipmsusername) {
				$ids = array_merge($ids, array_column($etat['membres'], 'forwardingid'));
			}
		}
		return $ids;
	}
}
class InformationsUtilisateur {
	public string $voipmsusername = "comptetest";
	public string $voipmsapipassword = "bidon";
	public string $didnumber = "5145550000";
	public string $language = "fr";
	public string $ringgroupname = "";
	public ?int $ringtime = null;
	public ?int $pressone = null;
	public $iduser = 0;
	public $username = "";
	public $databasemanager;
}
// L'amorce déclare l'autoload de src/modules/ (Journal, pour les erreurs au journal)
require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/ClientVoipms.php';
require_once dirname(__FILE__,2).'/src/modules/telephonie/GroupeSonnerie.php';

// La seule différence avec la vraie classe : l'adresse de l'API
class ClientVoipmsFactice extends ClientVoipms {
	protected string $urlApi = "http://127.0.0.1:8996/rest.php";
}

require_once __DIR__.'/verifie.php';

$fichierCompte = __DIR__."/stub-compte.json";
@unlink($fichierCompte);
function compte() {
	global $fichierCompte;
	return json_decode((string) @file_get_contents($fichierCompte), true);
}
function modifierCompte(callable $modification) {
	global $fichierCompte;
	$etat = compte();
	$modification($etat);
	file_put_contents($fichierCompte, json_encode($etat));
}
// Membres actuels du groupe de sonnerie nommé $nom, en numéros de téléphone
function numerosDuGroupe(string $nom): array {
	$etat = compte();
	foreach ($etat['groupes'] as $groupe) {
		if ($groupe['name'] === $nom) {
			return array_map(fn($id) => $etat['renvois'][$id]['phone_number'] ?? "renvoi effacé $id", $groupe['members']);
		}
	}
	return [];
}
// Chaîne des membres reçue par voip.ms pour le groupe nommé $nom, avec les numéros à la place des renvois
function texteDuGroupe(string $nom): string {
	$etat = compte();
	foreach ($etat['groupes'] as $groupe) {
		if ($groupe['name'] === $nom) {
			return preg_replace_callback('/fwd:([^,]+)/', fn($m) => "fwd:".($etat['renvois'][$m[1]]['phone_number'] ?? "?"), $groupe['texte_membres']);
		}
	}
	return "";
}

$bd = new DatabaseManager();
function comite(int $iduser, string $nomGroupe) {
	global $bd;
	$infos = new InformationsUtilisateur();
	$infos->iduser = $iduser;
	$infos->ringgroupname = $nomGroupe;
	$infos->databasemanager = $bd;
	return $infos;
}
// Un passage du cron pour un comité : retourne le résultat et les méthodes voip.ms appelées
function passage(InformationsUtilisateur $infos, array $numeros, bool $forcer = false, array $reglages = []) {
	modifierCompte(function (&$etat) { if ($etat !== null) $etat['journal'] = []; });
	$groupe = new GroupeSonnerie(new ClientVoipmsFactice($infos), $infos, $infos->databasemanager, fn(string $message) => null);
	$resultat = $groupe->mettreAJour($infos->iduser, $numeros, $numeros, $reglages, $forcer);
	return [$resultat, compte()['journal'] ?? [], $groupe->modePassage()];
}

$a = "5145550001"; $b = "5145550002"; $c = "5145550003";
$comiteA = comite(1, "Comite A");
$comiteB = comite(2, "Comite B");

// --- Cas 1 : premier passage, aucune liste conservée : resynchronisation complète ----------
[$resultat, $appels, $mode] = passage($comiteA, [$a, $b]);
verifie($resultat === "success", "Cas 1: le groupe est envoyé ($resultat)");
verifie(str_contains($mode, "aucune liste conservée"), "Cas 1: resynchronisation complète ($mode)");
verifie(numerosDuGroupe("Comite A") === [$a, $b], "Cas 1: le groupe contient A puis B");
verifie(count($bd->etats[1]['membres']) === 2, "Cas 1: la liste est conservée");

// --- Cas 2 : mêmes répondants : aucun appel à voip.ms ---------------------------------------
[$resultat, $appels] = passage($comiteA, [$a, $b]);
verifie($resultat === "aucun changement", "Cas 2: aucun changement ($resultat)");
verifie($appels === [], "Cas 2: aucun appel à voip.ms (".implode(",", $appels).")");

// --- Cas 3 : seul l'ordre change : setRingGroup, aucun renvoi créé ni effacé ----------------
[$resultat, $appels] = passage($comiteA, [$b, $a]);
verifie($resultat === "success", "Cas 3: le groupe est envoyé ($resultat)");
verifie($appels === ["getVoicemails", "getRingGroups", "setRingGroup"], "Cas 3: seulement les lectures et setRingGroup (".implode(",", $appels).")");
verifie(numerosDuGroupe("Comite A") === [$b, $a], "Cas 3: le groupe contient B puis A");

// --- Cas 4 : un répondant remplacé : renvoi créé avant setRingGroup, effacé après ------------
[$resultat, $appels] = passage($comiteA, [$b, $c]);
verifie($appels === ["setForwarding", "getVoicemails", "getRingGroups", "setRingGroup", "getForwardings", "delForwarding"],
	"Cas 4: créer, setRingGroup, puis effacer le partant (".implode(",", $appels).")");
verifie(numerosDuGroupe("Comite A") === [$b, $c], "Cas 4: le groupe contient B puis C");
$numerosDuCompte = array_column(compte()['renvois'], 'phone_number');
verifie(!in_array($a, $numerosDuCompte, true), "Cas 4: le renvoi de A est effacé");

// --- Cas 5 : un renvoi partagé avec un autre comité du même compte n'est pas effacé ----------
[$resultat] = passage($comiteB, [$c]);
verifie($resultat === "success" && numerosDuGroupe("Comite B") === [$c], "Cas 5: le comité B utilise le renvoi de C (used_number)");
[$resultat] = passage($comiteA, [$b]);
verifie(numerosDuGroupe("Comite A") === [$b], "Cas 5: le comité A n'a plus que B");
verifie(numerosDuGroupe("Comite B") === [$c], "Cas 5: le renvoi de C, encore utilisé par B, n'est pas effacé");

// --- Cas 6 : plus personne de garde, puis toujours personne ----------------------------------
[$resultat, $appels] = passage($comiteA, []);
verifie($resultat === "missing_members", "Cas 6: voip.ms refuse le groupe vide, comme avant ($resultat)");
verifie($bd->etats[1]['membres'] === [], "Cas 6: la liste vide est conservée");
verifie(!in_array($b, array_column(compte()['renvois'], 'phone_number'), true), "Cas 6: le renvoi de B est effacé");
[$resultat, $appels] = passage($comiteA, []);
verifie($appels === [], "Cas 6: toujours personne : aucun appel à voip.ms (".implode(",", $appels).")");

// --- Cas 7 : renvoi conservé effacé à la main dans voip.ms : tout est refait au même passage --
passage($comiteA, [$a, $b]);
modifierCompte(function (&$etat) use ($a) {
	foreach ($etat['renvois'] as $id => $renvoi) {
		if ($renvoi['phone_number'] === $a) unset($etat['renvois'][$id]);
	}
});
[$resultat, $appels, $mode] = passage($comiteA, [$b, $a]);
verifie($resultat === "success", "Cas 7: le groupe est quand même envoyé ($resultat)");
verifie(str_contains($mode, "refusé la liste conservée"), "Cas 7: resynchronisation complète au même passage ($mode)");
verifie(numerosDuGroupe("Comite A") === [$b, $a], "Cas 7: le groupe contient B puis A, avec un nouveau renvoi pour A");

// --- Cas 8 : resynchronisation quotidienne, au premier passage après 3 h ----------------------
$bd->etats[1]['lastfullsync'] = date("Y-m-d H:i:s", strtotime("-2 days"));
[$resultat, $appels, $mode] = passage($comiteA, [$b, $a]);
verifie(str_contains($mode, "quotidienne"), "Cas 8: resynchronisation quotidienne ($mode)");
verifie(!in_array("delForwarding", $appels, true) && $resultat === "success", "Cas 8: les renvois existants sont remis à jour, pas effacés (".implode(",", $appels).")");
[, $appels] = passage($comiteA, [$b, $a]);
verifie($appels === [], "Cas 8: une seule fois : le passage suivant n'appelle rien");

// --- Cas 9 : bouton « Forcer » ----------------------------------------------------------------
[$resultat, $appels, $mode] = passage($comiteA, [$b, $a], true);
verifie(str_contains($mode, "demandée") && in_array("setRingGroup", $appels, true), "Cas 9: « Forcer » renvoie tout ($mode)");

// --- Cas 10 : nom du groupe changé dans les réglages du comité ---------------------------------
$comiteA->ringgroupname = "Comite A2";
[$resultat, $appels, $mode] = passage($comiteA, [$b, $a]);
verifie(str_contains($mode, "nom du groupe changé") && numerosDuGroupe("Comite A2") === [$b, $a], "Cas 10: resynchronisation complète vers le nouveau groupe ($mode)");

// --- Cas 11 : durée de sonnerie et « Faire le 1 » (issues #62 et #35) ---------------------------
verifie(texteDuGroupe("Comite A2") === "fwd:$b,25,1;fwd:$a,25,1;", "Cas 11: sans réglage, 25 s et « Faire le 1 » comme avant (".texteDuGroupe("Comite A2").")");
[$resultat, $appels] = passage($comiteA, [$b, $a], false, [['ringtime' => 30, 'pressone' => 0], []]);
verifie($resultat === "success" && $appels === ["getVoicemails", "getRingGroups", "setRingGroup"],
	"Cas 11: seul un réglage change : setRingGroup, aucun renvoi créé ni effacé (".implode(",", $appels).")");
verifie(texteDuGroupe("Comite A2") === "fwd:$b,30,0;fwd:$a,25,1;", "Cas 11: voip.ms reçoit 30 s sans « Faire le 1 » pour B (".texteDuGroupe("Comite A2").")");
[, $appels] = passage($comiteA, [$b, $a], false, [['ringtime' => 30, 'pressone' => 0], []]);
verifie($appels === [], "Cas 11: mêmes réglages au passage suivant : aucun appel à voip.ms");
[, $appels] = passage($comiteA, [$b, $a]);
verifie(in_array("setRingGroup", $appels, true) && texteDuGroupe("Comite A2") === "fwd:$b,25,1;fwd:$a,25,1;", "Cas 11: retour aux valeurs par défaut renvoyé à voip.ms");

@unlink($fichierCompte);

echo $echecs === 0 ? "\nTous les cas de la liste conservée réussissent.\n" : "\n$echecs cas en échec.\n";
exit($echecs === 0 ? 0 : 1);
