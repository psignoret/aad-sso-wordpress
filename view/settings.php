<div class="wrap">
	<h2>Azure Active Directory Single Sign-on Settings</h2>
	<form method="post" action="options.php">
	<?php
		settings_fields( 'aadsso_settings' );
		do_settings_sections( 'aadsso_settings' );
		submit_button();
	?>
	</form>
</div>
