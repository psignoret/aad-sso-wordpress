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
	<p>Resetting the plugin will completely remove all settings.</p>
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
	<?php if( defined( 'AADSSO_SETTINGS_PATH' ) && file_exists( AADSSO_SETTINGS_PATH ) ): ?>
		<h3>Migrate Plugin</h3>
		<p>Old configuration data was found at <code><?php echo esc_html( AADSSO_SETTINGS_PATH ); ?></code>.
		It can be migrated automatically.</p>
		<p>Delete the file at <code><?php echo esc_html( AADSSO_SETTINGS_PATH ); ?></code> 
			or unset the <code>AADSSO_SETTINGS_PATH</code> constant to hide this migration utility.</p>
		<p><?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			wp_nonce_url(
				admin_url('options-general.php?page=aadsso_settings' ),
				'aadsso_migrate_from_json',
				'aadsso_nonce'
			),
			'Migrate Settings',
			'Migrate settings from old plugin versions to new configuration. This will overwrite existing settings! Careful, there is no undo for this.'
		)
		?></p>
		
	<?php endif; ?>
</div>
