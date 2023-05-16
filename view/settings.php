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
				<?php echo __( 'In this mode, settings should be configured using constants in <code>wp-config.php</code>.', 'aad-sso-wordpress' ); ?>
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
		echo sprintf(
			'<a href="%1$s" class="button button-secondary" onclick="return confirm(\'%4$s\')">%2$s</a> <span class="description">%3$s</span>',
			esc_attr(
				$this->aadsso_action_url(
					'aadsso_reset_settings'
				)
			),
			esc_html__( 'Reset Settings', 'aad-sso-wordpress' ),
			esc_html__( 'Reset the plugin to default settings. Careful, there is no undo for this.', 'aad-sso-wordpress' ),
			esc_attr__( 'Are you sure you want to reset all settings?', 'aad-sso-wordpress' )
		)
		?>
	</p>
</div>
