<?php
// Test des éléments communs aux pages du portail (authhelpers.php) : destination du bouton de
// retour selon l'utilisateur, et ligne du nom d'utilisateur (droit de voir, échappement).
//
// Usage : php elementscommuns_test.php (aucun réseau, aucune BD)

require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';

// DatabaseManager sans connexion : l'utilisateur courant et la table user sont imposés
class FausseBD extends DatabaseManager {
	public function __construct(private int $iduserCourant, private int $niveau, private array $utilisateurs = []) {}
	public function getcurrentuserid() { return $this->iduserCourant; }
	public function getcurrentuserprivilegelevel() { return $this->niveau; }
	public function getuserbyid($iduser) { return $this->utilisateurs[$iduser] ?? null; }
}

$echecs = 0;
function verifie($condition, $description) {
	global $echecs;
	echo ($condition ? "OK    " : "ÉCHEC ").$description."\n";
	if (!$condition) $echecs++;
}

$console = "page=adminpanel";
$tableauDeBord = "page=mainpage";
$admin = new FausseBD(1, 0);
$utilisateur = new FausseBD(5, 10);

verifie(str_contains(boutonRetourConsole(), $console), "pages d'administration : retour à la console");

$_GET = [];
verifie(str_contains(boutonRetour($admin), $tableauDeBord), "admin sur sa propre page : retour au tableau de bord");
verifie(str_contains(boutonRetour($utilisateur), $tableauDeBord), "utilisateur : retour au tableau de bord");

$_GET = ['iduser' => '5'];
verifie(str_contains(boutonRetour($admin), $console), "admin sur la page d'un utilisateur : retour à la console");
verifie(str_contains(boutonRetour($utilisateur), $tableauDeBord), "utilisateur avec iduser dans l'URL : pas de console");

$comptes = [5 => ['username' => 'Comité <b>'], 6 => ['username' => 'Autre']];
$admin = new FausseBD(1, 0, $comptes);
$utilisateur = new FausseBD(5, 10, $comptes);

verifie(str_contains(ligneNomUtilisateur($admin, 6), "<i>Autre</i>"), "un admin voit le nom d'un autre utilisateur");
verifie(str_contains(ligneNomUtilisateur($utilisateur, '5'), "Comité &lt;b&gt;"), "un utilisateur voit son nom, échappé");
verifie(ligneNomUtilisateur($utilisateur, 6) === "", "iduser forgé par un utilisateur : rien d'affiché");
verifie(ligneNomUtilisateur($admin, 99) === "", "iduser inexistant : rien d'affiché");

echo $echecs === 0 ? "\nTous les tests ont réussi\n" : "\n$echecs test(s) en échec\n";
exit($echecs === 0 ? 0 : 1);
