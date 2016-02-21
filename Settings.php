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
	 * Required if $clientType is 'public'.
	 */
	public $client_secret = '';

	/**
	 * @var string The URL to redirect to after signing in. Must also be configured in AAD.
	 */
	public $redirect_uri = '';

	/**
	 * @var string The URL to redirect to after signing out (of AAD, not WP).
	 */
	public $logout_redirect_uri = '';

	/**
	 * @var string The display name of the organization, used only in the link in the login page.
	 */
	public $org_display_name = '';

	/**
	 * @var string Provides a hint about the tenant or domain that the user should use to sign in.
	 * The value of the domain_hint is a registered domain for the tenant. If the tenant is federated
	 * to an on-premises directory, AAD redirects to the specified tenant federation server.
	 */
	public $org_domain_hint = '';

	/**
	 * @var string The WordPress field which is matched to the AAD UserPrincipalName.
	 * When the user is authenticated, their User Principal Name (UPN) is used to find
	 * a corresponding WordPress user. Valid options are 'login', 'email', or 'slug'.
	 */
	public $field_to_match_to_upn = '';

	/**
	* @var boolean Whether or not to auto-provision a new user.
	* If a user is able to authenticate with AAD, but not a current WordPress user, this determines
	* wether or not a WordPress user will be provisioned on-the-fly.
	*/
	public $enable_auto_provisioning = false;


	/**
	* @var boolean Whether or not to auto-redirect to AAD for sign-in
	* If set to true, users will be automatically redirected to AAD for login, instead of being
	* shown the WordPress login form. This can be overridden by the 'aad_auto_forward_login' filter.
	*/
	public $enable_auto_forward_to_aad = false;

	/**
	 * @var boolean Whether or not to use AAD group memberships to set WordPress roles.
	 */
	public $enable_aad_group_to_wp_role = false;

	/**
	 * @var string[] The AAD group to WordPress role map.
	 * An associative array user to match up AAD group object ids (key) to WordPress roles (value).
	 * Since the user will be given the first role with a matching group, the order of this array
	 * is important!
	 */
	// TODO: A user-friendly method of specifying the groups.
	public $aad_group_to_wp_role_map = array();

	/**
	 * @var string The default WordPress role to assign a user when not a member of defined AAD groups.
	 * This is only used if $enable_aad_group_to_wp_role is TRUE. null or empty means that access will be
	 * denied to users who are not members of the groups defined in $aad_group_to_wp_role_map.
	 */
	public $default_wp_role = null;

	/**
	 * @var string The OpenID Connect configuration discovery endpoint.
	 */
	public $openid_configuration_endpoint = 'https://login.microsoftonline.com/common/.well-known/openid-configuration';

	// These are the common endpoints that always work, but don't have tenant branding.
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
	* Loads the initial settings from a JSON string and loads additional settings from OpenID Connect configuration
	* endpoint. The contents of the JSON and the OpenID Connect configuration (in that order) overwrite defaults.
	*
	* If the provided JSON is null or an empty string, the class in instantitated with the default values.
	*
	* @param string $settings_json Valid JSON with settings values.
	*
	* @return self The (only) instance of the class.
	*/
	public static function load_settings_from_json( $settings_json ) {
		$settings = self::get_instance();

		if ( '' != $settings_json ) {
			
			// Import initial settings from JSON
			$settings->set_settings_from_json( $settings_json );

			// Import additional settings from OpenID Connect configuration endpoint
			$settings->set_settings_from_json( self::load_file_contents( $settings->openid_configuration_endpoint ) );
		}

		return $settings;
	}

	/**
	* loads the initial settings from a JSON file and loads additional settings from OpenID Connect configuration
	* endpoint. The contents of the JSON file and the OpenID Connect configuration (in that order) overwrite defaults.
	*
	* @param string $json_file_path The path to the JSON file.
	*
	* @return self The (only) instance of the class.
	*/
	public static function load_settings_from_json_file( $json_file_path ) {
		return self::load_settings_from_json( self::load_file_contents( $json_file_path ) );
	}

	/***
	 * Loads contents of a text file (local or remote).
	 *
	 * @param string $file_path The path to the file. May be local or remote.
	 *
	 * @return string The contents of the file.
	 */
	static function load_file_contents( $file_path ) {
		if( file_exists( $file_path ) ) {
			$f = fopen( $file_path, 'r' ) or die( 'Unable to open settings file.' );
			$file_contents = fread( $f, filesize( $file_path ) );
			fclose( $f );
		} else {
			$response = wp_remote_get( $file_path );
			$file_contents = wp_remote_retrieve_body( $response );
		}
		return $file_contents;
	}

	/***
	 * Imports the settings from a JSON string, overwriting any setting value
	 * defined in the JSON.
	 *
	 * @param string $settings_json The valid JSON containing setting values.
	 *
	 * @return self Returns the (only) instance of the class.
	 */
	function set_settings_from_json( $settings_json ) {
		$tmpSettings = json_decode( $settings_json, true );
		foreach ($tmpSettings as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}
		return $this;
	}
}
