<?php
/**
 * Azure Active Directory DB Settings Driver: class-aadsso-settings-db.php
 *
 * @package aad-sso-wordpress
 */

require '../Utilities.php';
require 'AADSSO_Settings.php';

/**
 * Provides settings
 * @ \AADSSO_Settings
 */
class AADSSO_Settings_DB extends AADSSO_Settings {

	/**
	 * Constructs an AADSSO_Settings instance that will read/write configuration from WordPress DB.
	 *
	 * @param string $aadsso_settings_src   The database key that is used to store the settings.
	 * @return  AADSSO_Settings_DB  The properly configured settings instance.
	 */
	public function __construct( $aadsso_settings_src = '' ) {

		$setting_key = 'aadsso_settings';
		if ( ! empty( $aadsso_settings_src ) ) {
			$setting_key = $aadsso_settings_src;
		}
		// First, set the settings stored in the WordPress database.
		$this->set_settings( get_option( $setting_key ) );

		/*
		 * Then, add the settings stored in the OpenID Connect configuration endpoint.
		 * We're using transient as a cache, to prevent from making a request on every WP page load.
		 * Default transient expiration is one hour (3600 seconds), but in case a forced load is
		 * required, adding aadsso_reload_openid_config=1 in the URL will do the trick.
		 */
		$openid_configuration = get_transient( 'aadsso_openid_configuration' );
		if ( false === $openid_configuration || isset( $_GET['aadsso_reload_openid_config'] ) ) {
			$openid_configuration = json_decode(
				AADSSO_Utilities::get_remote_contents( $this->openid_configuration_endpoint ),
				true // Return associative array.
			);
			set_transient( 'aadsso_openid_configuration', $openid_configuration, 3600 );
		}
		$this->set_settings( $openid_configuration );

		return $this;
	}
}
