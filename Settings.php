<?php

/**
 * Class containing all settings used by the AADSSO plugin.
 *
 * Installation-specific configuration settings should be kept in a JSON file and loaded with the
 * loadSettingsFromJSON() method rather than hard-coding here.
 */
class AADSSO_Settings {

	/**
	 * @var \AADSSO_Settings $instance The settings instance.
	 */
	private static $instance = NULL;

	/**
	 * @var string The OAuth 2.0 client type. Either 'confidential' or 'public'. (Not in use yet.)
	 */
	public $clientType = 'confidential';

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
	public $redirect_uri =         '';

	/**
	 * @var string The URL to redirect to after signing out (of AAD, not WP).
	 */
	public $logout_redirect_uri =   '';

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
	public $field_to_match_to_upn = 'email';

	/**
	 * @var boolean Whether or not to use AAD group memberships to set WordPress roles.
	 */
	public $enable_aad_group_to_wp_role = true;

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
	 * This is only used if $enable_aad_group_to_wp_role is TRUE. NULL means that access will be denied
	 * to users who are not members of the groups defined in $aad_group_to_wp_role_map.
	 */
	public $default_wp_role = NULL;

	/**
	 * @var string The OpenID Connect configuration discovery endpoint.
	 */
	public $openid_configuration_endpoint = 'https://login.windows.net/common/.well-known/openid-configuration';

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
	public $resourceURI =           'https://graph.windows.net';

	/**
	 * @var string The version of the AAD Graph API to use.
	 */
	public $graphVersion =          '2013-11-08';

	public function __construct() {
		
		if( is_admin() ) {
			// Setup stuff only needed in wp-admin
			add_action( 'admin_menu', array( $this, 'add_menus' ) );
			add_action( 'admin_init', array( $this, 'register_settings' ) );
		}
	}

	/**
	 * @return self The (only) instance of the class.
	 */
	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	/**
	* This method loads the settins from a JSON file and uses the contents to overwrite
	* any properties in the settings class.
	*
	* @param string $jsonFile The path to the JSON file.
	*
	* @return self Returns the (only) instance of the class.
	*/
	public static function loadSettings() {
		$settings = self::getInstance();

		// Import from Settings.json
		$settings->importSettingsFromDB();

		// Import from openid-configuration
		$settings->importSettingsFromJSON($settings->openid_configuration_endpoint);

		return $settings;
	}

	function importSettingsFromJSON($jsonFile) {
		// Load the JSON settings
		//$jsonSettings = file_get_contents($jsonFile);
		if( file_exists( $jsonFile ) ) {
			$f = fopen( $jsonFile, "r" ) or die( "Unable to open settings file" );
			$jsonSettings = fread( $f, filesize( $jsonFile ) );
			fclose( $f );
		} else {
			$response = wp_remote_get( $jsonFile );
			$jsonSettings = wp_remote_retrieve_body( $response );
		}
		$tmpSettings = json_decode($jsonSettings, TRUE);
		
		// Overwrite any properties defined in the JSON
		foreach ($tmpSettings as $key => $value) {
			if (property_exists($this, $key)) {
				$this->{$key} = $value;
			}
		}

		return $this;
	}

	private function importSettingsFromDB() {
		$defaults = array(
			'org_display_name' 		=> $this->org_display_name,
			'org_domain_hint'		=> $this->org_domain_hint,
			'client_id' 			=> $this->client_id,
			'client_secret' 		=> $this->client_secret,
			
			'group_map'				=> array (
				'administrator'	=> '',
				'editor'		=> '',
				'author'		=> '',
				'contributor'	=> '',
				'subscriber'	=> ''
			)
		);

		$settings = get_option( 'aad-settings' );

		$settings = wp_parse_args( $settings, $defaults );

		// Store the whole chunk of settings
		$this->settings = $settings;
		// Load the individual class properties
		// Note: Legacy hack
		foreach( $settings as $k => $v ) {
			$this->$k = $v;
		}

		// Create group to role map
		// Note: Legacy hack
		foreach( $settings['group_map'] as $k => $v ) {
			$this->aad_group_to_wp_role_map[ $v ] = $k;
		}
	}

