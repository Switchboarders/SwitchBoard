<?php
// Faux Google Sheets : les listes de villes par région de genstatsLAA.php, en CSV. Seules les régions 87
// et 88 ont des lignes. Un numéro absent des listes reste affiché tel quel dans le rapport.
const LIGNES_PAR_FEUILLE = [
	'1T9q-MoFTEyxEX9n5LOFJmpuqBvPBIE43JWkaMesjSF4' => "Laval,514-555-0187\n",
	'18_oZHHxIYrUj0BRWrJTpyMwPf93mUm2ziWWCuHaApPk' => "Longueuil,1-514-555-0188\n",
];
header('Content-Type: text/csv');
echo "Villes,Telephone\n";
foreach (LIGNES_PAR_FEUILLE as $feuille => $lignes) {
	if (str_contains($chemin, $feuille)) {
		echo $lignes;
	}
}
