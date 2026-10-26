<?php
/**
 * Claude (Anthropic Messages API) for the AI analysis and task assistant.
 *
 * Raw HTTP through wp_remote_post: the plugin ships without Composer, so the
 * official PHP SDK is not bundled. Answers are requested as structured JSON
 * (output_config.format = json_schema), so they always parse.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_CLAUDE_URL     = 'https://api.anthropic.com/v1/messages';
const SAP_CLAUDE_VERSION = '2026-10-21';

/**
 * Models the user can choose from.
 */
function sap_ai_models() {
	return array(
		'claude-opus-5-5'   => 'Claude Opus 5.5 — دقیق‌ترین (پیشنهادی)',
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — سریع‌تر و ارزان‌تر',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 — ارزان‌ترین',
	);
}

/**
 * Is an API key configured?
 */
function sap_ai_connected() {
	return '' !== sap_setting( 'anthropic_key' );
}

/**
 * Send one request and return the decoded JSON answer.
 *
 * @param string $system System prompt.
 * @param string $user   User message (data + task).
 * @param array  $schema JSON schema of the answer (objects need additionalProperties:false).
 * @param array  $args   max_tokens, effort, timeout.
 * @return array|WP_Error
 */
function sap_ai_request( $system, $user, $schema, $args = array() ) {
	$key = sap_setting( 'anthropic_key' );
	if ( '' === $key ) {
		return new WP_Error( 'sap_ai_key', 'کلید API هوش مصنوعی (Anthropic) در تنظیمات وارد نشده است.' );
	}
	$args  = wp_parse_args( $args, array( 'max_tokens' => 16000, 'effort' => 'medium', 'timeout' => 280 ) );
	$model = sap_setting( 'ai_model', 'claude-opus-5-5' );
	$model = array_key_exists( $model, sap_ai_models() ) ? $model : 'claude-opus-5-5';
	$body  = array(
		'model'         => $model,
		'max_tokens'    => (int) $args['max_tokens'],
		'system'        => $system,
		'messages'      => array( array( 'role' => 'user', 'content' => $user ) ),
		'output_config' => array(
			'effort' => $args['effort'],
			'format' => array( 'type' => 'json_schema', 'schema' => $schema ),
		),
	);
	$headers = array(
		'x-api-key'         => $key,
		'anthropic-version' => SAP_CLAUDE_VERSION,
		'content-type'      => 'application/json',
	);
	// If a safety classifier declines, the API re-runs the request on its recommended model.
	if ( 'claude-haiku-5-5' !== $model ) {
		$body['fallbacks']         = 'default';
		$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( (int) $args['timeout'] + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$res = wp_remote_post( SAP_CLAUDE_URL, array(
		'timeout' => (int) $args['timeout'],
		'headers' => $headers,
		'body'    => wp_json_encode( $body ),
	) );
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_ai_http', 'اتصال به سرویس هوش مصنوعی ناموفق بود: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code ) {
		$type = (string) ( $data['error']['type'] ?? '' );
		$msgs = array(
			'authentication_error' => 'کلید API هوش مصنوعی نامعتبر است.',
			'permission_error'     => 'این کلید API به مدل انتخاب‌شده دسترسی ندارد.',
			'rate_limit_error'     => 'سقف درخواست‌های هوش مصنوعی پر شده؛ چند دقیقه بعد دوباره امتحان کنید.',
			'overloaded_error'     => 'سرویس هوش مصنوعی موقتاً شلوغ است؛ کمی بعد دوباره امتحان کنید.',
		);
		$msg = $msgs[ $type ] ?? ( 'خطای سرویس هوش مصنوعی: ' . ( $data['error']['message'] ?? 'HTTP ' . $code ) );
		if ( 'invalid_request_error' === $type && false !== stripos( (string) ( $data['error']['message'] ?? '' ), 'credit' ) ) {
			$msg = 'اعتبار حساب Anthropic تمام شده است (Plans & Billing در کنسول Anthropic).';
		}
		return new WP_Error( 'sap_ai_' . ( $type ?: 'http' ), $msg, array( 'status' => $code ) );
	}
	sap_ai_track_usage( $data['usage'] ?? array(), (string) ( $data['model'] ?? $model ) );
	$stop = (string) ( $data['stop_reason'] ?? '' );
	if ( 'refusal' === $stop ) {
		return new WP_Error( 'sap_ai_refusal', 'سرویس هوش مصنوعی به این درخواست پاسخ نداد.' );
	}
	if ( 'max_tokens' === $stop ) {
		return new WP_Error( 'sap_ai_truncated', 'پاسخ هوش مصنوعی ناقص ماند (طولانی‌تر از سقف). دوباره امتحان کنید.' );
	}
	$text = '';
	foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
		if ( 'text' === ( $block['type'] ?? '' ) ) {
			$text .= (string) $block['text'];
		}
	}
	$json = json_decode( $text, true );
	if ( ! is_array( $json ) ) {
		return new WP_Error( 'sap_ai_parse', 'پاسخ هوش مصنوعی قابل خواندن نبود.' );
	}
	return $json;
}

/**
 * Keep a running monthly token count (shown in settings).
 */
function sap_ai_track_usage( $usage, $model ) {
	$month = gmdate( 'Y-m' );
	$u     = get_option( 'sap_ai_usage', array() );
	if ( ! is_array( $u ) || ( $u['month'] ?? '' ) !== $month ) {
		$u = array( 'month' => $month, 'input' => 0, 'output' => 0, 'requests' => 0 );
	}
	$u['input']    += (int) ( $usage['input_tokens'] ?? 0 ) + (int) ( $usage['cache_read_input_tokens'] ?? 0 ) + (int) ( $usage['cache_creation_input_tokens'] ?? 0 );
	$u['output']   += (int) ( $usage['output_tokens'] ?? 0 );
	$u['requests'] += 1;
	$u['model']     = $model;
	update_option( 'sap_ai_usage', $u, false );
}

/**
 * Usage of this month.
 */
function sap_ai_usage() {
	$u = get_option( 'sap_ai_usage', array() );
	return is_array( $u ) && ( $u['month'] ?? '' ) === gmdate( 'Y-m' ) ? $u : array( 'input' => 0, 'output' => 0, 'requests' => 0 );
}
