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
	public static function get_authorization_url( $settings, $antiforgery_id ) {
		$auth_url = $settings->authorization_endpoint . '?'
		 . http_build_query( array(
					'response_type' => 'code',
					'scope'         => 'openid',
					'domain_hint'   => $settings->org_domain_hint,
					'client_id'     => $settings->client_id,
					'resource'      => $settings->graph_endpoint,
					'redirect_uri'  => $settings->redirect_uri,
					'state'         => $antiforgery_id,
					'nonce'         => $antiforgery_id,
				) );
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
	public static function get_access_token( $code, $settings ) {

		$params = array(
			'grant_type'    => 'authorization_code',
			'code'          => $code,
			'redirect_uri'  => $settings->redirect_uri,
			'resource'      => $settings->graph_endpoint,
			'client_id'     => $settings->client_id,
		);

		// Depending on configuration, use a client secret, or an access token obtained using a
		// managed identity as a client assertion, to authenticate.
		if ( $settings->use_managed_identity_as_fic ) {
			$mi_token_response = self::get_token_with_managed_identity(
				'api://AzureADTokenExchange',
				$settings 
			);
			if( is_wp_error( $mi_token_response ) ) {
				return $mi_token_response;
			}
			$params['client_assertion'] = $mi_token_response->access_token;
			$params['client_assertion_type'] = 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer';
		} else {
			$params['client_secret'] = $settings->client_secret;
		}

		// Construct the body for the access token request
		$authentication_request_body = http_build_query( $params );

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
	 * Requests an access token using a managed identity.
	 *
	 * @param string $resource The body to use in the Authentication Request.
	 * @param \AADSSO_Settings $settings The settings to use.
	 * 
	 * @return mixed The response from the managed identity endpoint.
	 */
	public static function get_token_with_managed_identity( $resource, $settings ) {

		if ( AADSSO_Settings::is_managed_identity_available() ) {

			$params = array(
				'resource'    => $resource,
				'api-version' => '2019-08-01',
			);

			if ( ! empty( $settings->managed_identity_client_id ) ) {
				$params['client_id'] = $settings->managed_identity_client_id;
			}

			$managed_identity_request = $_SERVER['IDENTITY_ENDPOINT'] . '?' . http_build_query( $params );
			AADSSO::debug_log( 'Managed identity token request: ' . $managed_identity_request, 50 );

			// Make a request to the managed identity endpoint
			$response = wp_remote_get(
				$managed_identity_request,
				array(
					'headers' => array(
						'X-IDENTITY-HEADER' => $_SERVER['IDENTITY_HEADER'],
					),
				)
			);

			if( is_wp_error( $response ) ) {
				$error_message =  sprintf(
					__( 'ERROR: Unable to query managed identity endpoint: %s. '
					  . '(Code: %s, Message: %s)' ),
					$_SERVER['IDENTITY_ENDPOINT'],
					$response->get_error_code(),
					$response->get_error_message()
				);
				AADSSO::debug_log( $error_message, 10 );
				return new WP_Error( 'error_querying_managed_identity', $error_message );
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			$result = json_decode( $response_body );

			if ( ! ( $response_code >= 200 && $response_code < 300 ) ) {
				$error_message = sprintf(
					__( 'ERROR: The managed identity endpoint returned a %s error response.' ),
					$response_code
				);
				if ( $result !== null && isset( $result->statusCode ) ) {
					$error_message .= $response_body;
				}
				AADSSO::debug_log( $error_message, 10 );
				return new WP_Error( 'error_response_from_managed_identity', $error_message );
			}

			if ( $result == null ) {
				AADSSO::debug_log(
					'ERROR: Decoding the response from the managed identity endpoint as JSON yielded null. '
					. 'Response body: ' . $response_body, 10
				);
				return new WP_Error( 
					'unexpected_response_from_managed_identity', 
					'ERROR: The managed identity endpoint returned a response in an unexpected format.'
				);
			}

			return $result;

		} else {

			return new WP_Error(
				'managed_identity_not_available', 
				__( 'ERROR: A managed identity on Azure App Service was not available. '
				  . 'Make sure this site is running on Azure App Service, and has at least '
				  . 'one managed identity configured.' )
			);
		}
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

		if ( $jwt->nonce != $antiforgery_id ) {
			throw new DomainException( sprintf( 'Nonce mismatch. Expecting %s', $antiforgery_id ) );
		}

		return $jwt;
	}
}
