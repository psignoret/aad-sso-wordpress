<?php
/**
 * File class-aadsso-settings.php
 *
 * @package aad-sso-wordpress
 */

/**
 * A settings driver class that can be used to define settings with values from constants.
 * Constants are prefixed with 'AADSSO_' and are case-insensitive.
 *
 * @example
 *      define( 'AADSSO_CLIENT_ID', '12345678-1234-1234-1234-123456789012' );
 *      define( 'AADSSO_CLIENT_SECRET', '1234567890123456789012345678901234567890' );
 *      define( 'AADSSO_REDIRECT_URI', 'https://example.com/wp-admin/' );
 *      define( 'AADSSO_LOGOUT_REDIRECT_URI', 'https://example.com/' );
 * @property string $client_id The client ID obtained after registering an application in AAD.
 * @property string $client_secret The client secret key, which is generated on the app configuration page in AAD.
 * @property string $redirect_uri The URL to redirect to after signing in. Must also be configured in AAD.
 * @property string $logout_redirect_uri The URL to redirect to after signing out (of Azure AD, not WordPress).
 * @property string $org_display_name The display name of the organization, used only in the link in the login page.
 * @property string $org_domain_hint Provides a hint about the tenant or domain that the user should use to sign in.
 * @property string $field_to_match_to_upn The WordPress field which is matched to the AAD UserPrincipalName.
 * @property boolean $match_on_upn_alias Whether or not to match based UPN alias
 * @property boolean $enable_auto_provisioning Whether or not to auto-provision a new user.
 * @property boolean $enable_auto_forward_to_aad Whether or not to auto-redirect to AAD for sign-in
 * @property boolean $enable_aad_group_to_wp_role Whether or not to use AAD group memberships to set WordPress roles.
 * @property array $aad_group_to_wp_role_map The AAD group to WordPress role map. This should be stored as a JSON string.
 * @property string $default_wp_role The default WordPress role to assign a user if not in any Azure AD group.
 * @property boolean $enable_full_logout Whether or not logging out of WordPress triggers logging out of Azure AD.
 * @property string $openid_configuration_endpoint The OpenID Connect configuration discovery endpoint.
 * @property string $authorization_endpoint The OAuth 2.0 authorization endpoint.
 * @property string $token_endpoint The OAuth 2.0 token endpoint.
 * @property string $jwks_uri The OpenID Connect JSON Web Key Set endpoint.
 * @property string $end_session_endpoint The sign out endpoint.
 * @property string $graph_endpoint The URI of the Microsoft Graph API.
 * @property string $graph_version The version of the Microsoft Graph API to use.
 */
class AADSSO_Settings {

	/**
	 * The single instance of the class used for each request.
	 *
	 * @var \AADSSO_Settings
	 */
	private static $instance = null;

	/**
	 * The immutable list of settings that can be set, and the default values.
	 *
	 * @var array
	 */
	private static $defaults = null;

	/**
	 * Returns a sensible set of defaults for the plugin.
	 *
	 * If key is provided, only that default is returned.
	 *
	 * @param string $key Optional settings key to return, if only one is desired.
	 *
	 * @return mixed Sensible default settings for the plugin.
	 */
	public static function get_defaults( $key = null ) {
		if ( null === $key ) {
			return self::$defaults;
		} else {
			if ( isset( self::$defaults[ $key ] ) ) {
				return self::$defaults[ $key ];
			} else {
				return null;
			}
		}
	}

	/**
	 * Lookup a validator for a setting id.  These are only applied to values taken from constants.
	 *
	 * @var array Map of setting id => FILTER_* constant.
	 */
	private static $setting_filters = array(
		'match_on_upn_alias'            => FILTER_VALIDATE_BOOL,
		'enable_auto_provisioning'      => FILTER_VALIDATE_BOOL,
		'enable_auto_forward_to_aad'    => FILTER_VALIDATE_BOOL,
		'enable_aad_group_to_wp_role'   => FILTER_VALIDATE_BOOL,
		'enable_full_logout'            => FILTER_VALIDATE_BOOL,
		'openid_configuration_endpoint' => FILTER_VALIDATE_URL,
		'graph_endpoint'                => FILTER_VALIDATE_URL | FILTER_NULL_ON_FAILURE,
		'authorization_endpoint'        => FILTER_VALIDATE_URL | FILTER_NULL_ON_FAILURE,
		'end_session_endpoint'          => FILTER_VALIDATE_URL | FILTER_NULL_ON_FAILURE,
		'jwks_uri'                      => FILTER_VALIDATE_URL | FILTER_NULL_ON_FAILURE,
		'token_endpoint'                => FILTER_VALIDATE_URL | FILTER_NULL_ON_FAILURE,
	);

