<?php
/**
 * AI model access for the analysis and the task assistant.
 *
 * Two providers:
 *   gateway   — any OpenAI-compatible Chat Completions API (default: GapGPT,
 *               https://api.gapgpt.app/v1), which serves Claude, GPT and Gemini
 *               models under their own names.
 *   anthropic — the Anthropic Messages API directly.
 *
 * Raw HTTP through wp_remote_post (the plugin ships without Composer). Answers are
 * requested as JSON matching a schema; the gateway path also validates the JSON
 * itself, because not every gateway/model honours response_format.
 *
 * Cost guard: every call is priced from its token usage and added to a monthly
 * total; when the monthly budget is reached, AI calls stop until next month.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

const SAP_CLAUDE_URL     = 'https://api.anthropic.com/v1/messages';
const SAP_CLAUDE_VERSION = '2026-10-21';

/**
 * Suggested models with USD price per 1M tokens [input, output].
 * Any other model name can be typed in settings (priced at the fallback rate).
 */
function sap_ai_model_prices() {
	return array(
		'claude-haiku-5-5'  => array( 0.10, 0.50 ),
		'claude-sonnet-5-5' => array( 2.00, 10.00 ),
		'claude-opus-5-5'   => array( 4.00, 20.00 ),
		'gpt-5-mini'        => array( 0.25, 2.00 ),
		'gpt-5.4-mini'      => array( 0.75, 4.50 ),
		'gpt-5'             => array( 1.25, 10.00 ),
		'gemini-2.5-flash'  => array( 0.30, 2.50 ),
		'gemini-2.5-pro'    => array( 2.50, 20.00 ),
	);
}

/**
 * Labels for the model pickers.
 */
function sap_ai_models() {
	return array(
		'claude-sonnet-5-5' => 'Claude Sonnet 5.5 — تحلیل دقیق (پیشنهادی برای تحلیل)',
		'claude-haiku-5-5'  => 'Claude Haiku 5.5 — خیلی ارزان (پیشنهادی برای «بنویس برام»)',
		'claude-opus-5-5'   => 'Claude Opus 5.5 — دقیق‌ترین و گران‌ترین',
		'gpt-5-mini'        => 'GPT-5 mini',
		'gpt-5.4-mini'      => 'GPT-5.4 mini',
		'gemini-2.5-flash'  => 'Gemini 2.5 Flash',
	);
}

/**
 * Model for a purpose: "analysis" or "assist".
 */
function sap_ai_model_for( $purpose ) {
	$model = 'assist' === $purpose ? sap_setting( 'ai_model_assist', 'claude-haiku-5-5' ) : sap_setting( 'ai_model', 'claude-sonnet-5-5' );
	return preg_replace( '/[^a-zA-Z0-9._:\/-]/', '', (string) $model );
}

/**
 * Is AI configured for the selected provider?
 */
function sap_ai_connected() {
	return 'anthropic' === sap_setting( 'ai_provider', 'gateway' ) ? '' !== sap_setting( 'anthropic_key' ) : '' !== sap_setting( 'ai_gateway_key' );
}

/**
 * Price of a call in USD.
 */
function sap_ai_cost( $model, $input_tokens, $output_tokens ) {
	$prices = sap_ai_model_prices();
	$p      = $prices[ $model ] ?? array( 2.50, 15.00 ); // Unknown model: priced high, so the budget errs on the safe side.
	return ( $input_tokens * $p[0] + $output_tokens * $p[1] ) / 1000000;
}

/**
 * This month's usage: requests, tokens, cost (USD).
 */
function sap_ai_usage() {
	$u = get_option( 'sap_ai_usage', array() );
	if ( ! is_array( $u ) || ( $u['month'] ?? '' ) !== gmdate( 'Y-m' ) ) {
		$u = array( 'month' => gmdate( 'Y-m' ), 'input' => 0, 'output' => 0, 'requests' => 0, 'cost' => 0.0, 'last_cost' => 0.0 );
	}
	$u['cost']   = (float) ( $u['cost'] ?? 0 );
	$u['budget'] = max( 0, (float) sap_setting( 'ai_budget', 10 ) );
	return $u;
}

