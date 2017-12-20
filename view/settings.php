<?php
/**
 * Settings: settings.php
 *
 * The HTML/PHP template used for rendering the admin settings page.
 *
 * @package aad-sso-wordpress
 */

?><div class="wrap">

	<h2><?php echo esc_html__( 'Single Sign-on with Azure Active Directory', 'aad-sso-wordpress' ); ?></h2>
	<p><?php echo esc_html__( 'Settings for configuring single sign-on with Azure Active Directory can be configured here.', 'aad-sso-wordpress' ); ?></p>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'aadsso_settings' );
		do_settings_sections( 'aadsso_settings_page' );
		submit_button();
		?>
	</form>

	<h3><?php echo esc_html__( 'Reset Plugin', 'aad-sso-wordpress' ); ?></h3>
	<p><?php echo esc_html__( 'Resetting the plugin will completely remove all settings.', 'aad-sso-wordpress' ); ?></p>
	<p>
		<?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			esc_attr(
				wp_nonce_url(
					admin_url( 'options-general.php?page=aadsso_settings' ),
					'aadsso_reset_settings',
					'aadsso_nonce'
				)
			),
			esc_html__( 'Reset Settings', 'aad-sso-wordpress' ),
			esc_html__( 'Reset the plugin to default settings. Careful, there is no undo for this.', 'aad-sso-wordpress' )
		)
		?>
	</p>
	<?php if ( defined( 'AADSSO_SETTINGS_PATH' ) && file_exists( AADSSO_SETTINGS_PATH ) ) : ?>
		<h3><?php echo esc_html__( 'Migrate Legacy Settings', 'aad-sso-wordpress' ); ?></h3>
		<p>
		<?php
		printf(
			// translators: This message specifies that a file containing old configuration options was found at %1$s.
			esc_html__( 'Old configuration data was found at %1%s.', 'aad-sso-wordpress' ),
			sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
		);
		?>
			<?php echo esc_html__( 'This configuration data can be migrated automatically.', 'aad-sso-wordpress' ); ?></p>
		<p>
		<?php
		printf(
			// translators: This message tells users that the migration utility can be hidden by deleting the file, %1$s.
			esc_html__( 'Delete the file at %1$s to hide this migration utility.', 'aad-sso-wordpress' ),
			sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
		);
			?>
			</p>
		
		<?php
		// The web server must have write permission on the parent directory for this to succeed.
		// TODO: Replace this with the WP_Filesystem.
		// phpcs:ignore
		if ( is_writable( AADSSO_SETTINGS_PATH ) && is_writable( dirname( AADSSO_SETTINGS_PATH ) ) ) : 
		?>
		<p>
		<?php
		printf(
			// translators: This warning specifies that the file %1$s will be deleted if they proceed with the migration process.
			esc_html__( 'If migration is successful, migration will delete this configuration file, %1$s.', 'aad-sso-wordpress' ),
			sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
		);
			?>
			</p>
		<?php else : ?>
			<p>
			<?php
			printf(
				// translators: This warning specifies that the migration process will be unable to automatically delete the configuration file, %1$s, after migration.
				esc_html__( 'If migration is successful, migration will be unable to delete the configuration file at %1$s.  It is recommended to delete the file after migration.', 'aad-sso-wordpress' ),
				sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
			);
				?>
				</p>
		<?php endif; ?>
		
		<p>
		<?php
		printf(
			'<a href="%s" class="button">%s</a> <span class="description">%s</span>',
			esc_attr(
				wp_nonce_url(
					admin_url( 'options-general.php?page=aadsso_settings' ),
					'aadsso_migrate_from_json',
					'aadsso_nonce'
				)
			),
			esc_html__( 'Migrate Settings', 'aad-sso-wordpress' ),
			esc_html__( 'Migrate settings from old plugin versions to new configuration. This will overwrite existing settings! Careful, there is no undo for this.', 'aad-sso-wordpress' )
		)
		?>
		</p>
		
	<?php endif; ?>
</div>
