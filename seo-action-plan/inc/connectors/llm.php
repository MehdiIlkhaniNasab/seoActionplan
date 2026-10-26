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
	$args = wp_parse_args( $args, array( 'purpose' => 'analysis', 'max_tokens' => 6000, 'effort' => 'low', 'timeout' => 300 ) );
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
	$call  = function ( $args ) use ( $model, $system, $user, $schema ) {
		return 'anthropic' === sap_setting( 'ai_provider', 'gateway' )
			? sap_ai_request_anthropic( $model, $system, $user, $schema, $args )
			: sap_ai_request_gateway( $model, $system, $user, $schema, $args );
	};
	$res = $call( $args );
	// Cut off before even one item was complete: one retry with more room and a request to be brief.
	if ( is_wp_error( $res ) && 'sap_ai_truncated' === $res->get_error_code() && empty( $args['no_retry'] ) ) {
		sap_ai_mark_wasted();
		$args['max_tokens'] = (int) ceil( $args['max_tokens'] * 1.6 );
		$args['no_retry']   = true;
		$user              .= "\n\nمهم: پاسخ قبلی طولانی شد و قطع شد. این بار موارد را کوتاه‌تر و فشرده‌تر بنویس و فقط مهم‌ترین‌ها را بیاور.";
		$res                = $call( $args );
	}
	if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'sap_ai_truncated', 'sap_ai_parse' ), true ) ) {
		sap_ai_mark_wasted();
	}
	return $res;
}

/**
 * The last call was paid but produced nothing usable: count it separately, so the
 * usage panel shows how much was lost to failed answers.
 */
function sap_ai_mark_wasted() {
	$u = sap_ai_usage();
	unset( $u['budget'] );
	$u['wasted']      = round( (float) ( $u['wasted'] ?? 0 ) + (float) $u['last_cost'], 5 );
	$u['failed']      = (int) ( $u['failed'] ?? 0 ) + 1;
	$u['last_cost']   = 0;
	update_option( 'sap_ai_usage', $u, false );
}

/**
 * Decode a model answer; when the answer was cut off at the token limit, keep
 * every item that was complete instead of throwing the whole (paid) answer away.
 *
 * @return array|WP_Error JSON (with '_partial' => true when salvaged).
 */
function sap_ai_decode_answer( $text, $truncated ) {
	$json = $truncated ? null : sap_ai_extract_json( $text );
	if ( null === $json ) {
		$json = sap_ai_salvage_json( $text );
		if ( null !== $json && $truncated ) {
			// The item that was being written when the answer stopped is incomplete: drop it.
			foreach ( $json as $k => $v ) {
				if ( is_array( $v ) && count( $v ) > 1 && array_is_list( $v ) && is_array( end( $v ) ) && is_array( $v[0] ) && count( end( $v ) ) < count( $v[0] ) ) {
					array_pop( $json[ $k ] );
				}
			}
			$json['_partial'] = true;
		}
	}
	if ( null === $json ) {
		return $truncated
			? new WP_Error( 'sap_ai_truncated', 'پاسخ هوش مصنوعی به سقف طول رسید و ناقص ماند. دوباره امتحان کنید.' )
			: new WP_Error( 'sap_ai_parse', 'پاسخ هوش مصنوعی قابل خواندن نبود؛ دوباره امتحان کنید یا مدل دیگری انتخاب کنید.' );
	}
	return $json;
}

/**
 * Repair a JSON object that stops mid-way: cut back to the last complete value
 * and close the open brackets. Returns null when nothing complete is left.
 */
function sap_ai_salvage_json( $text ) {
	$text  = preg_replace( '/^\s*```(?:json)?\s*/i', '', (string) $text );
	$start = strpos( $text, '{' );
	if ( false === $start ) {
		return null;
	}
	$text  = substr( $text, $start );
	$len   = strlen( $text );
	$stack = array();
	$cuts  = array();
	$in    = false;
	$esc   = false;
	for ( $i = 0; $i < $len; $i++ ) {
		$ch = $text[ $i ];
		if ( $in ) {
			if ( $esc ) {
				$esc = false;
			} elseif ( '\\' === $ch ) {
				$esc = true;
			} elseif ( '"' === $ch ) {
				$in = false;
			}
			continue;
		}
		if ( '"' === $ch ) {
			$in = true;
		} elseif ( '{' === $ch || '[' === $ch ) {
			$stack[] = '{' === $ch ? '}' : ']';
		} elseif ( '}' === $ch || ']' === $ch ) {
			array_pop( $stack );
			$cuts[] = array( $i + 1, $stack );
			if ( ! $stack ) {
				break;
			}
		} elseif ( ',' === $ch ) {
			$cuts[] = array( $i, $stack );
		}
	}
	for ( $n = count( $cuts ) - 1, $tries = 0; $n >= 0 && $tries < 80; $n--, $tries++ ) {
		$cand = rtrim( rtrim( substr( $text, 0, $cuts[ $n ][0] ) ), ',' ) . implode( '', array_reverse( $cuts[ $n ][1] ) );
		$json = json_decode( $cand, true );
		if ( is_array( $json ) ) {
			return $json;
		}
	}
	return null;
}

/**
 * OpenAI-compatible Chat Completions (GapGPT and similar gateways).
 *
 * The answer is streamed (SSE): tokens keep flowing while the model writes, so
 * proxies and gateways that cut idle connections after ~100 s do not drop long
 * answers. The streamed text is assembled here once the response completes.
 */
