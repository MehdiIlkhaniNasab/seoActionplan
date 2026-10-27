<?php
/**
 * Network routing.
 *
 * Many sites running this plugin sit behind a local proxy (WP_PROXY_HOST, e.g.
 * v2ray on 127.0.0.1:10809) that is only needed to reach Google. Sending every
 * request through it breaks the rest: Iranian services (GapGPT, SEO Signal) and
 * client sites are slower or blocked through a foreign exit, and nothing works
 * while the proxy is off.
 *
 * «smart» mode (default): only Google and foreign AI APIs use the proxy;
 * everything else connects directly. When the proxy is down, a Google request is
 * retried once directly (useful with a system-wide VPN).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Hosts that need the proxy in smart mode.
 */
function sap_proxy_hosts() {
	return array( 'googleapis.com', 'google.com', 'googleusercontent.com', 'gstatic.com', 'api.anthropic.com', 'api.openai.com' );
}

/**
 * Is a proxy configured in wp-config.php?
 */
function sap_proxy_defined() {
	return defined( 'WP_PROXY_HOST' ) && '' !== (string) WP_PROXY_HOST;
}

/**
 * Proxy address for messages.
 */
function sap_proxy_label() {
	return sap_proxy_defined() ? WP_PROXY_HOST . ( defined( 'WP_PROXY_PORT' ) ? ':' . WP_PROXY_PORT : '' ) : '';
}

/**
 * Does a host match one of the patterns (suffix match)?
 */
function sap_host_in( $host, $patterns ) {
	$host = strtolower( (string) $host );
	foreach ( $patterns as $p ) {
		$p = strtolower( trim( (string) $p ) );
		if ( '' !== $p && ( $host === $p || substr( $host, -strlen( '.' . $p ) ) === '.' . $p ) ) {
			return true;
		}
	}
	return false;
}

add_filter( 'pre_http_send_through_proxy', function ( $override, $uri, $check ) {
	if ( ! sap_proxy_defined() ) {
		return $override;
	}
	if ( ! empty( $GLOBALS['sap_force_direct'] ) ) {
		return false;
	}
	$mode = sap_setting( 'proxy_mode', 'smart' );
	if ( 'all' === $mode ) {
		return $override;
	}
	if ( 'none' === $mode ) {
		return false;
	}
	$host = (string) ( $check['host'] ?? '' );
	if ( sap_host_in( $host, sap_list( (string) sap_setting( 'proxy_force_hosts', '' ) ) ) ) {
		return true;
	}
	return sap_host_in( $host, sap_proxy_hosts() ) ? true : false;
}, 10, 3 );

/**
 * Is an HTTP error a dead proxy?
 */
function sap_is_proxy_down( $error ) {
	$msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
	return sap_proxy_defined() && ( false !== strpos( $msg, 'cURL error 7' ) || false !== strpos( $msg, 'cURL error 97' ) ) && false !== strpos( $msg, (string) WP_PROXY_HOST );
}

/**
 * Persian explanation of a network error.
 */
function sap_net_error( $error, $service = '' ) {
	$msg = is_wp_error( $error ) ? $error->get_error_message() : (string) $error;
	if ( sap_is_proxy_down( $msg ) ) {
		return sprintf( 'پروکسی وردپرس (%s) خاموش است%s. نرم‌افزار پروکسی (مثلاً v2rayN) را روشن کنید و دوباره امتحان کنید. (اتصال مستقیم هم امتحان شد و جواب نداد.)', sap_proxy_label(), $service ? '؛ ' . $service . ' از ایران فقط از طریق پروکسی در دسترس است' : '' );
	}
	if ( false !== strpos( $msg, 'cURL error 28' ) ) {
		return 'مهلت اتصال تمام شد (شبکه کند یا مسدود). دوباره امتحان کنید.' . ( $service ? ' سرویس: ' . $service : '' );
	}
	if ( false !== strpos( $msg, 'cURL error 6' ) ) {
		return 'نام دامنه پیدا نشد؛ اینترنت سرور یا آدرس را بررسی کنید.';
	}
	return $msg;
}

/**
 * HTTP request that retries once without the proxy when the proxy is down.
 */
function sap_http( $url, $args ) {
	$res = wp_remote_request( $url, $args );
	if ( is_wp_error( $res ) && sap_is_proxy_down( $res ) ) {
		$GLOBALS['sap_force_direct'] = true;
		$res                         = wp_remote_request( $url, $args );
		unset( $GLOBALS['sap_force_direct'] );
	}
	return $res;
}

/**
 * Quick reachability test of a service (for the settings page).
 *
 * @return array{ok: bool, msg: string, via: string}
 */
function sap_net_test( $url ) {
	$host  = (string) wp_parse_url( $url, PHP_URL_HOST );
	$check = array( 'host' => $host );
	$proxy = sap_proxy_defined() && apply_filters( 'pre_http_send_through_proxy', null, $url, $check, array() );
	$t     = microtime( true );
	$res   = sap_http( $url, array( 'method' => 'GET', 'timeout' => 12, 'redirection' => 2 ) );
	$ms    = round( ( microtime( true ) - $t ) * 1000 );
	$via   = $proxy ? 'از طریق پروکسی ' . sap_proxy_label() : 'اتصال مستقیم';
	if ( is_wp_error( $res ) ) {
		return array( 'ok' => false, 'msg' => sap_net_error( $res ), 'via' => $via );
	}
	return array( 'ok' => true, 'msg' => sprintf( 'در دسترس (HTTP %s، %s میلی‌ثانیه)', wp_remote_retrieve_response_code( $res ), sap_num( $ms ) ), 'via' => $via );
}
