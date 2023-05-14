<?php
/**
 * File class-graphhelper.php
 *
 * @package AADSSO
 */

/**
 * A helper class used to make calls to Microsoft Graph API.
 */
class AADSSO_Graph_Helper {

	/**
	 * Singleton instance of this class.
	 *
	 * @var \AADSSO_Settings The instance of AADSSO_Settings to use.
	 */
	public static $settings;

	/**
	 * Gets the the Microsoft Graph API base URL to use.
	 *
	 * @return string The base URL to the Microsoft Graph API.
	 */
	public static function get_base_url() {
		return self::$settings->graph_endpoint . '/' . self::$settings->graph_version;
	}

	/**
	 * Checks which of the given groups the given user is a member of.
	 *
	 * @param string $user_id The ID of the user to check.
	 * @param array  $group_ids The IDs of the groups to check.
	 * @return mixed The response to the checkMemberGroups request.
	 */
	public static function user_check_member_groups( $user_id, $group_ids ) {
		$url = self::get_base_url() . '/users/' . $user_id . '/checkMemberGroups';
		return self::post_request( $url, array(), array( 'groupIds' => $group_ids ) );
	}

	/**
	 * Gets the requested user.
	 *
	 * @param string $user_id The ID of the user to get.
	 * @return mixed The response to the user request.
	 */
	public static function get_user( $user_id ) {
		$url = self::get_base_url() . '/users/' . $user_id;
		return self::get_request( $url );
	}

	/**
	 * Issues a GET request to the Microsoft Graph API.
	 *
	 * @param string $url The URL to make the request to.
	 * @param array  $query_params The query parameters to add to the URL.
	 * @return mixed The decoded response.
	 */
	public static function get_request( $url, $query_params = array() ) {

		// Build the full query URL, adding api-version if necessary.
		$query_params = http_build_query( $query_params );
		$url          = $url . '?' . $query_params;

		$_SESSION['aadsso_last_request'] = array(
			'method' => 'GET',
			'url'    => $url,
		);

		AADSSO::debug_log( 'GET ' . $url, 50 );

		// Make the GET request.
		$response = wp_remote_get(
			$url,
			array(
				'headers' => self::get_required_headers_and_settings(),
			)
		);

		return self::parse_and_log_response( $response );
	}

	/**
	 * Issues a POST request to the Microsoft Graph API.
	 *
	 * @param string $url The URL to make the request to.
	 * @param array  $query_params The query parameters to add to the URL.
	 * @param array  $data The data to send in the request body.
	 * @return mixed The decoded response.
	 */
	public static function post_request( $url, $query_params = array(), $data = array() ) {

		// Build the full query URL and encode the payload.
		$query_params = http_build_query( $query_params );
		$url          = $url . '?' . $query_params;
		$payload      = wp_json_encode( $data );

		AADSSO::debug_log( 'POST ' . $url, 50 );
		AADSSO::debug_log( $payload, 99 );

		// Make the POST request.
		$response = wp_remote_post(
			$url,
			array(
				'body'    => $payload,
				'headers' => self::get_required_headers_and_settings(),
			)
		);

		return self::parse_and_log_response( $response );
	}

	/**
	 * Logs the HTTP response headers and body and returns the JSON-decoded body.
	 *
	 * @param mixed $response The response to parse.
	 * @return mixed The decoded response.
	 */
	private static function parse_and_log_response( $response ) {

		$response_headers = wp_remote_retrieve_headers( $response );
		$response_body    = wp_remote_retrieve_body( $response );

		AADSSO::debug_log( 'Response headers: ' . wp_json_encode( $response_headers ), 99 );
		AADSSO::debug_log( 'Response body: ' . wp_json_encode( $response_body ), 50 );

		return json_decode( $response_body );
	}

	/**
	 * Returns an array with the required headers like authorization header, service version etc.
	 *
	 * @return array An associative array with the HTTP headers for Microsoft Graph API calls.
	 */
	private static function get_required_headers_and_settings() {
		// Generate the authentication header.
		return array(
			'Authorization' => $_SESSION['aadsso_token_type'] . ' ' . $_SESSION['aadsso_access_token'],
			'Accept'        => 'application/json',
			'Content-Type'  => 'application/json',
			'Prefer'        => 'return-content',
		);
	}
}
