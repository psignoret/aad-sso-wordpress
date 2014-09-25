<?php

class AADSSO_GraphHelper
{
    public static $ch;
    public static $settings;
    public static $token;
    public static $tenant_id;

    // TODO: validation if tenat_id is not set
    public static function getResourceUrl() {
        return self::$settings->resourceURI . '/' . self::$tenant_id;
    }

    public static function getUsers() {
        $url = self::getResourceUrl() . '/users/' . '?api-version=' . self::$settings->graphVersion;
        return self::getRequest($url);
    }

    public static function getUserMemberOf($id) {
        $url = self::getResourceUrl() . '/users/' . $id . '/$links/memberOf?api-version=' . self::$settings->graphVersion;
        return self::getRequest($url);
    }

    public static function userCheckMemberGroups($id, $group_ids) {
    	$group_ids = array_filter( $group_ids ); //remove empty elements
        $url = self::getResourceUrl() . '/users/' . $id . '/checkMemberGroups?api-version=' . self::$settings->graphVersion;
		return self::postRequest($url, array('groupIds' => $group_ids));
    }

    public static function getUser($id) {
        $url = self::getResourceUrl() . '/users/' . $id . '?api-version=' . self::$settings->graphVersion;
        return self::getRequest($url);
    }

    public static function updateUser($id, $data){
        return self::patchRequest(
                self::getResourceUrl() . '/users/' . $id . '?api-version=' . self::$settings->graphVersion, $data);
    }

    public static function getMe(){
        return self::getRequest(self::getResourceUrl() . '/me' . '?api-version=' . self::$settings->graphVersion);
    }

    public static function updateMe($data){
        return self::patchRequest(
                self::getResourceUrl() . '/me' . '?api-version=' . self::$settings->graphVersion, $data);
    }

    public static function getRequest($url) {
        self::setup($url);
        $_SESSION['last_request'] = array('method' => 'GET', 'url' => $url);
        return self::execute();
    }

    public static function patchRequest($url, $data) {
    	$response = self::postRequest( $url, $data );
       
        // Legacy hack
        $payload = json_encode($data);
        $_SESSION['last_request'] = array('method' => 'PATCH', 'url' => $url, 'payload' => $payload);
    	
        return $response;
    }

    public static function postRequest($url, $data) {
        $payload = json_encode($data);

		$args = array(
			'body'	=> $payload,
			'headers'	=> self::AddRequiredHeadersAndSettings()
		);

		$response = wp_remote_post( $url, $args );


		if( is_wp_error( $response ) ) {
			return new WP_Error( $response->get_error_code(), $response->get_error_message() );
		}

		$output = json_decode( wp_remote_retrieve_body( $response ) );
        
        $_SESSION['last_request'] = array('method' => 'POST', 'url' => $url, 'payload' => $payload);
        $_SESSION['last_request']['response'] = $output;
        
        return $output;
    }

    // Add required headers like authorization header, service version etc.
    public static function AddRequiredHeadersAndSettings() {
		return array( 
			'Authorization'		=> $_SESSION['token_type'] . ' ' . $_SESSION['access_token'],
			'Accept'			=> 'application/json;odata=minimalmetadata',
			'Content-Type'		=> 'application/json;odata=minimalmetadata',
			'Prefer'			=> 'return-content'
		);
    }

}
