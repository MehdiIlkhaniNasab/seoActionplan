<?php
/**
 * PageSpeed Insights API v5 (Core Web Vitals field data from CrUX + Lighthouse lab score).
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run PSI for a URL.
 */
function sap_psi_run( $url, $strategy = 'mobile' ) {
	$host = (string) wp_parse_url( $url, PHP_URL_HOST );
	if ( '' === $host || ! filter_var( $url, FILTER_VALIDATE_URL ) ) {
		return new WP_Error( 'sap_psi_url', 'PageSpeed: آدرس تست معتبر نیست؛ آدرس سایت پروژه را درست وارد کنید (مثال: https://example.com/).' );
	}
	if ( preg_match( '/^(localhost|127\.|10\.|192\.168\.)|\.local$|\.test$/i', $host ) ) {
		return new WP_Error( 'sap_psi_local', 'PageSpeed: گوگل فقط سایت‌های عمومی روی اینترنت را تست می‌کند؛ آدرس محلی (' . $host . ') قابل تست نیست. آدرس سایت کارفرما را وارد کنید.' );
	}
	@set_time_limit( 150 ); // phpcs:ignore
	$args = array(
		'url'      => rawurlencode( $url ),
		'strategy' => $strategy,
		'category' => 'PERFORMANCE',
		'locale'   => 'fa',
	);
	$key = sap_setting( 'psi_key' );
	if ( $key ) {
		$args['key'] = rawurlencode( $key );
	}
	$res = sap_http( add_query_arg( $args, 'https://www.googleapis.com/pagespeedonline/v5/runPagespeed' ), array( 'method' => 'GET', 'timeout' => 120 ) );
	if ( is_wp_error( $res ) ) {
		$msg = $res->get_error_message();
		if ( false !== stripos( $msg, 'timed out' ) || false !== stripos( $msg, 'timeout' ) ) {
			return new WP_Error( 'sap_psi_timeout', 'PageSpeed: پاسخ گوگل بیش از ۲ دقیقه طول کشید. چند دقیقه بعد دوباره تست کنید.' );
		}
		return new WP_Error( 'sap_psi_http', 'PageSpeed: اتصال به سرور گوگل برقرار نشد (' . $msg . ').' );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = wp_remote_retrieve_body( $res );
	$data = json_decode( $body, true );
	if ( $code >= 400 ) {
		return new WP_Error( 'sap_psi', sap_psi_error_message( $code, $data, $body ) );
	}
	$field  = sap_psi_field( $data['loadingExperience'] ?? array() );
	$origin = sap_psi_field( $data['originLoadingExperience'] ?? array() );
	$audits = $data['lighthouseResult']['audits'] ?? array();
	return array(
		'url'      => $url,
		'strategy' => $strategy,
		'score'    => isset( $data['lighthouseResult']['categories']['performance']['score'] ) ? (int) round( $data['lighthouseResult']['categories']['performance']['score'] * 100 ) : null,
		'field'    => $field,
		'origin'   => $origin,
		'lab'      => array(
			'lcp' => isset( $audits['largest-contentful-paint']['numericValue'] ) ? (int) $audits['largest-contentful-paint']['numericValue'] : null,
			'cls' => isset( $audits['cumulative-layout-shift']['numericValue'] ) ? round( $audits['cumulative-layout-shift']['numericValue'], 3 ) : null,
			'tbt' => isset( $audits['total-blocking-time']['numericValue'] ) ? (int) $audits['total-blocking-time']['numericValue'] : null,
			'fcp' => isset( $audits['first-contentful-paint']['numericValue'] ) ? (int) $audits['first-contentful-paint']['numericValue'] : null,
		),
	);
}

/**
 * Extract CrUX metrics (p75) from a loadingExperience block.
 */
function sap_psi_field( $exp ) {
	if ( empty( $exp['metrics'] ) ) {
		return null;
	}
	$m   = $exp['metrics'];
	$get = function ( $k ) use ( $m ) {
		return isset( $m[ $k ] ) ? array( 'p75' => $m[ $k ]['percentile'], 'cat' => $m[ $k ]['category'] ?? '' ) : null;
	};
	$cls = $get( 'CUMULATIVE_LAYOUT_SHIFT_SCORE' );
	if ( $cls ) {
		$cls['p75'] = $cls['p75'] / 100;
	}
	return array(
		'overall' => $exp['overall_category'] ?? '',
		'lcp'     => $get( 'LARGEST_CONTENTFUL_PAINT_MS' ),
		'inp'     => $get( 'INTERACTION_TO_NEXT_PAINT' ),
		'cls'     => $cls,
		'fcp'     => $get( 'FIRST_CONTENTFUL_PAINT_MS' ),
		'ttfb'    => $get( 'EXPERIMENTAL_TIME_TO_FIRST_BYTE' ),
	);
}

/**
 * Explain a PageSpeed API error in plain Persian (with the raw reason for support).
 */
function sap_psi_error_message( $code, $data, $body ) {
	$raw = (string) ( $data['error']['message'] ?? '' );
	$why = $raw;
	if ( '' === $raw ) {
		$why = 403 === $code
			? 'گوگل درخواست را بدون توضیح رد کرد. معمولاً یعنی IP این سرور/کامپیوتر برای سرویس‌های گوگل مسدود است (مثلاً IP ایران). ابزار را روی هاست خارج از ایران اجرا کنید یا اینترنت سیستم را از مسیر دیگری عبور دهید.'
			: 'پاسخ نامعتبر از گوگل: ' . mb_substr( wp_strip_all_tags( (string) $body ), 0, 160 );
	} elseif ( false !== stripos( $raw, 'API key not valid' ) ) {
		$why = 'کلید API نامعتبر است. کلید را دوباره از Google Cloud › Credentials کپی کنید یا فیلد کلید را خالی بگذارید.';
	} elseif ( false !== stripos( $raw, 'has not been used' ) || false !== stripos( $raw, 'is disabled' ) ) {
		$why = 'PageSpeed Insights API در پروژه Google Cloud شما فعال نیست. از APIs & Services › Library آن را Enable کنید و چند دقیقه صبر کنید.';
	} elseif ( false !== stripos( $raw, 'referer' ) || false !== stripos( $raw, 'blocked' ) ) {
		$why = 'کلید API محدودیت دارد (Application restrictions). در Google Cloud › Credentials روی کلید، محدودیت را روی None یا IP سرور بگذارید؛ محدودیت HTTP referrer برای درخواست سمت سرور کار نمی‌کند.';
	} elseif ( 429 === $code || false !== stripos( $raw, 'quota' ) ) {
		$why = 'سهمیه روزانه/دقیقه‌ای تمام شده است. کمی بعد تست کنید یا کلید API وارد کنید.';
	} elseif ( false !== stripos( $raw, 'FAILED_DOCUMENT_REQUEST' ) || false !== stripos( $raw, 'ERRORED_DOCUMENT_REQUEST' ) || false !== stripos( $raw, 'NO_FCP' ) ) {
		$why = 'گوگل نتوانست صفحه را باز کند (سایت در دسترس نیست، فایروال/سرویس محافظ ربات گوگل را مسدود کرده یا صفحه خطا می‌دهد). آدرس را در pagespeed.web.dev هم تست کنید.';
	}
	return sprintf( 'PageSpeed (HTTP %d): %s', $code, $why );
}
