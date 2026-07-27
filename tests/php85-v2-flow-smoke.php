<?php

/**
 * Standalone smoke test for Microsoft identity platform v2 request construction.
 */

require_once dirname( __DIR__ ) . '/AuthorizationHelper.php';

function aadsso_v2_test_assert( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$settings = new stdClass();
$settings->authorization_endpoint =
	'https://login.microsoftonline.com/organizations/oauth2/v2.0/authorize';
$settings->graph_endpoint = 'https://graph.microsoft.com';
$settings->org_domain_hint = 'example.com';
$settings->client_id = '00000000-0000-0000-0000-000000000000';
$settings->redirect_uri = 'https://example.com/wp-login.php';
$settings->prompt = '';
$settings->enable_aad_group_to_wp_role = true;

$verifier = AADSSO_AuthorizationHelper::generate_pkce_verifier();
$challenge = AADSSO_AuthorizationHelper::get_pkce_challenge( $verifier );
$authorization_url = AADSSO_AuthorizationHelper::get_authorization_url(
	$settings,
	'test-state',
	$challenge
);
$query = array();
parse_str( parse_url( $authorization_url, PHP_URL_QUERY ), $query );

aadsso_v2_test_assert( ! isset( $query['resource'] ), 'The v1 resource parameter is still present.' );
aadsso_v2_test_assert(
	isset( $query['scope'] )
		&& false !== strpos( $query['scope'], 'openid' )
		&& false !== strpos( $query['scope'], 'https://graph.microsoft.com/User.Read' ),
	'The v2 OIDC and Graph scopes are missing.'
);
aadsso_v2_test_assert(
	isset( $query['code_challenge_method'] )
		&& 'S256' === $query['code_challenge_method']
		&& $challenge === $query['code_challenge'],
	'The S256 PKCE challenge is invalid.'
);
aadsso_v2_test_assert(
	strlen( $verifier ) >= 43 && strlen( $verifier ) <= 128,
	'The PKCE verifier length is outside RFC 7636 limits.'
);

echo "Microsoft identity platform v2 flow smoke test passed.\n";
