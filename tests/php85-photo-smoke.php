<?php

class WP_Error {
	private $code;
	private $message;

	public function __construct( $code, $message ) {
		$this->code = $code;
		$this->message = $message;
	}

	public function get_error_code() {
		return $this->code;
	}

	public function get_error_message() {
		return $this->message;
	}
}

function __( $message ) {
	return $message;
}

function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}

function wp_remote_retrieve_response_code( $response ) {
	return $response['response']['code'];
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function wp_remote_retrieve_header( $response, $name ) {
	return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
}

function wp_remote_get( $url, $arguments = array() ) {
	$GLOBALS['photo_request'] = array(
		'url' => $url,
		'arguments' => $arguments,
	);
	return $GLOBALS['photo_response'];
}

function get_bloginfo() {
	return 'Photo smoke test';
}

function wp_login_url() {
	return 'https://example.test/wp-login.php';
}

function assert_photo_test( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/Settings.php';
require_once dirname( __DIR__ ) . '/GraphHelper.php';

$defaults = AADSSO_Settings::get_defaults();
assert_photo_test(
	true === $defaults['enable_profile_photo_sync'],
	'Profile photo sync must be enabled by default so upgraded sites expose the site-wide checkbox.'
);

$settings = new stdClass();
$settings->graph_endpoint = 'https://graph.microsoft.com';
$settings->graph_version = 'v1.0';
AADSSO_GraphHelper::$settings = $settings;

$_SESSION['aadsso_token_type'] = 'Bearer';
$_SESSION['aadsso_access_token'] = 'delegated-token';
$GLOBALS['photo_response'] = array(
	'response' => array( 'code' => 200 ),
	'headers' => array( 'content-type' => 'image/jpeg' ),
	'body' => 'image-bytes',
);

$photo = AADSSO_GraphHelper::get_current_user_photo();
assert_photo_test( ! is_wp_error( $photo ), 'A successful Graph photo response should be returned.' );
assert_photo_test(
	'https://graph.microsoft.com/v1.0/me/photo/$value' === $GLOBALS['photo_request']['url'],
	'The signed-in user photo must use the Microsoft Graph v1.0 /me/photo/$value endpoint.'
);
assert_photo_test(
	'Bearer delegated-token' === $GLOBALS['photo_request']['arguments']['headers']['Authorization'],
	'The photo request must use the delegated sign-in access token.'
);
assert_photo_test( 'image-bytes' === $photo['bytes'], 'The image bytes must remain unchanged.' );
assert_photo_test( 'image/jpeg' === $photo['content_type'], 'The image content type must be returned.' );

$GLOBALS['photo_response'] = array(
	'response' => array( 'code' => 404 ),
	'headers' => array(),
	'body' => '',
);
$missing_photo = AADSSO_GraphHelper::get_current_user_photo();
assert_photo_test(
	is_wp_error( $missing_photo )
		&& 'graph_photo_not_found' === $missing_photo->get_error_code(),
	'A missing Microsoft photo must produce the non-fatal graph_photo_not_found result.'
);

echo "PHP 8.5 profile photo smoke test passed.\n";
