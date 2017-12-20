<?php
/**
 * Graph Helper: class-aadsso-graph-helper.php
 *
 * Contains AADSSO_Graph_Helper class, which can be used to interact with Azure Active Directory.
 *
 * @package aad-sso-wordpress
 */

/**
 * A helper class used to make calls to Azure Active Directory Graph API.
 */
class AADSSO_Graph_Helper {

	/**
	 * AADSSO_Settings instance
	 *
	 * @var \AADSSO_Settings The instance of AADSSO_Settings to use.
	 */
	public static $settings;

	/**
	 * Tenant ID aginst which Graph API calls will be made.
	 *
	 * @var string The tenant ID against which Graph API calls will be made.
	 */
	public static $tenant_id;

	/**
	 * Gets the the Azure AD Graph API to use.
	 *
	 * @throws DomainException Thrown if there is no $tenant_id specified before the call.
	 *
	 * @return string The base URL to the Azure AD Graph API.
	 */
	public static function get_resource_url() {
		if ( null === self::$tenant_id ) {
			throw new DomainException( 'Missing tenant ID for making Azure AD Graph API calls.' );
		}
		return self::$settings->graph_endpoint . '/' . self::$tenant_id;
	}

	/**
	 * Checks which of the given groups the given user is a member of.
	 *
	 * @param string $user_id User's GUID within Azure AD.
	 * @param array  $group_ids Array of group IDs (GUID values) to verify user $id's membership against.
	 * @return mixed The response to the checkMemberGroups request.
	 */
	public static function user_check_member_groups( $user_id, $group_ids ) {
		$url = self::get_resource_url() . '/users/' . $user_id . '/checkMemberGroups';
		return self::post_request( $url, array(), array( 'groupIds' => $group_ids ) );
	}

	/**
	 * Gets the requested user.
	 *
	 * @param string $user_id The ID of the User to retrieve.
	 * @return mixed The response to the user request.
	 */
	public static function get_user( $user_id ) {
		$url = self::get_resource_url() . '/users/' . $user_id;
		return self::get_request( $url );
	}

	/**
	 * Issues a GET request to the Azure AD Graph API.
	 *
	 * @param string $url The URL to perform a GET request against.
	 * @param mixed  $query_params An associative array of query string parameters to use for the request.
	 * @return mixed The decoded response.
	 */
	public static function get_request( $url, $query_params = array() ) {

		// Build the full query URL, adding api-version if necessary.
		$query_params = http_build_query( self::maybe_add_api_version( $query_params ) );
		$url          = $url . '?' . $query_params;

		$_SESSION['aadsso_last_request'] = array(
			'method' => 'GET',
			'url'    => $url,
		);

		// Make the GET request.
		$response = wp_remote_get(
			$url, array(
				'headers' => self::get_required_headers_and_settings(),
			)
		);

		// Parse the response.
		$decoded_output = json_decode( wp_remote_retrieve_body( $response ) );

		$_SESSION['aadsso_last_request']['response'] = $decoded_output;
		return $decoded_output;
	}

	/**
	 * Issues a POST request to the Azure AD Graph API.
	 *
	 * @param string $url The URL to perform a POST request against.
	 * @param mixed  $query_params An associative array of query string parameters to use for the request.
	 * @param mixed  $data An associative array of data to be json_encode'd and sent as the POST request body.
	 * @return mixed The decoded response.
	 */
	public static function post_request( $url, $query_params = array(), $data = array() ) {

		// Build the full query URL and encode the payload.
		$query_params = http_build_query( self::maybe_add_api_version( $query_params ) );
		$url          = $url . '?' . $query_params;
		$payload      = json_encode( $data );

		AADSSO::debug_log( 'POST ' . $url, 50 );
		AADSSO::debug_log( $payload, 99 );

		// Make the POST request.
		$response = wp_remote_post(
			$url, array(
				'body'    => $payload,
				'headers' => self::get_required_headers_and_settings(),
			)
		);

		$response_headers = wp_remote_retrieve_headers( $response );
		$response_body    = wp_remote_retrieve_body( $response );

		AADSSO::debug_log( 'Response headers: ' . json_encode( $response_headers ), 99 );
		AADSSO::debug_log( 'Response body: ' . json_encode( $response_body ), 50 );

		return json_decode( $response_body );
	}

	/**
	 * Adds the AAD Graph API version from settings, if not present already.
	 *
	 * @param array $query_params The associative array of query parameters.
	 *
	 * @return array An associative array of query parameters, including api-version.
	 */
	public static function maybe_add_api_version( $query_params ) {
		if ( ! array_key_exists( 'api-version', $query_params ) ) {
			$query_params['api-version'] = self::$settings->graph_version;
		}
		return $query_params;
	}

	/**
	 * Returns an array with the required headers like authorization header, service version etc.
	 *
	 * @return array An associative array with the HTTP headers for AAD Graph API calls.
	 */
	public static function get_required_headers_and_settings() {
		// Generate the authentication header.
		return array(
			'Authorization' => $_SESSION['aadsso_token_type'] . ' ' . $_SESSION['aadsso_access_token'],
			'Accept'        => 'application/json;odata=minimalmetadata',
			'Content-Type'  => 'application/json;odata=minimalmetadata',
			'Prefer'        => 'return-content',
		);
	}
}
