#!/usr/bin/env php
<?php
// L'export du rapport de comparaison des coûts (ExportComparaisonCouts) : le .xlsx de phpoffice/phpspreadsheet et le CSV. Le
// classeur est relu par la même bibliothèque : feuilles, valeurs venues de voip.ms, et formules recalculées (totaux, moyennes,
// conversion en CAD au taux de la feuille). À relancer à chaque mise à jour de phpoffice/phpspreadsheet.
require_once '/banc/commun/banc.php';

const XLSX = '/tmp/banc-comparaison.xlsx';

$matrice = [
	'dateFrom' => '2026-09-01', 'dateTo' => '2026-10-31',
	'monthSlices' => [['label' => '2026-09'], ['label' => '2026-10']],
	'accounts' => [
		['username' => 'compte-a', 'monthlyCosts' => ['2026-09' => 12.5, '2026-10' => 7.25], 'periodTotal' => 19.75, 'monthlyAverage' => 9.88, 'balance' => 42.1, 'errors' => []],
		['username' => 'compte-é', 'monthlyCosts' => ['2026-09' => 3], 'periodTotal' => 3, 'monthlyAverage' => 3, 'balance' => null, 'errors' => ['solde illisible']],
	],
	'totalParMois' => ['2026-09' => 15.5, '2026-10' => 7.25], 'grandTotalAllAccounts' => 22.75, 'combinedBalance' => 42.1,
];
$export = new ExportComparaisonCouts();

// --- Le .xlsx ---
@unlink(XLSX);
(new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($export->buildXlsxSpreadsheet($matrice)))->save(XLSX);
$classeur = \PhpOffice\PhpSpreadsheet\IOFactory::createReader('Xlsx')->load(XLSX);
verifie($classeur->getSheetNames() === ['Sommaire (USD)', 'Sommaire (CAD)'], "xlsx : deux feuilles, USD puis CAD (".implode(', ', $classeur->getSheetNames()).")");
$usd = $classeur->getSheet(0);
$valeur = fn($feuille, string $cellule) => $feuille->getCell($cellule)->getCalculatedValue();
verifie([$usd->getCell('A1')->getValue(), $usd->getCell('B1')->getValue(), $usd->getCell('E1')->getValue()] === ['Compte', '2026-09', 'Moyenne mensuelle'], "xlsx : l'en-tête a les mois et les colonnes de calcul");
verifie($usd->getCell('A3')->getValue() === 'compte-é' && $usd->getCell('C3')->getValue() === null, "xlsx : un compte accentué, et un mois sans donnée reste vide");
verifie($usd->getCell('D2')->isFormula() && abs($valeur($usd, 'D2') - 19.75) < 1e-9 && abs($valeur($usd, 'E2') - 9.875) < 1e-9, "xlsx : total et moyenne d'un compte sont des formules justes (".$valeur($usd, 'D2')." ; ".$valeur($usd, 'E2').")");
verifie($usd->getCell('A4')->getValue() === 'TOTAL' && abs($valeur($usd, 'D4') - 22.75) < 1e-9 && abs($valeur($usd, 'F4') - 42.1) < 1e-9, "xlsx : la ligne TOTAL additionne les comptes et les soldes (".$valeur($usd, 'D4')." ; ".$valeur($usd, 'F4').")");
verifie($usd->getCell('G3')->getValue() === 'solde illisible', "xlsx : l'erreur d'un compte est à droite de sa ligne");
$cad = $classeur->getSheet(1);
$taux = (float)$cad->getCell('B2')->getValue();
verifie($taux > 0 && abs($valeur($cad, 'B5') - 12.5 * $taux) < 1e-9 && abs($valeur($cad, 'D7') - 22.75 * $taux) < 1e-9, "xlsx : la feuille CAD convertit au taux de sa cellule B2 ($taux)");
verifie($valeur($cad, 'C6') === '', "xlsx : un mois vide reste vide en CAD, pas 0,00 $");
verifie($usd->getStyle('B2')->getNumberFormat()->getFormatCode() === '#,##0.00" $ US"', "xlsx : les montants ont le format en dollars US");

// --- Le CSV ---
$csv = $export->buildCsv($matrice);
$lignes = array_map(fn(string $ligne) => str_getcsv($ligne, ',', '"', '\\'), explode("\n", trim(substr($csv, 3))));
verifie(str_starts_with($csv, "\xEF\xBB\xBF") && count($lignes) === 4, "csv : le BOM pour Excel, l'en-tête, deux comptes et le total");
verifie($lignes[3] === ['TOTAL', '15.5', '7.25', '22.75', '11.38', '42.1'], "csv : la ligne TOTAL (".implode(' ; ', $lignes[3]).")");

@unlink(XLSX);
finDuScenario();
