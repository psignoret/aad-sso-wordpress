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
	 * Returns a sensible set of defaults for the plugin.
	 * 
	 * @return array Sensible default settings for the plugin.
	 */
	public static function get_defaults() {
		return array(
			'org_display_name' => get_bloginfo('name'),
			'field_to_match_to_upn' => 'email',
			'default_wp_role' => 'subscriber',
			'enable_auto_provisioning' => false,
			'enable_auto_forward_to_aad' => false,
			'enable_aad_group_to_wp_role' => false
		);
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
	 * Instantiates the AADSSO_Settings instance using DB and cached Azure configuration.
	 * 
	 * @return AADSSO_Settings the (only) configured instance of this AADSSSO_Settings object.
	 */
	public static function init() {
		$instance = self::get_instance();
		
		$instance->set_settings( get_option('aadsso_settings') );
			
		/*
			Storing to transient prevents this from using an HTTP request on every WP page load.
			Default transient expiration is one hour (3600 seconds).
			DO NOT REMOVE THE CAST TO ARRAY
		*/
		$azure_settings = (array) get_transient('aadsso_openid_configuration_endpoint');
		if($azure_settings === false) {
			$azure_settings = json_decode( self::get_remote_contents($instance->openid_configuration_endpoint), true );
			set_transient('aadsso_openid_configuration_endpoint', $azure_settings, 3600);
		}
		
		$instance->set_settings( $azure_settings );

		return $instance;
		
	}

	/***
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
	 * Sets settings inside the current instance.
	 * 
	 * @param array $settings Key-Value information to be used as configuration.
	 * 
	 * @return AADSSO_Settings $this The current (only) instance with new configuration.
	 * 
	 */
	function set_settings( $settings ) {
		/*
			This should ideally be stored as role => azure guid
			Flipping this array at the last possible moment is ideal, because it keeps
			the UI as flexible as possible.
		*/
		if( !empty($settings['role_map']) ) {
			$settings['aad_group_to_wp_role_map'] = array_flip($settings['role_map']);
		}
		
		foreach ( (array) $settings as 
		$key => $value) {
			
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}
		return $this;
	}
	
}
