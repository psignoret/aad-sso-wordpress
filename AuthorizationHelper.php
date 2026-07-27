<?php

/**
 * A helper class used to request authorization and access tokens Microsoft Entra ID.
 */
class AADSSO_AuthorizationHelper
{
	/**
	 * @var string List of allowed algorithms. Currently, only RS256 is allowed and expected from AAD.
	 */
	private static $allowed_algorithms = array( 'RS256' );

	/**
	 * Gets the authorization URL used to makes the authorization request.
	 *
	 * @param \AADSSO_Settings $settings The settings to use.
	 * @param string $antiforgery_id The value to use as the nonce.
	 *
	 * @return string The authorization URL.
	 */
	public static function get_authorization_url( $settings, $antiforgery_id, $code_challenge = '' ) {
		$params = array(
			'response_type' => 'code',
			'response_mode' => 'query',
			'scope'         => self::get_requested_scopes( $settings ),
			'domain_hint'   => $settings->org_domain_hint,
			'client_id'     => $settings->client_id,
			'redirect_uri'  => $settings->redirect_uri,
			'state'         => $antiforgery_id,
			'nonce'         => $antiforgery_id,
		);

		if ( '' !== $code_challenge ) {
			$params['code_challenge'] = $code_challenge;
			$params['code_challenge_method'] = 'S256';
		}

		if ( ! empty( $settings->prompt ) ) {
			$params['prompt'] = $settings->prompt;
		}

		$auth_url = $settings->authorization_endpoint . '?' . http_build_query( $params );
		return $auth_url;
	}


	/**
	 * Exchanges an Authorization Code and obtains an Access Token and an ID Token.
	 *
	 * @param string $code The authorization code.
	 * @param \AADSSO_Settings $settings The settings to use.
	 *
	 * @return mixed The decoded authorization result.
	 */
	public static function get_access_token( $code, $settings, $code_verifier = '' ) {

		// Construct the body for the access token request.
		$request_parameters = array(
			'grant_type'   => 'authorization_code',
			'code'         => $code,
			'redirect_uri' => $settings->redirect_uri,
			'scope'        => self::get_requested_scopes( $settings ),
			'client_id'    => $settings->client_id,
		);

		if ( '' !== $code_verifier ) {
			$request_parameters['code_verifier'] = $code_verifier;
		}

		if ( 'certificate' === $settings->client_auth_method ) {
			try {
				$request_parameters['client_assertion_type'] =
					'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
				$request_parameters['client_assertion'] =
					AADSSO_CredentialHelper::create_client_assertion( $settings );
			} catch ( Exception $exception ) {
				return (object) array(
					'error' => 'certificate_authentication_failed',
					'error_description' => $exception->getMessage(),
				);
			}
		} else {
			$request_parameters['client_secret'] = $settings->client_secret;
		}

		$authentication_request_body = http_build_query( $request_parameters );

		return self::get_and_process_access_token( $authentication_request_body, $settings );
	}

	/**
	 * Makes the request for the access token and some does some basic processing of the result.
	 *
	 * @param array $authentication_request_body The body to use in the Authentication Request.
	 * @param \AADSSO_Settings $settings The settings to use.
	 *
	 * @return mixed The decoded authorization result.
	 */
	public static function get_and_process_access_token( $authentication_request_body, $settings ) {

		// Post the authorization code to the STS and get back the access token
		$response = wp_remote_post( $settings->token_endpoint, array(
			'body' => $authentication_request_body
		) );
		if( is_wp_error( $response ) ) {
			return new WP_Error( $response->get_error_code(), $response->get_error_message() );
		}
		$output = wp_remote_retrieve_body( $response );

		// Decode the JSON response from the STS. If all went well, this will contain the access
		// token and the id_token (a JWT token telling us about the current user)
		$result = json_decode( $output );

		if ( isset( $result->access_token ) ) {

			// Add the token information to the session so that we can use it later
			// TODO: these probably shouldn't be in SESSION...
			$_SESSION['aadsso_token_type'] = $result->token_type;
			$_SESSION['aadsso_access_token'] = $result->access_token;
		}

		return $result;
	}

