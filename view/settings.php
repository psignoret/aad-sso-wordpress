<div class="wrap">

	<h2>Single Sign-on with Azure Active Directory</h2>
	<p>Settings for configuring single sign-on with Azure Active Directory can be configured
		here.</p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'aadsso_settings' );
		do_settings_sections( 'aadsso_settings_page' );
		submit_button();
		?>
	</form>

	<h3>Reset Plugin</h3>
	<p>
		<?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			wp_nonce_url(
				admin_url('options-general.php?page=aadsso_settings' ),
				'aadsso_reset_settings',
				'aadsso_nonce'
			),
			'Reset Settings',
			'Reset the plugin to default settings. Careful, there is no undo for this.'
		)
		?>
	</p>
</div>
