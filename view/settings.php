<div class="wrap">

	<h2><?php echo esc_html__('Single Sign-on with Azure Active Directory', AADSSO); ?></h2>
	<p><?php echo esc_html__('Settings for configuring single sign-on with Azure Active Directory can be configured here.', AADSSO); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'aadsso_settings' );
		do_settings_sections( 'aadsso_settings_page' );
		submit_button();
		?>
	</form>

	<h3><?php echo esc_html__( 'Reset Plugin' , AADSSO ); ?></h3>
	<p><?php echo __( 'Resetting the plugin will completely remove all settings.' , AADSSO ); ?></p>
	<p>
		<?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			wp_nonce_url(
				admin_url('options-general.php?page=aadsso_settings' ),
				'aadsso_reset_settings',
				'aadsso_nonce'
			),
			esc_html__('Reset Settings', AADSSO),
			esc_html__('Reset the plugin to default settings. Careful, there is no undo for this.', AADSSO)
		)
		?>
	</p>
	<?php if( defined( 'AADSSO_SETTINGS_PATH' ) && file_exists( AADSSO_SETTINGS_PATH ) ): ?>
		<h3><?php echo esc_html__( 'Migrate Plugin', AADSSO ); ?></h3>
		<p><?php printf(
			__('Old configuration data was found at %s.', AADSSO),
			sprintf( __( '<code>%s</code>' , AADSSO ), esc_html( AADSSO_SETTINGS_PATH ) )
		); ?>  
			<?php echo esc_html__('This configuration data can be migrated automatically.', AADSSO); ?></p>
		<p><?php printf(
				__('Delete the file at %s or unset the %s constant to hide this migration utility.'),
				sprintf( __( '<code>%s</code>', AADSSO ) , esc_html( AADSSO_SETTINGS_PATH ) ),
				sprintf( __( '<code>%s</code>', AADSSO ) , esc_html( 'AADSSO_SETTINGS_PATH' ) )
			); ?></p>
		<p><?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			wp_nonce_url(
				admin_url( 'options-general.php?page=aadsso_settings' ),
				'aadsso_migrate_from_json',
				'aadsso_nonce'
			),
			esc_html__( 'Migrate Settings' , AADSSO ),
			esc_html__( 'Migrate settings from old plugin versions to new configuration. This will overwrite existing settings! Careful, there is no undo for this.' , AADSSO )
		)
		?></p>
		
	<?php endif; ?>
</div>
