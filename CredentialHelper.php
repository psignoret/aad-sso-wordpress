<?php

/**
 * Handles certificate credentials and encrypted private-key storage.
 */
class AADSSO_CredentialHelper {

	const ENCRYPTED_VALUE_PREFIX = 'aadsso:v1:';
	const CIPHER = 'AES-256-CBC';

	/**
	 * Encrypts a private key before it is stored in the WordPress options table.
	 *
	 * The encryption and MAC keys are derived from secrets stored in wp-config.php. Rotating
	 * those secrets intentionally makes previously stored private keys unreadable.
	 *
	 * @param string $private_key_pem Unencrypted PEM private key.
	 *
	 * @return string Encrypted, authenticated storage envelope.
	 * @throws RuntimeException If OpenSSL or secure randomness is unavailable.
	 */
	public static function encrypt_private_key( $private_key_pem ) {
		self::require_openssl();

		$iv_length = openssl_cipher_iv_length( self::CIPHER );
		$iv = self::random_bytes( $iv_length );
		$keys = self::get_encryption_keys();
		$ciphertext = openssl_encrypt(
			$private_key_pem,
			self::CIPHER,
			$keys['encryption'],
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $ciphertext ) {
			throw new RuntimeException( 'OpenSSL could not encrypt the private key.' );
		}

		$mac = hash_hmac( 'sha256', "v1\0" . $iv . $ciphertext, $keys['authentication'], true );
		$envelope = array(
			'iv' => base64_encode( $iv ),
			'ciphertext' => base64_encode( $ciphertext ),
			'mac' => base64_encode( $mac ),
		);

		return self::ENCRYPTED_VALUE_PREFIX . base64_encode( wp_json_encode( $envelope ) );
	}

	/**
	 * Decrypts and authenticates a private key from the WordPress options table.
	 *
	 * @param string $encrypted_value Encrypted storage envelope.
	 *
	 * @return string Unencrypted PEM private key.
	 * @throws UnexpectedValueException If the value is invalid or cannot be authenticated.
	 */
	public static function decrypt_private_key( $encrypted_value ) {
		self::require_openssl();

		if ( 0 !== strpos( $encrypted_value, self::ENCRYPTED_VALUE_PREFIX ) ) {
			throw new UnexpectedValueException( 'The stored private key has an unsupported format.' );
		}

		$encoded_envelope = substr( $encrypted_value, strlen( self::ENCRYPTED_VALUE_PREFIX ) );
		$decoded_envelope = base64_decode( $encoded_envelope, true );
		$envelope = json_decode( $decoded_envelope, true );

		if ( ! is_array( $envelope )
			|| empty( $envelope['iv'] )
			|| empty( $envelope['ciphertext'] )
			|| empty( $envelope['mac'] )
		) {
			throw new UnexpectedValueException( 'The stored private key is malformed.' );
		}

		$iv = base64_decode( $envelope['iv'], true );
		$ciphertext = base64_decode( $envelope['ciphertext'], true );
		$stored_mac = base64_decode( $envelope['mac'], true );

		if ( false === $iv || false === $ciphertext || false === $stored_mac
			|| openssl_cipher_iv_length( self::CIPHER ) !== strlen( $iv )
		) {
			throw new UnexpectedValueException( 'The stored private key is malformed.' );
		}

		$keys = self::get_encryption_keys();
		$calculated_mac = hash_hmac(
			'sha256',
			"v1\0" . $iv . $ciphertext,
			$keys['authentication'],
			true
		);

		if ( ! hash_equals( $calculated_mac, $stored_mac ) ) {
			throw new UnexpectedValueException(
				'The stored private key could not be authenticated. The WordPress salts may have changed.'
			);
		}

		$private_key_pem = openssl_decrypt(
			$ciphertext,
			self::CIPHER,
			$keys['encryption'],
			OPENSSL_RAW_DATA,
			$iv
		);

		if ( false === $private_key_pem ) {
			throw new UnexpectedValueException( 'OpenSSL could not decrypt the stored private key.' );
		}

		return $private_key_pem;
	}

