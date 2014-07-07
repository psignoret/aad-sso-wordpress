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
	public $field_to_match_to_upn = '';

	/**
	 * @var boolean Whether or not to use AAD group memberships to set WordPress roles.
	 */
	public $enable_aad_group_to_wp_role = FALSE;

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


	// These are the common endpoints that always work, but don't have tenant branding. 
	/** 
	 * @var string The OAuth 2.0 authorization endpoint.
	 */
	public $authorizationEndpoint = 'https://login.windows.net/common/oauth2/authorize?';
	
	/** 
	 * @var string The OAuth 2.0 token endpoint.
	 */
	public $tokenEndpoint =         'https://login.windows.net/common/oauth2/token?';
	
	/** 
	 * @var string The sign out endpoint.
	 */
	public $signOutEndpoint =       'https://login.windows.net/common/oauth2/logout?';

	/**
	 * @var string The URI of the Azure Active Directory Graph API.
	 */
	public $resourceURI =           'https://graph.windows.net';
	
	/**
	 * @var string The version of the AAD Graph API to use.
	 */
	public $graphVersion =          '2013-11-08';

	public function __construct () {}

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
	public static function loadSettingsFromJSON($jsonFile) {
		$settings = self::getInstance();
		
		// Load the JSON settings
		$jsonSettings = file_get_contents($jsonFile);
		$tmpSettings = json_decode($jsonSettings, TRUE);

		// Overwrite any properties defined in the JSON
		foreach ($tmpSettings as $key => $value) {
			if (property_exists($settings, $key)) {
				$settings->{$key} = $value;
			}
		}

		return $settings;
	}
}