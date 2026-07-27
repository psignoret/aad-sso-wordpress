<?php

/**
 * Standalone smoke test for the certificate credential implementation.
 *
 * Run from the plugin directory with:
 * php -d extension=openssl tests/php85-credential-smoke.php
 */

define( 'AUTH_KEY', str_repeat( 'a', 64 ) );
define( 'SECURE_AUTH_KEY', str_repeat( 'b', 64 ) );

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $value ) {
		return json_encode( $value );
	}
}

require_once dirname( __DIR__ ) . '/lib/php-jwt/src/JWT.php';
require_once dirname( __DIR__ ) . '/lib/php-jwt/src/BeforeValidException.php';
require_once dirname( __DIR__ ) . '/lib/php-jwt/src/ExpiredException.php';
require_once dirname( __DIR__ ) . '/lib/php-jwt/src/SignatureInvalidException.php';
require_once dirname( __DIR__ ) . '/CredentialHelper.php';

function aadsso_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$credentials = AADSSO_CredentialHelper::generate_self_signed_credentials(
	'aad-sso-wordpress.test',
	30,
	2048
);
$certificate_details = AADSSO_CredentialHelper::get_certificate_details(
	$credentials['certificate']
);
aadsso_test_assert(
	is_array( $certificate_details )
		&& isset( $certificate_details['subject']['CN'] )
		&& 'aad-sso-wordpress.test' === $certificate_details['subject']['CN'],
	'The generated certificate common name is invalid.'
);
$encrypted_private_key = AADSSO_CredentialHelper::encrypt_private_key( $credentials['private_key'] );
$decrypted_private_key = AADSSO_CredentialHelper::decrypt_private_key( $encrypted_private_key );
aadsso_test_assert(
	$credentials['private_key'] === $decrypted_private_key,
	'The encrypted private key did not round-trip.'
);
aadsso_test_assert(
	false === strpos( $encrypted_private_key, 'PRIVATE KEY' ),
	'The encrypted database value contains plaintext private-key material.'
);

$settings = new stdClass();
$settings->client_certificate = $credentials['certificate'];
$settings->client_private_key_encrypted = $encrypted_private_key;
$settings->client_id = '00000000-0000-0000-0000-000000000000';
$settings->token_endpoint = 'https://login.microsoftonline.com/organizations/oauth2/v2.0/token';

$assertion = AADSSO_CredentialHelper::create_client_assertion( $settings );
$decoded_assertion = \AADSSO\Firebase\JWT\JWT::decode(
	$assertion,
	$credentials['certificate'],
	array( 'RS256' )
);
aadsso_test_assert(
	$settings->token_endpoint === $decoded_assertion->aud,
	'The assertion audience does not match the v2 token endpoint.'
);
aadsso_test_assert(
	$settings->client_id === $decoded_assertion->iss
		&& $settings->client_id === $decoded_assertion->sub,
	'The assertion client identity claims are invalid.'
);

$segments = explode( '.', $assertion );
$header = json_decode( \AADSSO\Firebase\JWT\JWT::urlsafeB64Decode( $segments[0] ), true );
aadsso_test_assert(
	isset( $header['x5t#S256'] ) && isset( $header['x5t'] ),
	'The assertion does not contain both certificate thumbprint headers.'
);

$tampered_value = substr( $encrypted_private_key, 0, -1 )
	. ( 'A' === substr( $encrypted_private_key, -1 ) ? 'B' : 'A' );
$tamper_detected = false;
try {
	AADSSO_CredentialHelper::decrypt_private_key( $tampered_value );
} catch ( UnexpectedValueException $exception ) {
	$tamper_detected = true;
}
aadsso_test_assert( $tamper_detected, 'Encrypted private-key tampering was not detected.' );

echo "Certificate credential smoke test passed.\n";
