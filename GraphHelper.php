<?php

/**
 * A class used to make calls to Azure Active Directory Graph API.
 */
class AADSSO_GraphHelper
{
	public static $ch;
	public static $settings;
	public static $token;
	public static $tenant_id;

	// TODO: validation if tenant_id is not set
	public static function getResourceUrl() {
		return self::$settings->resourceURI . '/' . self::$tenant_id;
	}

	public static function getUsers() {
		$url = self::getResourceUrl() . '/users/';
		return self::getRequest($url);
	}

	public static function getUserMemberOf($id) {
		$url = self::getResourceUrl() . '/users/' . $id . '/$links/memberOf';
		return self::getRequest($url);
	}

	public static function userCheckMemberGroups($id, $group_ids) {
		$url = self::getResourceUrl() . '/users/' . $id . '/checkMemberGroups';
		return self::postRequest($url, array(), array('groupIds' => $group_ids));
	}

	public static function getUser($id) {
		$url = self::getResourceUrl() . '/users/' . $id;
		return self::getRequest($url);
	}

	public static function updateUser($id, $data) {
		$url = self::getResourceUrl() . '/users/' . $id;
		return self::patchRequest($url, array(), $data);
	}

	public static function getMe() {
		$url = self::getResourceUrl() . '/me';
		return self::getRequest($url);
	}

	public static function updateMe($data) {
		$url = self::getResourceUrl() . '/me';
		return self::patchRequest($url, array(), $data);
	}

	public static function getRequest($url, $query_params = array()) {

		// Build the full query URL
		$query_params = http_build_query(self::maybeAddApiVersion($query_params));
		$url = $url . '?' . $query_params;

		$_SESSION['last_request'] = array('method' => 'GET', 'url' => $url);

		// Make the GET request
		$response = wp_remote_get($url, array(
			'headers' => self::GetRequiredHeadersAndSettings(),
		));

		// Parse the response
		$decoded_output = json_decode(wp_remote_retrieve_body($response));
		
		$_SESSION['last_request']['response'] = $decoded_output;
		return $decoded_output;
	}

	public static function patchRequest($url, $query_params = array(), $data = array()) {

		// Build the full query URL and encode the payload
		$query_params = http_build_query(self::maybeAddApiVersion($query_params));
		$url = $url . '?' . $query_params;
		$payload = json_encode($data);

		$_SESSION['last_request'] = array('method' => 'PATCH', 'url' => $url, 'body' => $payload);
		
		// Make the PATCH request
		$response = wp_remote_request($url, array(
			'method' => 'PATCH',
			'body' => $payload,
			'headers' => self::GetRequiredHeadersAndSettings(),
		));

		// Parse the response
		$decoded_output = json_decode(wp_remote_retrieve_body($response));

		$_SESSION['last_request']['response'] = $decoded_output;
		return $decoded_output;
	}

	public static function postRequest($url, $query_params = array(), $data = array()) {

		// Build the full query URL and encode the payload
		$query_params = http_build_query(self::maybeAddApiVersion($query_params));
		$url = $url . '?' . $query_params;
		$payload = json_encode($data);

		$_SESSION['last_request'] = array('method' => 'POST', 'url' => $url, 'body' => $payload);
		
		// Make the POST request
		$response = wp_remote_post($url, array(
			'body' => $payload,
			'headers' => self::GetRequiredHeadersAndSettings(),
		));

		// Parse the response
		$decoded_output = json_decode(wp_remote_retrieve_body($response));

		$_SESSION['last_request']['response'] = $decoded_output;
		return $decoded_output;
	}

	/**
	 * Adds the AAD Graph API version from settings, if not present already.
	 * 
	 * @param Array $query_params The associative array of query parameters. 
	 * @return Array An associative array of query parameters, including api-version.
	 */
	public static function maybeAddApiVersion($query_params) {
		if ( ! array_key_exists('api-version', $query_params) ) {
			$query_params['api-version'] = self::$settings->graphVersion;
		}
		return $query_params;
	}

	/**
	  * Returns an array with the required headers like authorization header, service version etc.
	  * 
	  * @return array An associative array with the HTTP headers for AAD Graph API calls.
	  */
	public static function GetRequiredHeadersAndSettings()
	{
		// Generate the authentication header
		return array(
			'Authorization' => $_SESSION['token_type'] . ' ' . $_SESSION['access_token'],
			'Accept' => 'application/json;odata=minimalmetadata',
			'Content-Type' => 'application/json;odata=minimalmetadata',
			'Prefer' => 'return-content',
		);
	}

}
