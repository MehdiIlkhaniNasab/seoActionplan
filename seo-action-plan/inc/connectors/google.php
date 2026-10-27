<?php
/**
 * Google authentication: OAuth 2.0 (web server flow) or Service Account (JWT).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

// Search Console (read) + Google Sheets (read the content calendar / clustering sheets, write back the tool's columns).
const SAP_GOOGLE_SCOPE = 'https://www.googleapis.com/auth/webmasters.readonly https://www.googleapis.com/auth/spreadsheets';

/**
 * Redirect URI that must be registered in Google Cloud Console.
 */
function sap_google_redirect_uri() {
	return rest_url( 'seoactionplan/v1/oauth/google' );
}

/**
 * Build the Google consent URL.
 */
function sap_google_auth_url() {
	$client_id = sap_setting( 'google_client_id' );
	if ( '' === $client_id ) {
		return '';
	}
	$state = wp_generate_password( 24, false );
	set_transient( 'sap_oauth_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );
	return add_query_arg( array(
		'client_id'              => rawurlencode( $client_id ),
		'redirect_uri'           => rawurlencode( sap_google_redirect_uri() ),
		'response_type'          => 'code',
		'scope'                  => rawurlencode( SAP_GOOGLE_SCOPE . ' openid email' ),
		'access_type'            => 'offline',
		'prompt'                 => 'consent',
		'include_granted_scopes' => 'true',
		'state'                  => $state,
	), 'https://accounts.google.com/o/oauth2/v2/auth' );
}

/**
 * Exchange an auth code for tokens (OAuth callback).
 */
function sap_google_handle_callback( $code, $state ) {
	$user_id = get_transient( 'sap_oauth_' . $state );
	if ( ! $user_id ) {
		return new WP_Error( 'sap_state', 'نشست اتصال منقضی شده است. دوباره تلاش کنید.' );
	}
	delete_transient( 'sap_oauth_' . $state );
	if ( ! user_can( (int) $user_id, SAP_CAP ) && ! user_can( (int) $user_id, 'manage_options' ) ) {
		return new WP_Error( 'sap_forbidden', 'دسترسی مجاز نیست.' );
	}
	$res = sap_http( 'https://oauth2.googleapis.com/token', array( 'method' => 'POST',
		'timeout' => 20,
		'body'    => array(
			'code'          => $code,
			'client_id'     => sap_setting( 'google_client_id' ),
			'client_secret' => sap_setting( 'google_client_secret' ),
			'redirect_uri'  => sap_google_redirect_uri(),
			'grant_type'    => 'authorization_code',
		),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$body = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( empty( $body['access_token'] ) ) {
		return new WP_Error( 'sap_token', 'دریافت توکن ناموفق بود: ' . ( $body['error_description'] ?? $body['error'] ?? 'unknown' ) );
	}
	if ( ! sap_google_has_scope( $body['scope'] ?? '' ) ) {
		delete_transient( 'sap_google_access_token' );
		return new WP_Error( 'sap_scope', 'دسترسی به Search Console تأیید نشد. دوباره «اتصال حساب گوگل» را بزنید و در صفحه گوگل حتماً تیک گزینه «View Search Console data for your verified sites» را بزنید (یا «Select all»).' );
	}
	$email = '';
	if ( ! empty( $body['id_token'] ) ) {
		$parts = explode( '.', $body['id_token'] );
		$claims = json_decode( base64_decode( strtr( $parts[1] ?? '', '-_', '+/' ) ), true );
		$email  = $claims['email'] ?? '';
	}
	$changes = array( 'google_auth_mode' => 'oauth', 'google_account_email' => $email, 'google_scopes' => (string) ( $body['scope'] ?? '' ) );
	if ( ! empty( $body['refresh_token'] ) ) {
		$changes['google_refresh_token'] = $body['refresh_token'];
	}
	sap_update_settings( $changes );
	set_transient( 'sap_google_access_token', $body['access_token'], max( 60, (int) ( $body['expires_in'] ?? 3600 ) - 120 ) );
	return true;
}

/**
 * Get a valid access token (cached).
 */
function sap_google_token() {
	$cached = get_transient( 'sap_google_access_token' );
	if ( $cached ) {
		return $cached;
	}
	if ( 'service' === sap_setting( 'google_auth_mode' ) ) {
		$token = sap_google_service_account_token();
	} else {
		$refresh = sap_setting( 'google_refresh_token' );
		if ( '' === $refresh ) {
			return new WP_Error( 'sap_google_not_connected', 'حساب گوگل متصل نشده است. از تنظیمات افزونه، حساب گوگل را متصل کنید.' );
		}
		$res = sap_http( 'https://oauth2.googleapis.com/token', array( 'method' => 'POST',
			'timeout' => 20,
			'body'    => array(
				'client_id'     => sap_setting( 'google_client_id' ),
				'client_secret' => sap_setting( 'google_client_secret' ),
				'refresh_token' => $refresh,
				'grant_type'    => 'refresh_token',
			),
		) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$token = json_decode( wp_remote_retrieve_body( $res ), true );
	}
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	if ( empty( $token['access_token'] ) ) {
		$err = $token['error'] ?? '';
		if ( 'invalid_grant' === $err ) {
			return new WP_Error( 'sap_google_token', 'اتصال گوگل منقضی یا لغو شده است (در حالت Testing هر ۷ روز). از تنظیمات دوباره «اتصال حساب گوگل» را بزنید.' );
		}
		return new WP_Error( 'sap_google_token', 'تمدید توکن گوگل ناموفق بود: ' . ( $token['error_description'] ?? $err ?: 'unknown' ) );
	}
	if ( isset( $token['scope'] ) && 'service' !== sap_setting( 'google_auth_mode' ) && $token['scope'] !== sap_setting( 'google_scopes' ) ) {
		sap_update_settings( array( 'google_scopes' => (string) $token['scope'] ) );
	}
	if ( isset( $token['scope'] ) && ! sap_google_has_scope( $token['scope'] ) ) {
		return new WP_Error( 'sap_scope', sap_google_scope_message() );
	}
	set_transient( 'sap_google_access_token', $token['access_token'], max( 60, (int) ( $token['expires_in'] ?? 3600 ) - 120 ) );
	return $token['access_token'];
}

/**
 * Service account → access token using a signed JWT (RS256).
 */
function sap_google_service_account_token() {
	$json = json_decode( sap_setting( 'google_service_account' ), true );
	if ( empty( $json['client_email'] ) || empty( $json['private_key'] ) ) {
		return new WP_Error( 'sap_sa', 'فایل JSON سرویس اکانت معتبر نیست.' );
	}
	$b64    = function ( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	};
	$now    = time();
	$header = $b64( wp_json_encode( array( 'alg' => 'RS256', 'typ' => 'JWT' ) ) );
	$claims = $b64( wp_json_encode( array(
		'iss'   => $json['client_email'],
		'scope' => SAP_GOOGLE_SCOPE,
		'aud'   => 'https://oauth2.googleapis.com/token',
		'iat'   => $now,
		'exp'   => $now + 3600,
	) ) );
	$sig    = '';
	if ( ! openssl_sign( $header . '.' . $claims, $sig, $json['private_key'], 'sha256WithRSAEncryption' ) ) {
		return new WP_Error( 'sap_sa_sign', 'امضای JWT ناموفق بود (کلید خصوصی نامعتبر).' );
	}
	$res = sap_http( 'https://oauth2.googleapis.com/token', array( 'method' => 'POST',
		'timeout' => 20,
		'body'    => array(
			'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
			'assertion'  => $header . '.' . $claims . '.' . $b64( $sig ),
		),
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return json_decode( wp_remote_retrieve_body( $res ), true );
}

/**
 * Is a Google connection configured?
 */
function sap_google_connected() {
	if ( 'service' === sap_setting( 'google_auth_mode' ) ) {
		return '' !== sap_setting( 'google_service_account' );
	}
	return '' !== sap_setting( 'google_refresh_token' );
}

/**
 * Authorized JSON request to a Google API.
 */
function sap_google_request( $method, $url, $body = null, $timeout = 60 ) {
	$token = sap_google_token();
	if ( is_wp_error( $token ) ) {
		return $token;
	}
	$args = array(
		'method'  => $method,
		'timeout' => $timeout,
		'headers' => array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		),
	);
	if ( null !== $body ) {
		$args['body'] = wp_json_encode( $body );
	}
	$res = sap_http( $url, $args );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_google_net', 'اتصال به گوگل ناموفق بود: ' . sap_net_error( $res, 'گوگل' ) );
	}
	$code = wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 401 === $code ) {
		delete_transient( 'sap_google_access_token' );
	}
	if ( $code >= 400 ) {
		$msg = $data['error']['message'] ?? ( 'HTTP ' . $code );
		if ( false !== stripos( $msg, 'insufficient authentication scopes' ) ) {
			delete_transient( 'sap_google_access_token' );
			return new WP_Error( 'sap_scope', sap_google_scope_message(), array( 'status' => $code ) );
		}
		return new WP_Error( 'sap_google_http', 'خطای API گوگل: ' . $msg, array( 'status' => $code ) );
	}
	return is_array( $data ) ? $data : array();
}

/**
 * Does a granted scope string include Search Console access?
 */
function sap_google_has_scope( $scope ) {
	return false !== strpos( (string) $scope, 'auth/webmasters' );
}

/**
 * Was Google Sheets access granted? (Service accounts always have it for shared sheets.)
 */
function sap_google_has_sheets() {
	if ( 'service' === sap_setting( 'google_auth_mode' ) ) {
		return true;
	}
	return false !== strpos( (string) sap_setting( 'google_scopes' ), 'auth/spreadsheets' );
}

/**
 * Service account e-mail (to share sheets / add to Search Console).
 */
function sap_google_service_email() {
	$json = json_decode( (string) sap_setting( 'google_service_account' ), true );
	return (string) ( $json['client_email'] ?? '' );
}

/**
 * Explain a missing Search Console permission.
 */
function sap_google_scope_message() {
	return 'حساب گوگل متصل است اما اجازه Search Console یا Google Sheets داده نشده. در تنظیمات دوباره «اتصال حساب گوگل» را بزنید و در صفحه گوگل تیک هر دو مورد (Search Console و Google Sheets) را بزنید.';
}
