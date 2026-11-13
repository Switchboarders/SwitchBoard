	<style>
	.copy-link {
		color: #0066cc;
		text-decoration: none;
		cursor: pointer;
		padding: 0px 10px;
		border-radius: 4px;
		transition: background 0.2s;
		}
		.copy-link:hover {
		background: #f0f0f0;
		}
		.copy-link.copied {
		color: #00aa00;
		}
	</style>
    <script>
		function copyText(text, element) {
      navigator.clipboard.writeText(text).then(() => {
        // Success feedback
        const original = element.textContent;
        element.textContent = '✓ Copied!';
        element.classList.add('copied');

        // Reset after 2 seconds
        setTimeout(() => {
          element.textContent = original;
          element.classList.remove('copied');
        }, 2000);
      }).catch(err => {
        console.error('Failed to copy:', err);
        alert('Failed to copy to clipboard');
      });
    }
		function validateForm() {
		  window.location.reload();

		}


	</script>
		<section>
		<div class="login">
		<div class="login-triangle"></div>
		 <?php echo boutonRetour($this->webpageparent->databasemanager); ?>
		<h2 class="login-header"><?php echo(_("Informations SwitchBoard Chat"));?></h2>

		<form id="modifymacroform" name="modifymacroform" class="login-container" action=""  onsubmit="return validateForm()" method="post">
			<?php echo ligneNomUtilisateur($this->webpageparent->databasemanager,$currentuserid); ?>
			<div class="login-title"><?php echo(_("Modifier ou insérer Macro SB Chat:"));?></div>
			
			<?php
			if($modifierUneMacro && $macro === null){
				echo "No macro with this name !!";
			}elseif($modifierUneMacro){
				?>
				<?php echo csrfField();?>
				<input type="hidden" name="id_macro" value="<?php echo htmlspecialchars($macro['id_macro']);?>">
				<div class="login-title"><?php echo(_("Nom du macro : "));?></div>
				<p><input value="<?php echo htmlspecialchars($macro['macro_name']);?>" name="macro_name" type="text" placeholder="<?php echo(_("Nom du macro"));?>"></p>
				<div class="login-title"><?php echo(_("Texte du macro : "));?></div>
				<p><textarea name="macro_text" placeholder="<?php echo(_("Texte du macro"));?>"><?php echo htmlspecialchars($macro['macro_text']);?></textarea></p>
				<div class="login-title"><?php echo(_("Tag du macro : "));?></div>
				<p>
				<select name="macro_tags">
					<option value="" <?php echo empty($macro['macro_tags']) ? 'selected' : '';?>><?php echo(_("Aucun"));?></option>
					<option value="newcomer" <?php echo ($macro['macro_tags'] === 'newcomer') ? 'selected' : '';?>>newcomer</option>
					<option value="newcomerweb" <?php echo ($macro['macro_tags'] === 'newcomerweb') ? 'selected' : '';?>>newcomerweb</option>
					<option value="no_respondent" <?php echo ($macro['macro_tags'] === 'no_respondent') ? 'selected' : '';?>>no_respondent</option>
				</select>
				</p>
				<?php /* Macro tags config non utilisé pour le moment */ ?>
				<p><input type="submit" value="<?php echo(_("Soumettre"));?>"></p>
				<?php
			}
			echo "<H2 ><A href='managementportal.php?page=sbchatmacros&id_macro=-1&iduser=$currentuserid'>Nouveau macro</A></H2>";

			// Lister les macros existants
			if (empty($sbchatcallcentermacroslist)){
				echo "<p class='macros-vide'>"._("Aucune macro pour ce centre d'appel.")."</p>";
			}
			echo "<table border='1'>";

			foreach($sbchatcallcentermacroslist as $macro){


			  echo "<tr><td colspan='2'>";
			  echo "<center><h2><A href='managementportal.php?page=sbchatmacros&id_macro=".urlencode((string)$macro['id_macro'])."&iduser=$currentuserid'>Edit</A></h2></center></td>";
			  echo "</tr>";
			  echo "<tr><td colspan='2'>";
			  echo "<center>".boutonAction('sbchatmacros', 'Delete', ['iduser' => $currentuserid, 'champs' => ['id_macro' => $macro['id_macro'], 'delete' => 'true'], 'confirmation' => _("Voulez vous vraiment supprimer ce macro ?"), 'titre' => 'h2'])."</center></td>";
			  echo "</tr>";
			  echo "<tr><td>\n";
			  echo "ID macro :</td><td>".htmlspecialchars($macro['id_macro'] ?? '');
			  echo "</td></tr>\n";
			  echo "<tr><td>";
			  echo "ID centre d'appel :</td><td>".htmlspecialchars((string)($macro['id_call_center'] ?? ''));
			  echo "</td></tr>\n";
			  echo "<tr><td>\n";
			  echo "Macro name :</td><td>".htmlspecialchars((string)$macro['macro_name']);
			  echo "</td></tr>\n";
			  echo "<tr><td>";
			  echo "Macro text :</td><td>".htmlspecialchars((string)$macro['macro_text']);
			  echo "</td></tr>\n";
              if(!is_null($macro['macro_tags']) ) {
				echo "<tr><td>";
				echo "Macro tags :</td><td>".htmlspecialchars((string)$macro['macro_tags']);
				echo "</td></tr>\n";

              }else{
                echo "<tr><td>";
				echo "Macro tags :</td><td>No tag for this macro";
				echo "</td></tr>\n";
              }
			  if(!is_null($macro['macro_tags_config']) ) {
				echo "<tr><td>";
				echo "Macro tags config :</td><td>".htmlspecialchars(is_array($macro['macro_tags_config']) ? json_encode($macro['macro_tags_config']) : (string)$macro['macro_tags_config']);
				echo "</td></tr>\n";

			  }else{
				echo "<tr><td>";
				echo "Macro tags config :</td><td>No tag config for this macro";
				echo "</td></tr>\n";
			  }
			  echo "<tr><td colspan='2'>";
			  echo "";
			  echo "</td></tr>\n";
            }
			echo "</table>";
			// Copie morte du formulaire SBChat/MAS retirée d'ici : elle était inatteignable
			// (un exit(0) la précédait), référençait des variables inexistantes et surtout
			// préremplissait le SECRET MAS déchiffré dans un champ texte - exactement ce que
			// la refonte de sécurité interdit. La configuration SBChat/MAS se fait sur
			// ?page=sbchatinfo, la seule référence (voir SBCHAT-SETUP.md).
			?>
		  </form>

		</div>
		</section>


