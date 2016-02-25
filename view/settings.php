<div class="wrap">

	<h2><?php echo __( 'Single Sign-on with Azure Active Directory' , AADSSO ); ?></h2>
	<p><?php echo __( 'Settings for configuring single sign-on with Azure Active Directory can be configured here.', AADSSO ); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'aadsso_settings' );
		do_settings_sections( 'aadsso_settings_page' );
		submit_button();
		?>
	</form>

	<h3><?php echo __('Reset Plugin', AADSSO); ?></h3>
	<p>
		<?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			wp_nonce_url(
				admin_url( 'options-general.php?page=aadsso_settings' ),
				'aadsso_reset_settings',
				'aadsso_nonce'
			),
			__( 'Reset Settings', AADSSO ),
			__( 'Reset the plugin to default settings. Careful, there is no undo for this.', AADSSO )
		)
		?>
	</p>
</div>
