<?php
/**
 * Settings page for the plugin.
 *
 * @package aad-sso-wordpress
 */

?><div class="wrap">

	<?php if ( defined( 'AADSSO_IS_WPMU_PLUGIN' ) && AADSSO_IS_WPMU_PLUGIN ) : ?>
		<div class="notice notice-warning">

			<h3><?php echo esc_html__( 'Single Sign-on with Azure Active Directory is in Must-Use/Network Mode', 'aad-sso-wordpress' ); ?></h3>
			<p>
				<?php echo esc_html__( 'This plugin is installed as a must-use plugin.', 'aad-sso-wordpress' ); ?>
				<?php echo __( 'Settings should be configured using constants in <code>wp-config.php</code>.', 'aad-sso-wordpress' ); ?>
				<?php
				// translators: %s is the plugin directory path.
				echo sprintf( __( 'For more information, reference <code>%sREADME.md</code>.', 'aad-sso-wordpress' ), esc_html( AADSSO_PLUGIN_DIR ) );
				?>
			</p>

			<p>
			<?php
			echo sprintf(
				// translators: %1$s is the plugin directory name, %2$s is the WPMU_PLUGIN_DIR constant.
				__( 'To deactivate, you must move <code>%1$s</code> out of the <code>%2$s</code> directory and disable any loaders.', 'aad-sso-wordpress' ),
				esc_html( basename( dirname( dirname( __FILE__ ) ) ) ),
				esc_html( WPMU_PLUGIN_DIR )
			);
			?>
			</p>

			<h4><?php echo esc_html__( 'Current MU Plugin Loaders', 'aad-sso-wordpress' ); ?></h4>
			<p>
				<?php
				echo implode(
					', ',
					// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
					array_map(
						function( $plugin ) {
							return sprintf( '<code>%s</code>', esc_html( $plugin ) );
						},
						array_keys( get_mu_plugins() )
					)
				);
				?>
			</p>
		</div>
	<?php endif; ?>

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
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo __( 'You can also reset the plugin by defining the <code>AADSSO_RESET_SETTINGS</code> constant.', 'aad-sso-wordpress' );
	?>
	</p>
	<p>
		<?php
		printf(
			'<a href="%s" class="button button-secondary">%s</a> <span class="description">%s</span>',
			esc_attr(
				$this->aadsso_action_url(
					'aadsso_reset_settings'
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
			// translators: %s is the path to the settings file containing configuration data.
			esc_html__( 'Old configuration data was found at %s.', 'aad-sso-wordpress' ),
			sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
		);
		?>
		<?php echo esc_html__( 'This configuration data can be migrated automatically.', 'aad-sso-wordpress' ); ?></p>
		<p>
		<?php
		printf(
			// translators: %s is the path to the settings file that needs deleted.
			esc_html__( 'Delete the file at %s to hide this migration utility.', 'aad-sso-wordpress' ),
			sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
		);
		?>
			</p>

		<?php // The web server must have write permission on the parent directory for this to succeed. ?>
		<?php if ( is_writable( AADSSO_SETTINGS_PATH ) && is_writable( dirname( AADSSO_SETTINGS_PATH ) ) ) : ?>
		<p>
			<?php
			printf(
				// translators: %s is the path to the settings file.
				esc_html__( 'If migration is successful, migration will delete this configuration file, %s.', 'aad-sso-wordpress' ),
				sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
			);
			?>
			</p>
		<?php else : ?>
			<p>
			<?php
			printf(
				// translators: %s is the path to the settings file.
				esc_html__( 'If migration is successful, migration will be unable to delete the configuration file at %s.  It is recommended to delete the file after migration.', 'aad-sso-wordpress' ),
				sprintf( '<code>%s</code>', esc_html( AADSSO_SETTINGS_PATH ) )
			);
			?>
				</p>
		<?php endif; ?>

		<p>
		<?php
		printf(
			'<a href="%s" class="button button-secondary">%s</a> <span class="description">%s</span>',
			esc_attr(
				$this->aadsso_action_url( 'aadsso_migrate_legacy_settings' )
			),
			esc_html__( 'Migrate Settings', 'aad-sso-wordpress' ),
			esc_html__( 'Migrate settings from old plugin versions to new configuration. This will overwrite existing settings! Careful, there is no undo for this.', 'aad-sso-wordpress' )
		)
		?>
		</p>
	<?php endif; ?>
</div>
