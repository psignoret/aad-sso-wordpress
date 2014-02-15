<?php

require_once 'aadsso.php';
    
if (!isset($_GET['code']) && !isset($_GET['error'])) {
    // If someone tried to come here directly with no codes in the query, or if there was an error.
    header( 'Location: index.php' ) ;
} else {
    // If the expected code parameter is in the query, go try to get an access token
    AuthorizationHelper::getAccessToken($_GET['code']);        
    //print_r($_SESSION);
    
    header( 'Location: DisplayMe.php' ) ;
}
