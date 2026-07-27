<?php

$test_options = array(
	'aadsso_settings' => array(
		'client_id' => 'preserved-client-id',
		'client_secret' => 'preserved-client-secret',
		'openid_configuration_endpoint' =>
			'https://login.microsoftonline.com/common/v2.0/.well-known/openid-configuration',
	),
);
$test_transients = array(
	'aadsso_openid_configuration' => array(),
);

function get_bloginfo() {
	return 'Settings preservation test';
}

function wp_login_url() {
	return 'https://example.test/wp-login.php';
}

function get_option( $name, $default = false ) {
	global $test_options;
	return array_key_exists( $name, $test_options ) ? $test_options[ $name ] : $default;
}

function update_option( $name, $value ) {
	global $test_options;
	$test_options[ $name ] = $value;
	return true;
}

function get_transient( $name ) {
	global $test_transients;
	return array_key_exists( $name, $test_transients ) ? $test_transients[ $name ] : false;
}

function set_transient( $name, $value ) {
	global $test_transients;
	$test_transients[ $name ] = $value;
	return true;
}

function delete_transient( $name ) {
	global $test_transients;
	unset( $test_transients[ $name ] );
	return true;
}

function wp_remote_get() {
	return array( 'body' => '{}' );
}

function wp_remote_retrieve_body( $response ) {
	return $response['body'];
}

function assert_settings_test( $condition, $message ) {
	if ( ! $condition ) {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}
}

require_once dirname( __DIR__ ) . '/Settings.php';

AADSSO_Settings::init();
assert_settings_test(
	isset( $test_options['aadsso_settings_backup']['client_id'] )
		&& 'preserved-client-id' === $test_options['aadsso_settings_backup']['client_id'],
	'Initialization must back up existing settings.'
);

unset( $test_options['aadsso_settings'] );
$instance_property = new ReflectionProperty( 'AADSSO_Settings', 'instance' );
if ( PHP_VERSION_ID < 80100 ) {
	$instance_property->setAccessible( true );
}
$instance_property->setValue( null, null );

$restored = AADSSO_Settings::init();
assert_settings_test(
	isset( $test_options['aadsso_settings']['client_secret'] )
		&& 'preserved-client-secret' === $test_options['aadsso_settings']['client_secret'],
	'Initialization must restore a missing primary option from the backup.'
);
assert_settings_test(
	'preserved-client-id' === $restored->client_id,
	'The runtime settings object must use the restored configuration.'
);

echo "PHP 8.5 settings preservation smoke test passed.\n";
