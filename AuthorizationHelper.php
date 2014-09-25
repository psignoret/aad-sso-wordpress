<?php

// A class that provides authorization token for apps that need to access Azure Active Directory Graph Service.
class AADSSO_AuthorizationHelper
{
    // Get the authorization URL which makes the authorization request
    public static function getAuthorizationURL($settings, $antiforgery_id) {
        $authUrl = $settings->authorization_endpoint . '?' .
                   http_build_query(
                        array(
                            'response_type' => 'code',
                            'scope' => 'openid',
                            'domain_hint' => $settings->org_domain_hint,
                            'client_id' => $settings->client_id,
                            'resource' => $settings->resourceURI,
                            'redirect_uri' => $settings->redirect_uri,
                            'state' => $antiforgery_id,
                            'nonce' => $antiforgery_id,
                        )
                   );
        return $authUrl;
    }

    // Takes an authorization code and obtains an gets an access token
    public static function getAccessToken($code, $settings) {

        // Construct the body for the access token request
        $authenticationRequestBody = http_build_query(
                                        array(
                                            'grant_type' => 'authorization_code',
                                            'code' => $code,
                                            'redirect_uri' => $settings->redirect_uri,
                                            'resource' => $settings->resourceURI,
                                            'client_id' => $settings->client_id,
                                            'client_secret' => $settings->client_secret
                                        )
                                    );

        return self::getAndProcessAccessToken($authenticationRequestBody, $settings);
    }

    // Takes an authorization code and obtains an gets an access token as what AAD calls a "native app"
    public static function getAccessTokenAsNativeApp($code, $settings) {

        // Construct the body for the access token request
        $authenticationRequestBody = http_build_query(
                                        array(
                                            'grant_type' => 'authorization_code',
                                            'code' => $code,
                                            'redirect_uri' => $settings->redirect_uri,
                                            'resource' => $settings->resourceURI,
                                            'client_id' => $settings->client_id
                                        )
                                    );

        return self::getAndProcessAccessToken($authenticationRequestBody, $settings);
    }

    // Does the request for the access token and some basic processing of the access and JWT tokens
    static function getAndProcessAccessToken($authenticationRequestBody, $settings) {

		$response = wp_remote_post( $settings->token_endpoint, array(
			'body' => $authenticationRequestBody
		) );

		if( is_wp_error( $response ) ) {
			return new WP_Error( $response->get_error_code(), $response->get_error_message() );
		}

		$output = wp_remote_retrieve_body( $response );

        // Decode the JSON response from the STS. If all went well, this will contain the access token and the
        // id_token (a JWT token telling us about the current user)s
        $token = json_decode($output);

        if ( isset($token->access_token) ) {

            // Add the token information to the session so that we can use it later
            // TODO: these probably shouldn't be in SESSION...
            $_SESSION['token_type'] = $token->token_type;
            $_SESSION['access_token'] = $token->access_token;
        }

        return $token;
    }

    public static function validateIdToken($id_token, $settings, $antiforgery_id) {

        $jwt = NULL;
        $lastException = NULL;

        // TODO: cache the keys
        $discovery = json_decode(file_get_contents($settings->jwks_uri));

        if ($discovery->keys == NULL) {
            throw new DomainException('jwks_uri does not contain the keys attribute');
        }

        foreach ($discovery->keys as $key) {
            try {
                if ($key->x5c == NULL) {
                    throw new DomainException('key does not contain the x5c attribute');
                }

                $key_der = $key->x5c[0];

                // Per section 4.7 of the current JWK draft [1], the 'x5c' property will be the DER-encoded value
                // of the X.509 certificate. PHP's openssl functions all require a PEM-encoded value.
                $key_pem = chunk_split($key_der, 64, "\n");
                $key_pem = "-----BEGIN CERTIFICATE-----\n".$key_pem."-----END CERTIFICATE-----\n";

                // This throws exception if the id_token cannot be validated.
                $jwt = JWT::decode( $id_token, $key_pem);
                break;
            } catch (Exception $e) {
                $lastException = $e;
            }
        }

        if ($jwt == NULL) {
            throw $lastException;
        }

        if ($jwt->nonce != $antiforgery_id) {
            throw new DomainException(sprintf('Nonce mismatch. Expecting %s', $antiforgery_id));
        }

        return $jwt;
    }
}