/**
 * Add a call to this month's usage.
 */
function sap_ai_track_usage( $model, $input, $output ) {
	$u = sap_ai_usage();
	unset( $u['budget'] );
	$cost           = sap_ai_cost( $model, $input, $output );
	$u['input']    += $input;
	$u['output']   += $output;
	$u['requests'] += 1;
	$u['cost']      = round( $u['cost'] + $cost, 5 );
	$u['last_cost'] = round( $cost, 5 );
	$u['model']     = $model;
	update_option( 'sap_ai_usage', $u, false );
	return $cost;
}

/**
 * Send one request and return the decoded JSON answer.
 *
 * @param string $system System prompt.
 * @param string $user   User message (data + task).
 * @param array  $schema JSON schema of the answer.
 * @param array  $args   purpose (analysis|assist), max_tokens, effort, timeout.
 * @return array|WP_Error
 */
function sap_ai_request( $system, $user, $schema, $args = array() ) {
	$args = wp_parse_args( $args, array( 'purpose' => 'analysis', 'max_tokens' => 12000, 'effort' => 'medium', 'timeout' => 240 ) );
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید API هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$usage = sap_ai_usage();
	if ( $usage['budget'] > 0 && $usage['cost'] >= $usage['budget'] ) {
		return new WP_Error( 'sap_ai_budget', sprintf( 'سقف بودجه ماهانه هوش مصنوعی (%s دلار) پر شده است. در تنظیمات می‌توانید سقف را بیشتر کنید؛ بقیه ابزار بدون هوش مصنوعی کار می‌کند.', sap_num( $usage['budget'], 2 ) ) );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( (int) $args['timeout'] + 30 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$model = sap_ai_model_for( $args['purpose'] );
	return 'anthropic' === sap_setting( 'ai_provider', 'gateway' )
		? sap_ai_request_anthropic( $model, $system, $user, $schema, $args )
		: sap_ai_request_gateway( $model, $system, $user, $schema, $args );
}

/**
 * OpenAI-compatible Chat Completions (GapGPT and similar gateways).
 */
function sap_ai_request_gateway( $model, $system, $user, $schema, $args ) {
	$base = untrailingslashit( esc_url_raw( sap_setting( 'ai_base_url', 'https://api.gapgpt.app/v1' ) ) );
	// The schema is also stated in the prompt, so the answer is right even when response_format is ignored.
	$system .= "\n\nپاسخ را فقط و فقط به صورت یک شیء JSON معتبر مطابق این JSON Schema بده، بدون هیچ متن یا توضیح اضافه و بدون ```:\n" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );
	$body = array(
		'model'           => $model,
		'max_tokens'      => (int) $args['max_tokens'],
		'messages'        => array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		),
		'response_format' => array(
			'type'        => 'json_schema',
			'json_schema' => array( 'name' => 'seo_result', 'schema' => $schema, 'strict' => true ),
		),
	);
	$send = function ( $body ) use ( $base, $args ) {
		return wp_remote_post( $base . '/chat/completions', array(
			'timeout' => (int) $args['timeout'],
			'headers' => array(
				'Authorization' => 'Bearer ' . sap_setting( 'ai_gateway_key' ),
				'Content-Type'  => 'application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
	};
	$res  = $send( $body );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	// Some gateway/model pairs reject structured output: retry once in plain JSON mode.
	if ( in_array( $code, array( 400, 422 ), true ) ) {
		unset( $body['response_format'] );
		$res  = $send( $body );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}
	if ( is_wp_error( $res ) ) {
		return new WP_Error( 'sap_ai_http', 'اتصال به سرویس هوش مصنوعی ناموفق بود: ' . $res->get_error_message() );
	}
	$data = json_decode( wp_remote_retrieve_body( $res ), true );
	if ( 200 !== $code ) {
		$msg = (string) ( $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : 'HTTP ' . $code ) );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'sap_ai_auth', 'کلید API سرویس هوش مصنوعی نامعتبر است یا به این مدل دسترسی ندارد.' );
		}
		if ( 402 === $code || false !== stripos( $msg, 'credit' ) || false !== stripos( $msg, 'balance' ) || false !== stripos( $msg, 'quota' ) ) {
			return new WP_Error( 'sap_ai_credit', 'اعتبار حساب سرویس هوش مصنوعی کافی نیست؛ حساب را شارژ کنید.' );
		}
		if ( 429 === $code ) {
			return new WP_Error( 'sap_ai_rate', 'سقف درخواست سرویس هوش مصنوعی پر شده؛ چند دقیقه بعد دوباره امتحان کنید.' );
		}
		return new WP_Error( 'sap_ai_http', 'خطای سرویس هوش مصنوعی: ' . mb_substr( $msg, 0, 300 ) );
	}
	$in   = (int) ( $data['usage']['prompt_tokens'] ?? 0 );
	$out  = (int) ( $data['usage']['completion_tokens'] ?? 0 );
	sap_ai_track_usage( $model, $in, $out );
	$text = (string) ( $data['choices'][0]['message']['content'] ?? '' );
	if ( 'length' === ( $data['choices'][0]['finish_reason'] ?? '' ) ) {
		return new WP_Error( 'sap_ai_truncated', 'پاسخ هوش مصنوعی ناقص ماند (طولانی‌تر از سقف). دوباره امتحان کنید.' );
	}
	$json = sap_ai_extract_json( $text );
	if ( null === $json ) {
		return new WP_Error( 'sap_ai_parse', 'پاسخ هوش مصنوعی قابل خواندن نبود؛ دوباره امتحان کنید یا مدل دیگری انتخاب کنید.' );
	}
	return $json;
}

