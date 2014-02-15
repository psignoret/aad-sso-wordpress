<?php

class AADSSO_Settings {

	private static $instance = NULL;

	// These are the common endpoints that always work., i.e., no tenant branding
	public $authorizationEndpoint = "https://login.windows.net/common/oauth2/authorize?";
	public $tokenEndpoint =         "https://login.windows.net/common/oauth2/token?";
	public $signOutEndpoint =       "https://login.windows.net/common/oauth2/logout?";

	// The client ID, get this from AAD
	//public $clientId = 'e69981fb-edab-4385-944d-03c464ad41ec';   // philippesignoretoutlook.onmicrosoft.com Not native
	public $clientId = '7be797bb-fc35-43c9-826c-2f9e956f6ca4';   // philippesignoretoutlook.onmicrosoft.com Native client app
	//public $clientId = 'f68a1908-9fa7-4e22-9600-594c2cf625bd';   // transeconomics.com Native client app
	    
	// Don't need it! :)
	public $password = '';
	    
	// Must be configured also in AAD!
	public $redirectURI =         '';

	// Logout reply URL, doesn't need to be configured anywhere
	public $logoutRedirectURI =   'https://psignoret-oauth.azurewebsites.net/Logout.php';

	// The display name of the organization
	public $org_display_name = 'TransEconomics';

	// When the user is authenticated, their User Principal Name (UPN) is used to find a corresponding WordPress user. 'login', 'email', or 'slug'
	public $field_to_match_to_upn = 'email';

	// Not likely to change soon...
	public $resourceURI =        'https://graph.windows.net';
	public $graphVersion =       '2013-11-08';

	public function __construct () {}

	public static function getInstance() {
		if (!self::$instance) {
			self::$instance = new self;
		}
		return self::$instance;
	}
}