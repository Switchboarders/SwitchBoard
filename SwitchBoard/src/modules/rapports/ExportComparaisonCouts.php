<?php
// Transforme la matrice produite par TacheComparaisonCouts::obtenirMatrice() en fichier
// téléchargeable (CSV ou .xlsx via phpoffice/phpspreadsheet). Ne fait aucun appel API voip.ms,
// aucun header() ni exit() - reste un simple convertisseur données -> fichier, appelé depuis
// AdminPanel::displayMultiAccountCostReportPage() qui gère l'envoi HTTP.
//
// voip.ms facture en dollars américains (USD) : la version .xlsx ajoute un second onglet
// convertissant tous les montants en dollars canadiens (CAD), au taux du jour (voir
// TauxDeChange - Banque du Canada, avec repli automatique si injoignable).
//
// Dans le .xlsx, seuls les montants qui viennent directement de voip.ms (coût mensuel, solde)
// sont des valeurs figées - tout le reste (Total période, Moyenne mensuelle, ligne TOTAL, et la
// totalité de l'onglet CAD) est une vraie formule Excel. Le chiffrier reste donc cohérent si
// quelqu'un corrige un montant à la main après le téléchargement. Le CSV, qui n'a pas de notion
// de formule, garde des valeurs figées partout (inchangé).
class ExportComparaisonCouts {

	private function libellesColonnes(array $matrix): array {
		$colonnes = [_("Compte")];
		foreach ($matrix['monthSlices'] as $slice) {
			$colonnes[] = $slice['label'];
		}
		$colonnes[] = _("Total période");
		$colonnes[] = _("Moyenne mensuelle");
		$colonnes[] = _("Solde actuel");
		return $colonnes;
	}

	private function ligneCompte(array $matrix, array $compte): array {
		$ligne = [$compte['username']];
		foreach ($matrix['monthSlices'] as $slice) {
			$ligne[] = $compte['monthlyCosts'][$slice['label']] ?? '';
		}
		$ligne[] = $compte['periodTotal'];
		$ligne[] = $compte['monthlyAverage'];
		$ligne[] = $compte['balance'] ?? '';
		return $ligne;
	}

	private function ligneTotal(array $matrix): array {
		$nbMois = count($matrix['monthSlices']);
		$ligne = [_("TOTAL")];
		foreach ($matrix['monthSlices'] as $slice) {
			$ligne[] = $matrix['totalParMois'][$slice['label']] ?? 0;
		}
		$ligne[] = $matrix['grandTotalAllAccounts'];
		$ligne[] = $nbMois > 0 ? round($matrix['grandTotalAllAccounts'] / $nbMois, 2) : 0;
		$ligne[] = $matrix['combinedBalance'];
		return $ligne;
	}

	/** Construit le contenu CSV complet en mémoire et le retourne comme chaîne. */
	public function buildCsv(array $matrix): string {
		$flux = fopen('php://memory', 'w+');
		// BOM UTF-8 : sans lui, Excel ouvre un CSV UTF-8 contenant des accents français en les
		// affichant mal (mojibake) - un problème propre à Excel, pas aux autres tableurs.
		fwrite($flux, "\xEF\xBB\xBF");

		fputcsv($flux, $this->libellesColonnes($matrix), ",", '"', "\\");
		foreach ($matrix['accounts'] as $compte) {
			fputcsv($flux, $this->ligneCompte($matrix, $compte), ",", '"', "\\");
		}
		fputcsv($flux, $this->ligneTotal($matrix), ",", '"', "\\");

		rewind($flux);
		$contenu = stream_get_contents($flux);
		fclose($flux);
		return $contenu;
	}

	private function ajouterLegende(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, int $ligneDepart, int $nbColonnes): void {
		$legendes = [
			_("Légende :"),
			_("- Une colonne par mois calendaire de la période choisie (le premier et le dernier mois peuvent être partiels selon les dates choisies)."),
			_("- Total période, Moyenne mensuelle et la ligne TOTAL sont des formules (SOMME/MOYENNE) : elles restent exactes si vous corrigez un montant mensuel à la main."),
			_("- Solde actuel : solde du compte voip.ms au moment de la génération de ce rapport, indépendant de la période choisie."),
			_("- La ligne TOTAL additionne tous les comptes sélectionnés, y compris leurs soldes combinés (utile pour évaluer le capital total actuellement réparti entre ces comptes)."),
		];
		foreach ($legendes as $idx => $texte) {
			$feuille->setCellValue([1, $ligneDepart + $idx], $texte);
		}
	}

	/**
	 * Remplit la feuille USD : coût mensuel et solde sont des valeurs (viennent directement de
	 * voip.ms) ; Total période, Moyenne mensuelle et la ligne TOTAL sont des formules Excel
	 * (SUM/AVERAGE) référençant ces valeurs, pas des nombres pré-calculés.
	 */
	private function remplirFeuilleUsd(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, array $matrix, int $ligneEntete): void {
		$colonnes = $this->libellesColonnes($matrix);
		$nbColonnes = count($colonnes);
		$nbMois = count($matrix['monthSlices']);
		$formatMonetaire = '#,##0.00" $ US"';

		$colLettreMoisDebut = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2);
		$colLettreMoisFin = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(1 + max($nbMois, 1));
		$colLettreSolde = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(4 + $nbMois);

