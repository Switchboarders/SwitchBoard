<?php
// Fragment HTML de l'aperçu du rapport + formulaires de téléchargement. Rendu dans un tampon de
// sortie et renvoyé en JSON par AdminPanel::displayMultiAccountCostReportPage() (action
// render_result), puis injecté par JS dans multiaccountcostreportpage.php - jamais affiché
// directement via le flux Webpage habituel. Attend $matrix (voir
// TacheComparaisonCouts::obtenirMatrice()) et $jobToken déjà définis par l'appelant.
?>
<h3><?php echo(_("Aperçu"));?></h3>
<div style="overflow-x:auto;">
<table style="border-collapse:collapse;text-align:left;">
	<tr style="font-weight:bold;border-bottom:1px solid #ccc;">
		<th style="padding:4px 8px;"><?php echo(_("Compte"));?></th>
		<?php foreach ($matrix['monthSlices'] as $slice): ?>
		<th style="padding:4px 8px;"><?php echo htmlspecialchars($slice['label']);?></th>
		<?php endforeach; ?>
		<th style="padding:4px 8px;"><?php echo(_("Total période"));?></th>
		<th style="padding:4px 8px;"><?php echo(_("Moyenne mensuelle"));?></th>
		<th style="padding:4px 8px;"><?php echo(_("Solde actuel"));?></th>
	</tr>
	<?php foreach ($matrix['accounts'] as $compte): ?>
	<tr style="border-bottom:1px solid #eee;">
		<td style="padding:4px 8px;"><?php echo htmlspecialchars($compte['username']);?></td>
		<?php foreach ($matrix['monthSlices'] as $slice): ?>
		<td style="padding:4px 8px;"><?php echo isset($compte['monthlyCosts'][$slice['label']]) ? number_format($compte['monthlyCosts'][$slice['label']], 2)._(" $") : '-';?></td>
		<?php endforeach; ?>
		<td style="padding:4px 8px;"><?php echo number_format($compte['periodTotal'], 2)._(" $");?></td>
		<td style="padding:4px 8px;"><?php echo number_format($compte['monthlyAverage'], 2)._(" $");?></td>
		<td style="padding:4px 8px;"><?php echo $compte['balance'] !== null ? number_format($compte['balance'], 2)._(" $") : '-';?></td>
	</tr>
	<?php if (!empty($compte['errors'])): ?>
	<tr>
		<td colspan="<?php echo (3 + count($matrix['monthSlices']));?>" style="padding:2px 8px;color:red;font-size:0.9em;">
			<?php echo htmlspecialchars(implode(' | ', $compte['errors']));?>
		</td>
	</tr>
	<?php endif; ?>
	<?php endforeach; ?>
	<tr style="font-weight:bold;border-top:2px solid #333;">
		<td style="padding:4px 8px;"><?php echo(_("TOTAL"));?></td>
		<?php foreach ($matrix['monthSlices'] as $slice): ?>
		<td style="padding:4px 8px;"><?php echo number_format($matrix['totalParMois'][$slice['label']], 2)._(" $");?></td>
		<?php endforeach; ?>
		<td style="padding:4px 8px;"><?php echo number_format($matrix['grandTotalAllAccounts'], 2)._(" $");?></td>
		<td style="padding:4px 8px;"></td>
		<td style="padding:4px 8px;"><?php echo number_format($matrix['combinedBalance'], 2)._(" $");?></td>
	</tr>
</table>
</div>

<form method="post" action="?page=multiaccountcostreport" style="display:inline;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="download_csv">
	<input type="hidden" name="jobToken" value="<?php echo htmlspecialchars($jobToken);?>">
	<input type="submit" value="<?php echo(_("Télécharger CSV"));?>">
</form>
<form method="post" action="?page=multiaccountcostreport" style="display:inline;">
	<?php echo csrfField(); ?>
	<input type="hidden" name="action" value="download_xlsx">
	<input type="hidden" name="jobToken" value="<?php echo htmlspecialchars($jobToken);?>">
	<input type="submit" value="<?php echo(_("Télécharger Excel"));?>">
</form>