	public function add_menus() {
		add_options_page( 'AAD Settings', 'AAD Settings', 'manage_options', 'aad-settings', array( $this, 'render_admin_settings' ) );
	}

	public function render_admin_settings() {
		require_once( 'views/admin/settings.php' );
	}

	public function register_settings() {

		register_setting( 'aad-settings', 'aad-settings' );

		/*
		 * Directory Settings
		 * - Org name
		 * - Org domain
		 * - Secret keys
		 */
		add_settings_section( 
			'aad-directory-settings', 
			__( 'Directory Settings' ), 
			array( $this, 'render_directory_settings_section' ), 
			'aad-settings' 
		);

		add_settings_field( 
			'org_display_name',
			__( 'Organization Display Name' ),
			array( $this, 'render_org_display_name' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'org_domain_hint',
			__( 'Organization Domain Hint' ),
			array( $this, 'render_org_domain_hint' ),
			'aad-settings',
			'aad-directory-settings'
		);
		
		add_settings_field(
			'client_id',
			__( 'Client ID' ),
			array( $this, 'render_client_id' ),
			'aad-settings',
			'aad-directory-settings'
		);

		add_settings_field(
			'client_secret',
			__( 'Client Secret' ),
			array( $this, 'render_client_secret' ),
			'aad-settings',
			'aad-directory-settings'
		);
		/*
		 * Map of group hash from Azure to local groups
		 */
		add_settings_section( 
			'aad-group-settings', 
			__( 'Group Map' ), 
			array( $this, 'render_group_settings_section' ), 
			'aad-settings' 
		);

		add_settings_field(
			'group_map_admin',
			__( 'Administrator' ),
			array( $this, 'render_group_map_admin' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_editor',
			__( 'Editor' ),
			array( $this, 'render_group_map_editor' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_author',
			__( 'Author' ),
			array( $this, 'render_group_map_author' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field(
			'group_map_contributor',
			__( 'Contributor' ),
			array( $this, 'render_group_map_contributor' ),
			'aad-settings',
			'aad-group-settings'
		);

		add_settings_field( 
			'group_map_subscriber',
			__( 'Subscriber' ),
			array( $this, 'render_group_map_subscriber' ),
			'aad-settings',
			'aad-group-settings'
		);
	}

	public function render_directory_settings_section() {}

	public function render_org_display_name() {
		echo '<input type="text" id="org_display_name" name="aad-settings[org_display_name]" value="' . $this->org_display_name . '" />';
	}

	public function render_org_domain_hint() {
		echo '<input type="text" id="org_display_hint" hint="aad-settings[org_display_hint]" value="' . $this->org_domain_hint . '" />';
	}

	public function render_client_id() {
		echo '<input type="text" id="client_id" name="aad-settings[client_id]" value="' . $this->client_id . '" />';
	}

	public function render_client_secret() {
		echo '<input type="text" id="client_secret" name="aad-settings[client_secret]" value="' . $this->client_secret . '" />';
	}

	public function render_group_settings_section() {}

	public function render_group_map_admin() {
		echo '<input type="text" id="group_map_admin" name="aad-settings[group_map][administrator]" value="' . $this->settings['group_map']['administrator'] . '" />';
	}

	public function render_group_map_editor() {
		echo '<input type="text" id="group_map_editor" name="aad-settings[group_map][editor]" value="' . $this->settings['group_map']['editor'] . '" />';
	}

	public function render_group_map_author() {
		echo '<input type="text" id="group_map_author" name="aad-settings[group_map][author]" value="' . $this->settings['group_map']['author'] . '" />';
	}

	public function render_group_map_contributor() {
		echo '<input type="text" id="group_map_contributor" name="aad-settings[group_map][contributor]" value="' . $this->settings['group_map']['contributor'] . '" />';
	}

	public function render_group_map_subscriber() {
		echo '<input type="text" id="group_map_subscriber" name="aad-settings[group_map][subscriber]" value="' . $this->settings['group_map']['subscriber'] . '" />';
	}
}