	/**
	 * Validates a certificate/private-key pair and returns normalized PEM values.
	 *
	 * @param string $certificate_pem Public X.509 certificate in PEM format.
	 * @param string $private_key_pem Private key in PEM format.
	 * @param string $passphrase Optional private-key passphrase.
	 *
	 * @return array Normalized certificate and unencrypted private key.
	 * @throws InvalidArgumentException If either credential is invalid or they do not match.
	 */
	public static function validate_and_normalize_credentials(
		$certificate_pem,
		$private_key_pem,
		$passphrase = ''
	) {
		self::require_openssl();

		$certificate = openssl_x509_read( trim( $certificate_pem ) );
		if ( false === $certificate ) {
			throw new InvalidArgumentException( 'The certificate is not a valid PEM X.509 certificate.' );
		}

		$private_key = openssl_pkey_get_private( trim( $private_key_pem ), $passphrase );
		if ( false === $private_key ) {
			throw new InvalidArgumentException(
				'The private key is not valid, or its passphrase is incorrect.'
			);
		}

		$key_details = openssl_pkey_get_details( $private_key );
		if ( ! is_array( $key_details )
			|| ! isset( $key_details['type'] )
			|| OPENSSL_KEYTYPE_RSA !== $key_details['type']
		) {
			throw new InvalidArgumentException( 'The private key must be an RSA private key.' );
		}

		if ( ! openssl_x509_check_private_key( $certificate, $private_key ) ) {
			throw new InvalidArgumentException( 'The private key does not match the certificate.' );
		}

		$normalized_certificate = '';
		$normalized_private_key = '';
		if ( ! openssl_x509_export( $certificate, $normalized_certificate, true )
			|| ! openssl_pkey_export( $private_key, $normalized_private_key )
		) {
			throw new InvalidArgumentException( 'OpenSSL could not normalize the certificate credentials.' );
		}

		return array(
			'certificate' => $normalized_certificate,
			'private_key' => $normalized_private_key,
		);
	}

	/**
	 * Generates a self-signed RSA certificate and matching private key.
	 *
	 * @param string $common_name Certificate common name.
	 * @param int $valid_days Certificate validity period in days.
	 * @param int $key_bits RSA key size.
	 *
	 * @return array Normalized certificate and unencrypted private key.
	 * @throws RuntimeException If OpenSSL cannot generate the credentials.
	 */
	public static function generate_self_signed_credentials(
		$common_name,
		$valid_days = 730,
		$key_bits = 3072
	) {
		self::require_openssl();

		$valid_days = max( 30, min( 3650, (int) $valid_days ) );
		$key_bits = max( 2048, min( 4096, (int) $key_bits ) );
		$private_key = openssl_pkey_new(
			array(
				'private_key_bits' => $key_bits,
				'private_key_type' => OPENSSL_KEYTYPE_RSA,
				'digest_alg' => 'sha256',
			)
		);
		if ( false === $private_key ) {
			throw new RuntimeException( 'OpenSSL could not generate the RSA private key.' );
		}

		$certificate_request = openssl_csr_new(
			array( 'commonName' => $common_name ),
			$private_key,
			array( 'digest_alg' => 'sha256' )
		);
		if ( false === $certificate_request ) {
			throw new RuntimeException( 'OpenSSL could not generate the certificate request.' );
		}

		$serial_bytes = self::random_bytes( 4 );
		$serial_parts = unpack( 'Nserial', $serial_bytes );
		$serial_number = $serial_parts['serial'] & 0x7fffffff;
		$certificate = openssl_csr_sign(
			$certificate_request,
			null,
			$private_key,
			$valid_days,
			array( 'digest_alg' => 'sha256' ),
			$serial_number
		);
		if ( false === $certificate ) {
			throw new RuntimeException( 'OpenSSL could not self-sign the certificate.' );
		}

		$certificate_pem = '';
		$private_key_pem = '';
		if ( ! openssl_x509_export( $certificate, $certificate_pem, true )
			|| ! openssl_pkey_export( $private_key, $private_key_pem )
		) {
			throw new RuntimeException( 'OpenSSL could not export the generated credentials.' );
		}

		return self::validate_and_normalize_credentials( $certificate_pem, $private_key_pem );
	}

