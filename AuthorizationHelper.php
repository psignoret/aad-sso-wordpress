<?php

// A class that provides authorization token for apps that need to access Azure Active Directory Graph Service.
class AADSSO_AuthorizationHelper
{
    // Get the authorization URL which makes the authorization request
    public static function getAuthorizationURL($settings, $antiforgery_id) {
        $authUrl = $settings->authorizationEndpoint .
                   http_build_query(
                        array(
                            'response_type' => 'code',
                            'client_id' => $settings->clientId,
                            'resource' => $settings->resourceURI,
                            'redirect_uri' => $settings->redirectURI,
                            'state' => $antiforgery_id
                        )
                   );
        return $authUrl;
    }

    // Takes an authorization code and obtains an gets an access token
    public function getAccessToken($code, $settings) {

        // Construct the body for the access token request
        $authenticationRequestBody = http_build_query(
                                        array(
                                            'grant_type' => 'authorization_code',
                                            'code' => $code,
                                            'redirect_uri' => $settings->redirectURI,
                                            'resource' => $settings->resourceURI,
                                            'client_id' => $settings->clientId,
                                            'client_secret' => $settings->password
                                        )
                                    );
        
        // Using curl to post the information to STS and get back the authentication response    
        $ch = curl_init();
        //curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
        curl_setopt($ch, CURLOPT_URL, $settings->tokenEndpoint); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            // Get the response back as a string 
        curl_setopt($ch, CURLOPT_POST, 1);                      // Mark as POST request
        curl_setopt($ch, CURLOPT_POSTFIELDS,  $authenticationRequestBody);  // Set the parameters for the request
        //curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);        // By default, HTTPS does not work with curl.
        $output = curl_exec($ch);                               // Read the output from the post request
        curl_close($ch);                                        // close cURL resource to free up system resources

        // Decode the json response from STS
        $tokenOutput = json_decode($output);

        return $tokenOutput;
    }

    // Takes an authorization code and obtains an gets an access token as what AAD calls a "native app"
    public function getAccessTokenAsNativeApp($code, $settings) {

        // Construct the body for the access token request
        $authenticationRequestBody = http_build_query(
                                        array(
                                            'grant_type' => 'authorization_code',
                                            'code' => $code,
                                            'redirect_uri' => $settings->redirectURI,
                                            'resource' => $settings->resourceURI,
                                            'client_id' => $settings->clientId
                                        )
                                    );
        
        // Using curl to post the information to STS and get back the authentication response    
        $ch = curl_init();
        //curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
        curl_setopt($ch, CURLOPT_URL, $settings->tokenEndpoint); 
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);            // Get the response back as a string 
        curl_setopt($ch, CURLOPT_POST, 1);                      // Mark as POST request
        curl_setopt($ch, CURLOPT_POSTFIELDS,  $authenticationRequestBody);  // Set the parameters for the request
        //curl_setopt($ch, CURLOPT_PROXY, '127.0.0.1:8888');
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, FALSE);        // By default, HTTPS does not work with curl.
        $output = curl_exec($ch);                               // Read the output from the post request
        curl_close($ch);                                        // close cURL resource to free up system resources

        // Decode the json response from STS
        $tokenOutput = json_decode($output);

        return $tokenOutput;
    }
}