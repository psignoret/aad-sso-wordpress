<?php

/**
 * Class containing all settings used by the AADSSO plugin.
 *
 * Installation-specific configuration settings should be kept in JSON and loaded with
 * load_settings_from_json() or load_settings_from_json_file() methods rather than hard-coding here.
 */
class AADSSO_Settings {

	/**
	 * @var \AADSSO_Settings $instance The settings instance.
	 */
	private static $instance = null;

	/**
	 * @var string The client ID obtained after registering an application in AAD.
	 */
	public $client_id = '';

	/**
	 * @var string The client secret key, which is generated on the app configuration page in AAD.
	 */
	public $client_secret = '';

	/**
	 * @var string The URL to redirect to after signing in. Must also be configured in AAD.
	 */
	public $redirect_uri = '';

	/**
	 * @var string The URL to redirect to after signing out (of Azure AD, not WordPress).
	 */
	public $logout_redirect_uri = '';

	/**
	 * @var string The display name of the organization, used only in the link in the login page.
	 */
	public $org_display_name = '';

	/**
	 * The value of the domain_hint is a registered domain for the tenant. If the tenant is federated
	 * to an on-premises directory, AAD redirects to the specified tenant federation server.
	 *
	 * @var string Provides a hint about the tenant or domain that the user should use to sign in.
	 */
	public $org_domain_hint = '';

	/**
	 * Indicates which field is matched against the authenticated user's User Principal Name (UPN)
	* to find a corresponding WordPress user. Valid options are 'login', 'email', or 'slug'.
	 *
	 * @var string The WordPress field which is matched to the AAD UserPrincipalName.
	 */
	public $field_to_match_to_upn = '';

	/**
	* Indicates whether or not a WordPress user should be auto-provisioned if a user is able to
	* authenticate with Azure AD, but was not matched to a current WordPress user.
	*
	* @var boolean Whether or not to auto-provision a new user.
	*/
	public $enable_auto_provisioning = false;


	/**
	* Indicates if unauthenticated users are automatically redirecteded to AAD for login, instead of
	* being shown the WordPress login form. Can be overridden with 'aad_auto_forward_login' filter.
	*
	* @var boolean Whether or not to auto-redirect to AAD for sign-in
	*/
	public $enable_auto_forward_to_aad = false;

	/**
	 * @var boolean Whether or not to use AAD group memberships to set WordPress roles.
	 */
	public $enable_aad_group_to_wp_role = false;

	/**
	 * An associative array used to match up AAD group object ids (key) to WordPress roles (value).
	 *
	 * Since the user will be given the first role with a matching group, the order of this array
	 * is important!
	 *
	 * @var string[] The AAD group to WordPress role map.
	 */
	public $aad_group_to_wp_role_map = array();

	/**
	 * The default WordPress role to assign to a user when not a member of defined AAD groups.
	 *
	 * This used only if $enable_aad_group_to_wp_role is true. Empty or null means that access will
	 * be denied to users who are not members of the groups defined in $aad_group_to_wp_role_map.
	 *
	 * @var string The default WordPress role to assign a user if not in any Azure AD group.
	 */
	public $default_wp_role = null;

	/**
	 * @var string The OpenID Connect configuration discovery endpoint.
	 */
	public $openid_configuration_endpoint = 'https://login.microsoftonline.com/common/.well-known/openid-configuration';

	/**
	 * @var string The OAuth 2.0 authorization endpoint.
	 */
	public $authorization_endpoint = '';

	/**
	 * @var string The OAuth 2.0 token endpoint.
	 */
	public $token_endpoint = '';

	/**
	 * @var string The OpenID Connect JSON Web Key Set endpoint.
	 */
	public $jwks_uri = '';

	/**
	 * @var string The sign out endpoint.
	 */
	public $end_session_endpoint = '';

	/**
	 * @var string The URI of the Azure Active Directory Graph API.
	 */
	public $graph_endpoint = 'https://graph.windows.net';

	/**
	 * @var string The version of the AAD Graph API to use.
	 */
	public $graph_version = '2013-11-08';

	/**
	 * Returns a sensible set of defaults for the plugin.
	 *
	 * If key is provided, only that default is returned.
	 *
	 * @param string Optional settings key to return, if only one is desired.
	 *
	 * @return mixed Sensible default settings for the plugin.
	 */
	public static function get_defaults( $key = null ) {

		$defaults = array(
			'org_display_name' => get_bloginfo( 'name' ),
			'field_to_match_to_upn' => 'email',
			'default_wp_role' => null,
			'enable_auto_provisioning' => false,
			'enable_auto_forward_to_aad' => false,
			'enable_aad_group_to_wp_role' => false,
			'redirect_uri' => wp_login_url(),
			'logout_redirect_uri' => wp_login_url(),
		);

		if ( null === $key ) {
			return $defaults;
		} else {
			if ( isset( $defaults[ $key ] ) ) {
				return $defaults[ $key ];
			} else {
				return null;
			}
		}
	}

	/**
	 * Gets the (only) instance of the plugin.
	 *
	 * @return self The (only) instance of the class.
	 */
	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	 * Initializes values for using stored settings and cached Azure AD configuration.
	 *
	 * @return \AADSSO_Settings The (only) configured instance of this class.
	 */
	public static function init() {

		$instance = self::get_instance();

		// First, set the settings stored in the WordPress database.
		$instance->set_settings( get_option( 'aadsso_settings' ) );

		/*
		 * Then, add the settings stored in the OpenID Connect configuration endpoint.
		 * We're using transient as a cache, to prevent from making a request on every WP page load.
		 * Default transient expiration is one hour (3600 seconds), but in case a forced load is
		 * required, adding aadsso_reload_openid_configuration=1 in the URL will do the trick.
		 */
		$openid_configuration = get_transient( 'aadsso_openid_configuration' );
		if( false === $openid_configuration || isset( $_GET['aadsso_reload_openid_config'] ) ) {
			$openid_configuration = json_decode(
				self::get_remote_contents( $instance->openid_configuration_endpoint ),
				true // Return associative array
			);
			set_transient( 'aadsso_openid_configuration', $openid_configuration, 3600 );
		}
		$instance->set_settings( $openid_configuration );

		return $instance;
	}

	/**
	 * Loads contents of a text file (local or remote).
	 *
	 * @param string $file_path The path to the file. May be local or remote.
	 *
	 * @return string The contents of the file.
	 */
	public static function get_remote_contents( $file_path ) {

		$response = wp_remote_get( $file_path );
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
	function set_settings( $settings ) {

		// Expecting $settings to be an associative array. Do nothing if it isn't.
		if ( ! is_array( $settings ) || empty( $settings ) ) {
			return $this;
		}

		/*
		 * This should ideally be stored as role => group object ID.
		 * Flipping this array at the last possible moment is ideal, because it keeps
		 * the UI as flexible as possible.
		 */
		if( ! empty( $settings['role_map'] ) ) {
			$settings['aad_group_to_wp_role_map'] = array_flip( $settings['role_map'] );
		}

		// Overwrite any provided setting values.
		foreach ( $settings as $key => $value ) {
			if ( property_exists( $this, $key ) ) {
				$this->{$key} = $value;
			}
		}
		return $this;
	}
}