	/**
	 * Returns a base64url-encoded thumbprint of a PEM certificate.
	 *
	 * @param string $certificate_pem Public X.509 certificate in PEM format.
	 * @param string $algorithm Hash algorithm (sha256 or sha1).
	 *
	 * @return string Certificate thumbprint.
	 * @throws InvalidArgumentException If the certificate cannot be decoded.
	 */
	public static function get_certificate_thumbprint( $certificate_pem, $algorithm = 'sha256' ) {
		if ( ! in_array( $algorithm, array( 'sha256', 'sha1' ), true ) ) {
			throw new InvalidArgumentException( 'Unsupported certificate thumbprint algorithm.' );
		}

		$certificate_body = preg_replace(
			'/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/',
			'',
			$certificate_pem
		);
		$certificate_der = base64_decode( $certificate_body, true );

		if ( false === $certificate_der || '' === $certificate_der ) {
			throw new InvalidArgumentException( 'The certificate could not be decoded.' );
		}

		return self::base64url_encode( hash( $algorithm, $certificate_der, true ) );
	}

	/**
	 * Builds the signed private_key_jwt assertion used in place of a client secret.
	 *
	 * @param AADSSO_Settings $settings Current plugin settings.
	 *
	 * @return string Signed RS256 JWT assertion.
	 */
	public static function create_client_assertion( $settings ) {
		if ( empty( $settings->client_certificate )
			|| empty( $settings->client_private_key_encrypted )
		) {
			throw new UnexpectedValueException( 'Certificate credentials are not configured.' );
		}

		$private_key = self::decrypt_private_key( $settings->client_private_key_encrypted );
		$credentials = self::validate_and_normalize_credentials(
			$settings->client_certificate,
			$private_key
		);
		$now = time();
		$payload = array(
			'aud' => $settings->token_endpoint,
			'exp' => $now + 600,
			'iss' => $settings->client_id,
			'jti' => self::base64url_encode( self::random_bytes( 16 ) ),
			'nbf' => $now - 60,
			'sub' => $settings->client_id,
		);
		$header = array(
			'x5t#S256' => self::get_certificate_thumbprint( $credentials['certificate'] ),
			'x5t' => self::get_certificate_thumbprint( $credentials['certificate'], 'sha1' ),
		);

		return \AADSSO\Firebase\JWT\JWT::encode(
			$payload,
			$credentials['private_key'],
			'RS256',
			null,
			$header
		);
	}

	/**
	 * Returns parsed certificate details for display in the settings page.
	 *
	 * @param string $certificate_pem Public X.509 certificate in PEM format.
	 *
	 * @return array|false Parsed certificate information.
	 */
	public static function get_certificate_details( $certificate_pem ) {
		$certificate = openssl_x509_read( $certificate_pem );
		return false === $certificate ? false : openssl_x509_parse( $certificate );
	}

	private static function get_encryption_keys() {
		if ( defined( 'AADSSO_PRIVATE_KEY_ENCRYPTION_KEY' )
			&& strlen( AADSSO_PRIVATE_KEY_ENCRYPTION_KEY ) >= 32
		) {
			$key_material = AADSSO_PRIVATE_KEY_ENCRYPTION_KEY;
		} elseif ( defined( 'AUTH_KEY' )
			&& defined( 'SECURE_AUTH_KEY' )
			&& '' !== AUTH_KEY
			&& '' !== SECURE_AUTH_KEY
		) {
			$key_material = AUTH_KEY . "\0" . SECURE_AUTH_KEY;
		} else {
			throw new RuntimeException(
				'Define AADSSO_PRIVATE_KEY_ENCRYPTION_KEY (at least 32 random characters) '
				. 'or the standard AUTH_KEY and SECURE_AUTH_KEY constants in wp-config.php.'
			);
		}

		$site_key = hash( 'sha256', $key_material . "\0aad-sso-wordpress", true );

		return array(
			'encryption' => hash_hmac( 'sha256', 'private-key-encryption', $site_key, true ),
			'authentication' => hash_hmac( 'sha256', 'private-key-authentication', $site_key, true ),
		);
	}

	private static function random_bytes( $length ) {
		if ( function_exists( 'random_bytes' ) ) {
			return random_bytes( $length );
		}

		$bytes = openssl_random_pseudo_bytes( $length, $strong );
		if ( false === $bytes || true !== $strong ) {
			throw new RuntimeException( 'A cryptographically secure random source is unavailable.' );
		}

		return $bytes;
	}

	private static function base64url_encode( $value ) {
		return rtrim( strtr( base64_encode( $value ), '+/', '-_' ), '=' );
	}

	private static function require_openssl() {
		if ( ! extension_loaded( 'openssl' ) ) {
			throw new RuntimeException( 'The PHP OpenSSL extension is required for certificate authentication.' );
		}
	}
}