		foreach ($colonnes as $idx => $libelle) {
			$feuille->setCellValue([$idx + 1, $ligneEntete], $libelle);
		}
		$feuille->getStyle([1, $ligneEntete, $nbColonnes, $ligneEntete])->getFont()->setBold(true);

		$ligneCourante = $ligneEntete + 1;
		$premiereLigneCompte = $ligneCourante;
		foreach ($matrix['accounts'] as $compte) {
			$feuille->setCellValue([1, $ligneCourante], $compte['username']);
			foreach ($matrix['monthSlices'] as $idxMois => $slice) {
				$valeur = $compte['monthlyCosts'][$slice['label']] ?? '';
				$feuille->setCellValue([2 + $idxMois, $ligneCourante], $valeur);
				if ($valeur !== '') {
					$feuille->getStyle([2 + $idxMois, $ligneCourante])->getNumberFormat()->setFormatCode($formatMonetaire);
				}
			}

			// Total période et Moyenne mensuelle : formules, pas des valeurs figées.
			$feuille->setCellValue([2 + $nbMois, $ligneCourante], "=SUM({$colLettreMoisDebut}{$ligneCourante}:{$colLettreMoisFin}{$ligneCourante})");
			$feuille->setCellValue([3 + $nbMois, $ligneCourante], "=AVERAGE({$colLettreMoisDebut}{$ligneCourante}:{$colLettreMoisFin}{$ligneCourante})");
			$feuille->getStyle([2 + $nbMois, $ligneCourante, 3 + $nbMois, $ligneCourante])->getNumberFormat()->setFormatCode($formatMonetaire);

			$feuille->setCellValue([4 + $nbMois, $ligneCourante], $compte['balance'] ?? '');
			if ($compte['balance'] !== null) {
				$feuille->getStyle([4 + $nbMois, $ligneCourante])->getNumberFormat()->setFormatCode($formatMonetaire);
			}

			if (!empty($compte['errors'])) {
				$feuille->setCellValue([$nbColonnes + 1, $ligneCourante], implode(' | ', $compte['errors']));
				$feuille->getStyle([$nbColonnes + 1, $ligneCourante])->getFont()->getColor()->setRGB('FF0000');
			}
			$ligneCourante++;
		}
		$derniereLigneCompte = $ligneCourante - 1;

		// Ligne TOTAL : entièrement des formules, additionnant les lignes de comptes ci-dessus.
		$ligneTotal = $ligneCourante;
		$feuille->setCellValue([1, $ligneTotal], _("TOTAL"));
		for ($idxMois = 0; $idxMois < $nbMois; $idxMois++) {
			$colLettre = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex(2 + $idxMois);
			$feuille->setCellValue([2 + $idxMois, $ligneTotal], "=SUM({$colLettre}{$premiereLigneCompte}:{$colLettre}{$derniereLigneCompte})");
			$feuille->getStyle([2 + $idxMois, $ligneTotal])->getNumberFormat()->setFormatCode($formatMonetaire);
		}
		$feuille->setCellValue([2 + $nbMois, $ligneTotal], "=SUM({$colLettreMoisDebut}{$ligneTotal}:{$colLettreMoisFin}{$ligneTotal})");
		$feuille->setCellValue([3 + $nbMois, $ligneTotal], "=AVERAGE({$colLettreMoisDebut}{$ligneTotal}:{$colLettreMoisFin}{$ligneTotal})");
		$feuille->setCellValue([4 + $nbMois, $ligneTotal], "=SUM({$colLettreSolde}{$premiereLigneCompte}:{$colLettreSolde}{$derniereLigneCompte})");
		$feuille->getStyle([2 + $nbMois, $ligneTotal, 4 + $nbMois, $ligneTotal])->getNumberFormat()->setFormatCode($formatMonetaire);
		$feuille->getStyle([1, $ligneTotal, $nbColonnes, $ligneTotal])->getFont()->setBold(true);

		foreach (range(1, $nbColonnes) as $idxColonne) {
			$feuille->getColumnDimensionByColumn($idxColonne)->setAutoSize(true);
		}
		$feuille->freezePane('A'.($ligneEntete + 1));

