<?php
// Test du droit d'agir sur un compte (accesUtilisateurPermis() de authhelpers.php) : un
// utilisateur ordinaire n'accède qu'au sien, même en forgeant iduser dans l'URL. Et de estAdmin(),
// sur lequel reposent requireAdmin() et le contrôle des routes du portail. Et de estSessionSansUtilisateur(),
// qui fait fermer par le portail la session d'un utilisateur supprimé.
//
// Usage : php accesutilisateur_test.php (aucun réseau, aucune BD)

require_once dirname(__FILE__,2).'/src/commun/lesconstantes.php';

// DatabaseManager sans connexion : l'utilisateur courant et la table user sont imposés. Le niveau false est
// celui d'une session dont l'utilisateur a été supprimé (getcurrentuserprivilegelevel() ne trouve plus sa ligne).
class FausseBD extends DatabaseManager {
	public function __construct(private int $iduserCourant, private int|false $niveau, private array $utilisateurs = []) {}
	public function getcurrentuserid() { return $this->iduserCourant; }
	public function getcurrentuserprivilegelevel() { return $this->niveau; }
	public function getuserbyid($iduser) { return $this->utilisateurs[$iduser] ?? false; }
}

require_once __DIR__.'/verifie.php';

$utilisateur = new FausseBD(5, 10);
verifie(accesUtilisateurPermis($utilisateur, 5), "un utilisateur accède à son compte");
verifie(accesUtilisateurPermis($utilisateur, '5'), "iduser reçu de l'URL, sous forme de texte");
verifie(!accesUtilisateurPermis($utilisateur, 6), "un utilisateur n'accède pas au compte d'un autre");
verifie(!accesUtilisateurPermis($utilisateur, 'abc'), "iduser invalide : refusé");
verifie(accesUtilisateurPermis(new FausseBD(1, 0), 6), "un co-administrateur accède au compte d'un autre");
verifie(accesUtilisateurPermis(new FausseBD(1, -1), 6), "l'administrateur principal accède au compte d'un autre");

verifie(estAdmin(new FausseBD(1, 0)) && estAdmin(new FausseBD(1, -1)), "niveaux 0 et -1 : administrateur");
verifie(!estAdmin($utilisateur), "niveau 10 : pas administrateur");
$supprime = new FausseBD(5, false);
verifie(!estAdmin($supprime), "session d'un utilisateur supprimé : pas administrateur");
verifie(!accesUtilisateurPermis($supprime, 6), "session d'un utilisateur supprimé : pas d'accès au compte d'un autre");

$_SESSION = ['authenticated' => true, 'iduser' => 5];
verifie(!estSessionSansUtilisateur(new FausseBD(5, 10, [5 => ['username' => 'comite']])), "session d'un utilisateur qui existe : elle reste ouverte");
verifie(estSessionSansUtilisateur($supprime), "session d'un utilisateur supprimé : elle est à fermer");
$_SESSION = [];
verifie(!estSessionSansUtilisateur($supprime), "aucune session ouverte : rien à fermer");

echo $echecs === 0 ? "\nTous les tests ont réussi\n" : "\n$echecs test(s) en échec\n";
exit($echecs === 0 ? 0 : 1);
