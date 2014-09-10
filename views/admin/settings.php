<div class="wrap">
	<h2><?php __( 'Azure Active Directory Settings' ); ?></h2>

	<form method="post" action="options.php">

	<?php
		settings_fields( 'aad-settings' );
		do_settings_sections( 'aad-settings' );
		do_settings_sections( 'aad-directory-settings' );

		do_settings_sections( 'aad-group-settings' );

		submit_button();
	?>

	</form>
</div>