		$this->ajouterLegende($feuille, $ligneTotal + 2, $nbColonnes);
	}

	/**
	 * Remplit la feuille CAD : B1/B2 portent l'étiquette et la valeur du taux de change (une
	 * cellule modifiable à la main - toutes les formules ci-dessous la référencent par $B$2, donc
	 * la changer recalcule tout l'onglet). Chaque cellule du tableau est une formule qui
	 * référence la cellule correspondante de la feuille USD multipliée par $B$2 - aucun montant
	 * n'est recalculé indépendamment ici, la feuille USD reste la seule source de vérité.
	 */
	private function remplirFeuilleCad(\PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $feuille, array $matrix, array $tauxInfo, int $ligneEnteteUsd, string $nomFeuilleUsd): void {
		$colonnes = $this->libellesColonnes($matrix);
		$nbColonnes = count($colonnes);
		$formatMonetaire = '#,##0.00" $ CA"';

		$feuille->setCellValue('B1', _("Taux de conversion"));
		$feuille->getStyle('B1')->getFont()->setBold(true);
		$feuille->setCellValue('B2', $tauxInfo['taux']);
		$feuille->getStyle('B2')->getNumberFormat()->setFormatCode('0.0000');

		$descriptionSource = $tauxInfo['date'] !== null
			? sprintf(_("Source : %s, taux du %s"), $tauxInfo['source'], $tauxInfo['date'])
			: sprintf(_("Source : %s"), $tauxInfo['source']);
		$feuille->setCellValue('A1', $descriptionSource);
		$feuille->getStyle('A1')->getFont()->setItalic(true);

		$ligneEntete = 4;
		$decalageLigne = $ligneEntete - $ligneEnteteUsd;

		foreach ($colonnes as $idx => $libelle) {
			$feuille->setCellValue([$idx + 1, $ligneEntete], $libelle);
		}
		$feuille->getStyle([1, $ligneEntete, $nbColonnes, $ligneEntete])->getFont()->setBold(true);

		$ligneCourante = $ligneEntete + 1;
		foreach ($matrix['accounts'] as $compte) {
			$ligneUsd = $ligneCourante - $decalageLigne;
			$feuille->setCellValue([1, $ligneCourante], "='{$nomFeuilleUsd}'!A{$ligneUsd}");
			for ($col = 2; $col <= $nbColonnes; $col++) {
				$colLettre = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
				// IF(...="","",...) : préserve les cases vides (mois sans donnée pour ce compte)
				// plutôt que d'afficher "0,00 $ CA", ce qui laisserait croire à un coût nul.
				$feuille->setCellValue([$col, $ligneCourante], "=IF('{$nomFeuilleUsd}'!{$colLettre}{$ligneUsd}=\"\",\"\",'{$nomFeuilleUsd}'!{$colLettre}{$ligneUsd}*\$B\$2)");
				$feuille->getStyle([$col, $ligneCourante])->getNumberFormat()->setFormatCode($formatMonetaire);
			}
			if (!empty($compte['errors'])) {
				$colLettreErreur = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($nbColonnes + 1);
				$feuille->setCellValue([$nbColonnes + 1, $ligneCourante], "='{$nomFeuilleUsd}'!{$colLettreErreur}{$ligneUsd}");
				$feuille->getStyle([$nbColonnes + 1, $ligneCourante])->getFont()->getColor()->setRGB('FF0000');
			}
			$ligneCourante++;
		}

		// Ligne TOTAL : référence directement la ligne TOTAL de la feuille USD (déjà une
		// formule là-bas) - pas besoin de refaire les sommes ici.
		$ligneUsdTotal = $ligneCourante - $decalageLigne;
		$feuille->setCellValue([1, $ligneCourante], "='{$nomFeuilleUsd}'!A{$ligneUsdTotal}");
		for ($col = 2; $col <= $nbColonnes; $col++) {
			$colLettre = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col);
			$feuille->setCellValue([$col, $ligneCourante], "='{$nomFeuilleUsd}'!{$colLettre}{$ligneUsdTotal}*\$B\$2");
			$feuille->getStyle([$col, $ligneCourante])->getNumberFormat()->setFormatCode($formatMonetaire);
		}
		$feuille->getStyle([1, $ligneCourante, $nbColonnes, $ligneCourante])->getFont()->setBold(true);

		foreach (range(1, $nbColonnes) as $idxColonne) {
			$feuille->getColumnDimensionByColumn($idxColonne)->setAutoSize(true);
		}
		$feuille->freezePane('A'.($ligneEntete + 1));

		$this->ajouterLegende($feuille, $ligneCourante + 2, $nbColonnes);
	}

	/**
	 * Construit un classeur PhpSpreadsheet avec deux feuilles : "Sommaire (USD)" (montants
	 * voip.ms tels quels, voip.ms facture en USD) et "Sommaire (CAD)" (les mêmes montants, en
	 * formules, convertis au taux du jour - voir TauxDeChange, qui se replie
	 * automatiquement sur un taux par défaut si la source externe est injoignable).
	 */
	public function buildXlsxSpreadsheet(array $matrix): \PhpOffice\PhpSpreadsheet\Spreadsheet {

		$spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();

		$feuilleUsd = $spreadsheet->getActiveSheet();
		$feuilleUsd->setTitle(_("Sommaire (USD)"));
		$ligneEnteteUsd = 1;
		$this->remplirFeuilleUsd($feuilleUsd, $matrix, $ligneEnteteUsd);

		$tauxInfo = (new TauxDeChange())->obtenirTauxUsdVersCad();
		$feuilleCad = $spreadsheet->createSheet();
		$feuilleCad->setTitle(_("Sommaire (CAD)"));
		$this->remplirFeuilleCad($feuilleCad, $matrix, $tauxInfo, $ligneEnteteUsd, $feuilleUsd->getTitle());

		$spreadsheet->setActiveSheetIndex(0);
		return $spreadsheet;
	}
}

?>