/**
 * Anthropic Messages API (structured output, server-side fallback on refusal).
 */
function sap_ai_request_anthropic( $model, $system, $user, $schema, $args ) {
	$body    = array(
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
		'x-api-key'         => sap_setting( 'anthropic_key' ),
		'anthropic-version' => SAP_CLAUDE_VERSION,
		'content-type'      => 'application/json',
	);
	if ( in_array( $model, array( 'claude-opus-5-5', 'claude-sonnet-5-5' ), true ) ) {
		$body['fallbacks']         = 'default';
		$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
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
		return new WP_Error( 'sap_ai_' . ( $type ?: 'http' ), $msgs[ $type ] ?? ( 'خطای سرویس هوش مصنوعی: ' . ( $data['error']['message'] ?? 'HTTP ' . $code ) ) );
	}
	$u = (array) ( $data['usage'] ?? array() );
	sap_ai_track_usage( $model, (int) ( $u['input_tokens'] ?? 0 ) + (int) ( $u['cache_read_input_tokens'] ?? 0 ) + (int) ( $u['cache_creation_input_tokens'] ?? 0 ), (int) ( $u['output_tokens'] ?? 0 ) );
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
	$json = sap_ai_extract_json( $text );
	return null === $json ? new WP_Error( 'sap_ai_parse', 'پاسخ هوش مصنوعی قابل خواندن نبود.' ) : $json;
}

/**
 * JSON object from a model answer (tolerates ```json fences and surrounding text).
 */
function sap_ai_extract_json( $text ) {
	$text = trim( (string) $text );
	$json = json_decode( $text, true );
	if ( is_array( $json ) ) {
		return $json;
	}
	$text  = preg_replace( '/^```(?:json)?\s*|\s*```$/i', '', $text );
	$start = strpos( $text, '{' );
	$end   = strrpos( $text, '}' );
	if ( false === $start || false === $end || $end <= $start ) {
		return null;
	}
	$json = json_decode( substr( $text, $start, $end - $start + 1 ), true );
	return is_array( $json ) ? $json : null;
}
