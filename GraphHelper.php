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


    public static function setup($url) {
        self::$ch = curl_init();
        self::AddRequiredHeadersAndSettings(self::$ch);
        curl_setopt(self::$ch, CURLOPT_URL, $url);
    }

    public static function execute() {
        $output = curl_exec(self::$ch);
        curl_close(self::$ch);
        $decoded_output = json_decode($output);
        $_SESSION['last_request']['response'] = $decoded_output;
        return $decoded_output;
    }

    public static function getRequest($url) {
        self::setup($url);
        $_SESSION['last_request'] = array('method' => 'GET', 'url' => $url);
        return self::execute();
    }

    public static function patchRequest($url, $data) {
        $payload = json_encode($data);
        self::setup($url);
        curl_setopt(self::$ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $payload);
        $_SESSION['last_request'] = array('method' => 'PATCH', 'url' => $url, 'payload' => $payload);
        return self::execute();
    }

    public static function postRequest($url, $data) {
        $payload = json_encode($data);
        self::setup($url);
        curl_setopt(self::$ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt(self::$ch, CURLOPT_POSTFIELDS, $payload);
        $_SESSION['last_request'] = array('method' => 'POST', 'url' => $url, 'payload' => $payload);
        return self::execute();
    }

    // Add required headers like authorization header, service version etc.
    public static function AddRequiredHeadersAndSettings($ch)
    {
        // Generate the authentication header
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Authorization: ' . $_SESSION['token_type'] . ' ' . $_SESSION['access_token'],
            'Accept: application/json;odata=minimalmetadata',
            'Content-Type: application/json;odata=minimalmetadata',
            'Prefer: return-content'));

        // Set the option to recieve the response back as string.

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        // By default https does not work for CURL.
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

}
