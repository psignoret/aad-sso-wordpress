<?php

/**
 * A helper class used to make calls to Azure Active Directory Graph API.
 */
class AADSSO_GraphHelper
{
	/**
	 * @var \AADSSO_Settings The instance of AADSSO_Settings to use.
	 */
	public static $settings;

	/**
	 * @var string The tenant ID against which Graph API calls will be made.
	 */
	public static $tenant_id;

	/**
	 * Gets the the Azure AD Graph API to use.
	 *
	 * @return string The base URL to the Azure AD Graph API.
	 */
	public static function get_resource_url() {
		if ( null == self::$tenant_id ) {
			throw new DomainException( 'Missing tenant ID for making Azure AD Graph API calls.' );
		}
		return self::$settings->graph_endpoint . '/' . self::$tenant_id;
	}

	/**
	 * Checks which of the given groups the given user is a member of.
	 *
	 * @return mixed The response to the checkMemberGroups request.
	 */
	public static function user_check_member_groups( $id, $group_ids ) {
		$url = self::get_resource_url() . '/users/' . $id . '/checkMemberGroups';
		return self::post_request( $url, array(), array( 'groupIds' => $group_ids ) );
	}

	/**
	 * Gets the requested user.
	 *
	 * @return mixed The response to the user request.
	 */
	public static function get_user( $id ) {
		$url = self::get_resource_url() . '/users/' . $id;
		return self::get_request( $url );
	}

	/**
	 * Issues a GET request to the Azure AD Graph API.
	 *
	 * @return mixed The decoded response.
	 */
	public static function get_request( $url, $query_params = array() ) {

		// Build the full query URL, adding api-version if necessary
		$query_params = http_build_query( self::maybe_add_api_version( $query_params ) );
		$url = $url . '?' . $query_params;

		$_SESSION['aadsso_last_request'] = array(
			'method' => 'GET',
			'url' => $url,
		);

		// Make the GET request
		$response = wp_remote_get( $url, array(
			'headers' => self::get_required_headers_and_settings(),
		) );

		// Parse the response
		$decoded_output = json_decode( wp_remote_retrieve_body( $response ) );

		$_SESSION['aadsso_last_request']['response'] = $decoded_output;
		return $decoded_output;
	}

	/**
	 * Issues a POST request to the Azure AD Graph API.
	 *
	 * @return mixed The decoded response.
	 */
	public static function post_request( $url, $query_params = array(), $data = array() ) {

		// Build the full query URL and encode the payload
		$query_params = http_build_query( self::maybe_add_api_version( $query_params ) );
		$url = $url . '?' . $query_params;
		$payload = json_encode( $data );

		$_SESSION['aadsso_last_request'] = array(
			'method' => 'POST',
			'url' => $url,
			'body' => $payload,
		);

		// Make the POST request
		$response = wp_remote_post( $url, array(
			'body' => $payload,
			'headers' => self::get_required_headers_and_settings(),
		) );

		// Parse the response
		$decoded_output = json_decode( wp_remote_retrieve_body( $response ) );

		$_SESSION['aadsso_last_request']['response'] = $decoded_output;
		return $decoded_output;
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
	public static function get_required_headers_and_settings()
	{
		// Generate the authentication header
		return array(
			'Authorization' => $_SESSION['aadsso_token_type'] . ' ' . $_SESSION['aadsso_access_token'],
			'Accept'        => 'application/json;odata=minimalmetadata',
			'Content-Type'  => 'application/json;odata=minimalmetadata',
			'Prefer'        => 'return-content',
		);
	}
}
