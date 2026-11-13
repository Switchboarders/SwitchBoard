<?php
echo "<section>
		<h1>"._("Page de validation")."</h1>  
		</section>";
		echo boutonRetour($this->webpageparent->databasemanager);
		$this->webpageparent->initiatecalendar();
		try {
			echo htmlDeLaValidation($this->webpageparent->calendarmanager->validerAgenda());
		} catch (\Throwable $erreur) {
			echo "<div class='validation-warning'>".htmlspecialchars(TraitementComite::messageErreurPourPage($erreur))."</div>";
		}
?>