function sap_ai_request_gateway( $model, $system, $user, $schema, $args ) {
	$base = untrailingslashit( esc_url_raw( sap_setting( 'ai_base_url', 'https://api.gapgpt.app/v1' ) ) );
	// The schema is also stated in the prompt, so the answer is right even when response_format is ignored.
	$system .= "\n\nپاسخ را فقط و فقط به صورت یک شیء JSON معتبر مطابق این JSON Schema بده، بدون هیچ متن یا توضیح اضافه و بدون ```. کوتاه و دقیق بنویس:\n" . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE );
	$body = array(
		'model'            => $model,
		'max_tokens'       => (int) $args['max_tokens'],
		'stream'           => true,
		'stream_options'   => array( 'include_usage' => true ),
		'reasoning_effort' => 'low' === $args['effort'] ? 'low' : 'medium',
		'messages'         => array(
			array( 'role' => 'system', 'content' => $system ),
			array( 'role' => 'user', 'content' => $user ),
		),
		'response_format'  => array(
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
				'Accept'        => 'text/event-stream, application/json',
			),
			'body'    => wp_json_encode( $body ),
		) );
	};
	$res  = $send( $body );
	$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	// Some gateway/model pairs reject optional parameters: retry once with the plain request.
	if ( in_array( $code, array( 400, 422 ), true ) ) {
		unset( $body['response_format'], $body['reasoning_effort'], $body['stream_options'] );
		$res  = $send( $body );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
	}
	if ( is_wp_error( $res ) ) {
		$msg = $res->get_error_message();
		if ( false !== strpos( $msg, 'cURL error 7' ) && defined( 'WP_PROXY_HOST' ) ) {
			$msg = sprintf( 'پروکسی وردپرس (%s:%s) در دسترس نیست. پروکسی را روشن کنید یا برای GapGPT در wp-config.php بنویسید: define( \'WP_PROXY_BYPASS_HOSTS\', \'api.gapgpt.app\' );', WP_PROXY_HOST, defined( 'WP_PROXY_PORT' ) ? WP_PROXY_PORT : '' );
		} elseif ( false !== strpos( $msg, 'cURL error 28' ) || false !== strpos( $msg, 'cURL error 56' ) ) {
			$msg = 'اتصال وسط دریافت پاسخ قطع شد (پروکسی یا شبکه). دوباره امتحان کنید؛ اگر تکرار شد، GapGPT را از پروکسی معاف کنید: define( \'WP_PROXY_BYPASS_HOSTS\', \'api.gapgpt.app\' );';
		}
		return new WP_Error( 'sap_ai_http', 'اتصال به سرویس هوش مصنوعی ناموفق بود: ' . $msg );
	}
	$raw = (string) wp_remote_retrieve_body( $res );
	if ( 200 !== $code ) {
		$data = json_decode( $raw, true );
		$msg  = (string) ( $data['error']['message'] ?? ( is_string( $data['error'] ?? null ) ? $data['error'] : 'HTTP ' . $code ) );
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
	$parsed = sap_ai_parse_stream( $raw );
	sap_ai_track_usage( $model, $parsed['in'], $parsed['out'] );
	return sap_ai_decode_answer( $parsed['text'], 'length' === $parsed['finish'] );
}

/**
 * Assemble a Chat Completions answer from an SSE stream (or a plain JSON body,
 * when the gateway ignored stream:true).
 *
 * @return array{text: string, in: int, out: int, finish: string}
 */
function sap_ai_parse_stream( $raw ) {
	$out = array( 'text' => '', 'in' => 0, 'out' => 0, 'finish' => '' );
	$one = json_decode( $raw, true );
	if ( is_array( $one ) && isset( $one['choices'] ) ) {
		$out['text']   = (string) ( $one['choices'][0]['message']['content'] ?? '' );
		$out['finish'] = (string) ( $one['choices'][0]['finish_reason'] ?? '' );
		$out['in']     = (int) ( $one['usage']['prompt_tokens'] ?? 0 );
		$out['out']    = (int) ( $one['usage']['completion_tokens'] ?? 0 );
		return $out;
	}
	foreach ( preg_split( '/\r?\n/', $raw ) as $line ) {
		$line = trim( $line );
		if ( 0 !== strpos( $line, 'data:' ) ) {
			continue;
		}
		$payload = trim( substr( $line, 5 ) );
		if ( '' === $payload || '[DONE]' === $payload ) {
			continue;
		}
		$chunk = json_decode( $payload, true );
		if ( ! is_array( $chunk ) ) {
			continue;
		}
		foreach ( (array) ( $chunk['choices'] ?? array() ) as $c ) {
			$out['text'] .= (string) ( $c['delta']['content'] ?? '' );
			if ( ! empty( $c['finish_reason'] ) ) {
				$out['finish'] = (string) $c['finish_reason'];
			}
		}
		if ( ! empty( $chunk['usage'] ) ) {
			$out['in']  = (int) ( $chunk['usage']['prompt_tokens'] ?? $out['in'] );
			$out['out'] = (int) ( $chunk['usage']['completion_tokens'] ?? $out['out'] );
		}
	}
	// No usage reported: estimate (Persian ≈ 2.5 characters per token) so the budget still counts it.
	if ( ! $out['out'] && '' !== $out['text'] ) {
		$out['out'] = (int) ceil( mb_strlen( $out['text'] ) / 2.5 );
	}
	return $out;
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
	$text = '';
	foreach ( (array) ( $data['content'] ?? array() ) as $block ) {
		if ( 'text' === ( $block['type'] ?? '' ) ) {
			$text .= (string) $block['text'];
		}
	}
	return sap_ai_decode_answer( $text, 'max_tokens' === $stop );
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