	/**
	 * Decodes and validates an id_token value returned
	 *
	 * @param array $authentication_request_body The body to use in the Authentication Request.
	 * @param \AADSSO_Settings $settings The settings to use.
	 *
	 * @return mixed The decoded authorization result.
	 */
	public static function validate_id_token( $id_token, $settings, $antiforgery_id ) {

		$jwt = null;
		$last_exception = null;

		// TODO: cache the keys
		$jwks = wp_remote_retrieve_body( wp_remote_get( $settings->jwks_uri ) );
		$discovery = json_decode( $jwks );

		if ( null == $discovery->keys ) {
			throw new DomainException( 'jwks_uri does not contain the keys attribute' );
		}

		foreach ( $discovery->keys as $key ) {
			try {
				if ( null == $key->x5c ) {
					throw new DomainException( 'key does not contain the x5c attribute' );
				}

				$key_der = $key->x5c[0];

				/* Per section 4.7 of the current JWK draft [1], the 'x5c' property will be the
				 * DER-encoded value of the X.509 certificate. PHP's openssl functions all require
				 * a PEM-encoded value.
				 */
				$key_pem = chunk_split( $key_der, 64, "\n" );
				$key_pem = "-----BEGIN CERTIFICATE-----\n"
				            . $key_pem
				            . "-----END CERTIFICATE-----\n";

				// This throws an exception if the id_token cannot be validated.
				$jwt = \AADSSO\Firebase\JWT\JWT::decode( $id_token, $key_pem, self::$allowed_algorithms );
				break;
			} catch ( Exception $e ) {
				$last_exception = $e;
			}
		}

		if ( null == $jwt ) {
			throw $last_exception;
		}

		if ( ! isset( $jwt->nonce ) || $jwt->nonce != $antiforgery_id ) {
			throw new DomainException( sprintf( 'Nonce mismatch. Expecting %s', $antiforgery_id ) );
		}

		if ( ! isset( $jwt->aud ) ) {
			throw new DomainException( 'ID token does not contain an audience.' );
		}
		$audiences = is_array( $jwt->aud ) ? $jwt->aud : array( $jwt->aud );
		if ( ! in_array( $settings->client_id, $audiences, true ) ) {
			throw new DomainException( 'ID token audience does not match the configured client ID.' );
		}

		if ( ! empty( $settings->issuer ) ) {
			$expected_issuer = $settings->issuer;
			if ( false !== strpos( $expected_issuer, '{tenantid}' ) && ! empty( $jwt->tid ) ) {
				$expected_issuer = str_replace( '{tenantid}', $jwt->tid, $expected_issuer );
			}
			if ( ! isset( $jwt->iss ) || $jwt->iss !== $expected_issuer ) {
				throw new DomainException( 'ID token issuer does not match OpenID Connect discovery.' );
			}
		}

		return $jwt;
	}

	/**
	 * Returns the v2 scopes needed for sign-in and Microsoft Graph calls.
	 *
	 * @param \AADSSO_Settings $settings The settings to use.
	 *
	 * @return string Space-delimited OAuth 2.0 scope list.
	 */
	public static function get_requested_scopes( $settings ) {
		$graph_endpoint = rtrim( $settings->graph_endpoint, '/' );
		$scopes = array(
			'openid',
			'profile',
			'email',
			$graph_endpoint . '/User.Read',
		);

		return implode( ' ', $scopes );
	}

	/**
	 * Generates an RFC 7636 PKCE verifier.
	 *
	 * @return string PKCE verifier.
	 */
	public static function generate_pkce_verifier() {
		if ( function_exists( 'random_bytes' ) ) {
			$random = random_bytes( 64 );
		} elseif ( function_exists( 'wp_generate_password' ) ) {
			return wp_generate_password( 64, false, false );
		} else {
			$random = openssl_random_pseudo_bytes( 64, $strong );
			if ( false === $random || true !== $strong ) {
				throw new RuntimeException( 'A cryptographically secure random source is unavailable.' );
			}
		}

		return rtrim( strtr( base64_encode( $random ), '+/', '-_' ), '=' );
	}

	/**
	 * Creates the S256 challenge for a PKCE verifier.
	 *
	 * @param string $code_verifier PKCE verifier.
	 *
	 * @return string PKCE challenge.
	 */
	public static function get_pkce_challenge( $code_verifier ) {
		return rtrim(
			strtr( base64_encode( hash( 'sha256', $code_verifier, true ) ), '+/', '-_' ),
			'='
		);
	}
}