	/**
	 * Magic getter for the properties in this class. Resolves configuration values in the following order:
	 * 1. Constant prefixed with 'AADSSO_' and the property name in all caps
	 * 2. The value in the settings array
	 * 3. The default value for the property
	 * 4. null
	 *
	 * @param mixed $key The configuration property to resolve and return.
	 */
	public function __get( $key ) {
		AADSSO::debug_log( 'AADSSO_Settings::__get( ' . $key . ' )', AADSSO_LOG_SILLY );

		$defaults = self::get_defaults();

		if ( ! array_key_exists( $key, $defaults ) ) {
			return null;
		}

		$constant_name = 'AADSSO_' . strtoupper( $key );

		if ( 'aad_group_to_wp_role_map' === $key ) {
			if ( defined( $constant_name ) ) {
				return json_decode( constant( $constant_name ), true );
			} elseif ( isset( $this->runtime_settings[ $key ] ) ) {
				return $this->runtime_settings[ $key ];
			} elseif ( isset( $defaults[ $key ] ) ) {
				return $defaults[ $key ];
			}
		}

		if ( defined( $constant_name ) ) {
			return filter_var(
				constant( $constant_name ),
				array_key_exists( $key, self::$setting_filters )
					? self::$setting_filters[ $key ]
					: FILTER_UNSAFE_RAW
			);
		} elseif ( isset( $this->runtime_settings[ $key ] ) ) {
			return $this->runtime_settings[ $key ];
		} elseif ( isset( $defaults[ $key ] ) ) {
			return $defaults[ $key ];
		} else {
			return null;
		}
	}

	/**
	 * Constructor for the class.  This is private to enforce the singleton pattern.
	 */
	private function __construct() {
		self::$defaults = array(
			// Cosmetic and directory selection.
			'org_display_name'              => get_bloginfo( 'name' ),
			'org_domain_hint'               => '',

			// Client secrets and flow information.
			'client_id'                     => '',
			'client_secret'                 => '',
			'redirect_uri'                  => wp_login_url(),

			// Login/Logout Behaviors.
			'logout_redirect_uri'           => wp_login_url(),
			'enable_full_logout'            => false,
			'enable_auto_forward_to_aad'    => false,

			// User identifiers and mapping.
			'field_to_match_to_upn'         => 'email',
			'match_on_upn_alias'            => false,

			// Auto-Provisioning.
			'enable_auto_provisioning'      => false,
			'default_wp_role'               => null,

			// Automatic Role Mapping.
			'enable_aad_group_to_wp_role'   => false,
			'aad_group_to_wp_role_map'      => array(),

			// Advanced: OpenID/Graph Metadata Endpoints.
			'openid_configuration_endpoint' => 'https://login.microsoftonline.com/common/.well-known/openid-configuration',
			'graph_endpoint'                => 'https://graph.microsoft.com',
			'graph_version'                 => 'v1.0',
			'authorization_endpoint'        => null,
			'end_session_endpoint'          => null,
			'jwks_uri'                      => null,
			'token_endpoint'                => null,
		);

		// First, retrieve the settings stored in the WordPress database.
		$this->load_runtime_settings( get_option( 'aadsso_settings' ) );

		/*
		 * Then, add the settings stored in the OpenID Connect configuration endpoint.
		 * We're using transient as a cache, to prevent from making a request on every WP page load.
		 * Default transient expiration is one hour (3600 seconds), but in case a forced load is
		 * required, adding aadsso_reload_openid_config=1 in the URL will do the trick.
		 */
		$openid_configuration = get_transient( 'aadsso_openid_configuration' );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( false === $openid_configuration || isset( $_GET['aadsso_reload_openid_config'] ) ) {
			$openid_configuration = json_decode(
				self::get_remote_contents( $this->openid_configuration_endpoint ),
				true // Returns associative array.
			);
			set_transient( 'aadsso_openid_configuration', $openid_configuration, 3600 );
		}

		$this->load_runtime_settings( $openid_configuration );
	}

	/**
	 * Gets the (only) instance of the plugin.
	 *
	 * @return self The (only) instance of the class.
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Loads contents of a text file (local or remote).
	 *
	 * @param string $file_path The path to the file. May be local or remote.
	 *
	 * @return string The contents of the file.
	 */
	public static function get_remote_contents( $file_path ) {

		$response      = wp_remote_get( $file_path );
		$file_contents = wp_remote_retrieve_body( $response );

		return $file_contents;
	}

	/**
	 * Sets provided settings inside the current instance.
	 *
	 * @param array $settings An associative array of settings to be added to current configuration.
	 *
	 * @return \AADSSO_Settings The current (only) instance with new configuration.
	 */
	public function load_runtime_settings( $settings ) {

		// Expecting $settings to be an associative array. Do nothing if it isn't.
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return $this;
		}

		/*
		 * Invert the <role> => <CSV list of groups> map (which is what is stored in the database) to a flat
		 * <group> => <role> map is used during the authorization check. If a group appears twice, the first
		 * occurence (the first role) will take precedence.
		 */
		if ( ! empty( $settings['role_map'] ) ) {
			$settings['aad_group_to_wp_role_map'] = array();
			foreach ( $settings['role_map'] as $role_slug => $group_ids_list ) {
				$group_ids = explode( ',', $group_ids_list );
				if ( ! empty( $group_ids ) ) {
					foreach ( $group_ids as $group_id ) {
						if ( ! isset( $settings['aad_group_to_wp_role_map'][ $group_id ] ) ) {
							$settings['aad_group_to_wp_role_map'][ $group_id ] = $role_slug;
						}
					}
				}
			}
		}

		// Merge the new settings into $this->runtime_settings.
		$this->runtime_settings = array_merge( $this->runtime_settings, $settings );

		return $this;
	}

	/**
	 * Stores the current configuration as an associative array.
	 *
	 * @var array $settings The current configuration.
	 */
	private $runtime_settings = array();
}
