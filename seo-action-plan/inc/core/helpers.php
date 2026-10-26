<?php
/**
 * Formatting, text matching, storage and security helpers.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Convert Latin digits to Persian digits.
 */
function sap_fa( $value ) {
	return strtr( (string) $value, array(
		'0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
		'5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
	) );
}

/**
 * Persian formatted number with thousands separator.
 */
function sap_num( $n, $decimals = 0 ) {
	if ( null === $n || '' === $n ) {
		return '—';
	}
	$s = number_format( (float) $n, $decimals, '.', ',' );
	if ( $decimals > 0 ) {
		$s = rtrim( rtrim( $s, '0' ), '.' );
	}
	return sap_fa( strtr( $s, array( ',' => '٬', '.' => '٫' ) ) );
}

/**
 * Percent from a ratio (0.123 → ۱۲٫۳٪).
 */
function sap_pct( $ratio, $decimals = 1 ) {
	if ( null === $ratio ) {
		return '—';
	}
	return sap_num( $ratio * 100, $decimals ) . '٪';
}

/**
 * Safe array getter with dot notation.
 */
function sap_get( $arr, $path, $default = null ) {
	foreach ( explode( '.', (string) $path ) as $k ) {
		if ( is_array( $arr ) && array_key_exists( $k, $arr ) ) {
			$arr = $arr[ $k ];
		} else {
			return $default;
		}
	}
	return $arr;
}

/**
 * Normalize Persian/Arabic text for matching.
 */
function sap_norm_text( $s ) {
	$s = (string) $s;
	$s = strtr( $s, array(
		'ي' => 'ی', 'ك' => 'ک', 'ى' => 'ی', 'ة' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'ؤ' => 'و',
		"\u{200C}" => ' ', "\u{200F}" => '', "\u{200E}" => '',
		'۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
		'۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
	) );
	$s = function_exists( 'mb_strtolower' ) ? mb_strtolower( $s, 'UTF-8' ) : strtolower( $s );
	return trim( preg_replace( '/\s+/u', ' ', $s ) );
}

/**
 * Keyword join key: normalized and without spaces, so «اسکوتر برقی», «اسکوتر‌برقی»
 * and «اسکوتربرقی» match the same Search Console query.
 */
function sap_kw_key( $s ) {
	return str_replace( ' ', '', sap_norm_text( $s ) );
}

/**
 * Words of a normalized phrase.
 */
function sap_tokens( $s ) {
	$s = preg_replace( '/[^\p{L}\p{N}\s]+/u', ' ', sap_norm_text( $s ) );
	return array_values( array_filter( explode( ' ', (string) $s ), function ( $w ) {
		return mb_strlen( $w ) > 1;
	} ) );
}

/**
 * Normalize a URL so GSC, Screaming Frog and sheet URLs can be joined.
 */
function sap_norm_url( $url ) {
	$url = trim( (string) $url );
	if ( '' === $url ) {
		return '';
	}
	$url   = rawurldecode( $url );
	$parts = wp_parse_url( $url );
	if ( empty( $parts['host'] ) ) {
		return rtrim( $url, '/' );
	}
	$host = strtolower( preg_replace( '/^www\./', '', $parts['host'] ) );
	$path = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
	$q    = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
	return $host . ( '' === $path ? '/' : $path ) . $q;
}

/**
 * Shorten a URL for display: path only, decoded.
 */
function sap_short_url( $url, $max = 60 ) {
	$url   = rawurldecode( (string) $url );
	$parts = wp_parse_url( $url );
	$path  = ( $parts['path'] ?? '/' ) . ( isset( $parts['query'] ) ? '?' . $parts['query'] : '' );
	if ( '' === $path || '/' === $path ) {
		return 'صفحه اصلی';
	}
	if ( mb_strlen( $path ) > $max ) {
		$path = mb_substr( $path, 0, $max - 1 ) . '…';
	}
	return $path;
}

/**
 * Does a URL match one of the focus patterns?
 *
 * A pattern is a path prefix (/product-category/scooter/), may contain * wildcards,
 * or is a full URL. Matching is done on the normalized path.
 */
function sap_url_matches( $url, $patterns ) {
	if ( empty( $patterns ) ) {
		return false;
	}
	$parts = wp_parse_url( rawurldecode( (string) $url ) );
	$path  = sap_norm_text( ( $parts['path'] ?? '/' ) );
	foreach ( (array) $patterns as $p ) {
		$p = trim( (string) $p );
		if ( '' === $p ) {
			continue;
		}
		if ( preg_match( '#^https?://#i', $p ) ) {
			$pp = wp_parse_url( rawurldecode( $p ) );
			$p  = $pp['path'] ?? '/';
		}
		$p = sap_norm_text( $p );
		if ( false !== strpos( $p, '*' ) ) {
			$re = '#^' . str_replace( '\*', '.*', preg_quote( $p, '#' ) ) . '#u';
			if ( preg_match( $re, $path ) ) {
				return true;
			}
		} elseif ( 0 === strpos( $path, $p ) || 0 === strpos( $path . '/', $p ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Compress data for storage.
 */
function sap_pack( $data ) {
	return base64_encode( gzcompress( wp_json_encode( $data ), 6 ) );
}

/**
 * Decompress stored data.
 */
function sap_unpack( $packed ) {
	if ( empty( $packed ) || ! is_string( $packed ) ) {
		return array();
	}
	$raw = base64_decode( $packed, true );
	if ( false === $raw ) {
		return array();
	}
	$json = @gzuncompress( $raw ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	$data = false === $json ? null : json_decode( $json, true );
	return is_array( $data ) ? $data : array();
}

/**
 * Encrypt a secret (API keys, tokens) before storing in the database.
 */
function sap_encrypt( $plain ) {
	$plain = (string) $plain;
	if ( '' === $plain ) {
		return '';
	}
	if ( ! function_exists( 'sodium_crypto_secretbox' ) ) {
		return 'b64:' . base64_encode( $plain );
	}
	$key   = hash( 'sha256', wp_salt( 'auth' ) . 'seo-action-plan', true );
	$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	return 'sb1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) );
}

/**
 * Decrypt a stored secret.
 */
function sap_decrypt( $stored ) {
	$stored = (string) $stored;
	if ( '' === $stored ) {
		return '';
	}
	if ( 0 === strpos( $stored, 'b64:' ) ) {
		return (string) base64_decode( substr( $stored, 4 ) );
	}
	if ( 0 !== strpos( $stored, 'sb1:' ) || ! function_exists( 'sodium_crypto_secretbox_open' ) ) {
		return $stored;
	}
	$raw   = base64_decode( substr( $stored, 4 ) );
	$key   = hash( 'sha256', wp_salt( 'auth' ) . 'seo-action-plan', true );
	$nonce = substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
	$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), $nonce, $key );
	return false === $plain ? '' : $plain;
}

/**
 * Mask a secret for display.
 */
function sap_mask( $secret ) {
	$secret = (string) $secret;
	if ( strlen( $secret ) < 8 ) {
		return '' === $secret ? '' : '••••';
	}
	return substr( $secret, 0, 4 ) . str_repeat( '•', 8 ) . substr( $secret, -4 );
}

/**
 * Split a textarea / cell into a clean list (newline, comma, Persian comma).
 */
function sap_list( $text ) {
	$lines = preg_split( '/\r\n|\r|\n|,|،|;|؛/u', (string) $text );
	return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
}

/**
 * Split a textarea by newline only.
 */
function sap_rows( $text ) {
	$lines = preg_split( '/\r\n|\r|\n/u', (string) $text );
	return array_values( array_filter( array_map( 'trim', $lines ), 'strlen' ) );
}

/**
 * Sort rows by a key (desc by default).
 */
function sap_sort( $rows, $key, $desc = true ) {
	usort( $rows, function ( $a, $b ) use ( $key, $desc ) {
		$x = $a[ $key ] ?? 0;
		$y = $b[ $key ] ?? 0;
		return $desc ? $y <=> $x : $x <=> $y;
	} );
	return $rows;
}

/**
 * Current time in MySQL format (site timezone).
 */
function sap_now() {
	return current_time( 'mysql' );
}

/**
 * Can the current user manage the tool?
 */
function sap_can() {
	return current_user_can( SAP_CAP ) || current_user_can( 'manage_options' );
}

/**
 * Can the current user work on tasks (manager or team member)?
 */
function sap_can_work() {
	return sap_can() || current_user_can( SAP_CAP_WORK );
}

/**
 * Inline SVG icon set (stroke icons, 24px grid).
 */
function sap_icon( $name, $size = 18 ) {
	$paths = array(
		'grid'     => '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>',
		'plus'     => '<path d="M12 5v14M5 12h14"/>',
		'check'    => '<path d="M20 6 9 17l-5-5"/>',
		'x'        => '<path d="M18 6 6 18M6 6l12 12"/>',
		'alert'    => '<path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/><path d="M12 9v4M12 17h.01"/>',
		'refresh'  => '<path d="M21 12a9 9 0 1 1-2.6-6.4L21 8"/><path d="M21 3v5h-5"/>',
		'helm'     => '<circle cx="12" cy="12" r="6.5"/><circle cx="12" cy="12" r="2"/><path d="M12 2v3.5M12 18.5V22M2 12h3.5M18.5 12H22M4.9 4.9l2.5 2.5M16.6 16.6l2.5 2.5M4.9 19.1l2.5-2.5M16.6 7.4l2.5-2.5"/>',
		'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
		'inbox'    => '<path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1z"/>',
		'list'     => '<path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/>',
		'log'      => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5M9 13h6M9 17h6"/>',
		'settings' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
		'spark'    => '<path d="M12 3v4M12 17v4M3 12h4M17 12h4M5.6 5.6l2.8 2.8M15.6 15.6l2.8 2.8M18.4 5.6l-2.8 2.8M8.4 15.6l-2.8 2.8"/>',
		'link'     => '<path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/>',
		'trash'    => '<path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/>',
		'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
		'download' => '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/>',
	);
	$p = $paths[ $name ] ?? $paths['grid'];
	return sprintf(
		'<svg class="sap-icon" width="%1$d" height="%1$d" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
		(int) $size,
		$p
	);
}

/**
 * Work areas (GTD contexts). Shared by findings, tasks, activity log and the report export.
 */
function sap_contexts() {
	return array(
		'research'       => 'کیورد ریسرچ و تحقیق',
		'content_new'    => 'تولید محتوا',
		'content_update' => 'بهینه‌سازی و آپدیت محتوا',
		'onpage'         => 'سئو داخلی (تایتل، متا، اسکیما)',
		'internal_links' => 'لینک‌سازی داخلی',
		'technical'      => 'سئو تکنیکال',
		'offpage'        => 'آف‌پیج و لینک‌سازی',
		'cro'            => 'CRO و نرخ تبدیل',
		'management'     => 'مدیریت و گزارش',
	);
}

/**
 * Label of a work area.
 */
function sap_context_label( $key ) {
	$c = sap_contexts();
	return $c[ $key ] ?? 'سایر';
}

/**
 * Render an admin template with variables.
 */
function sap_view( $name, $vars = array() ) {
	$file = SAP_DIR . '/templates/app/views/' . $name . '.php';
	if ( ! file_exists( $file ) ) {
		return;
	}
	extract( $vars, EXTR_SKIP ); // phpcs:ignore WordPress.PHP.DontExtract
	include $file;
}

/**
 * Random token for API keys.
 */
function sap_token( $len = 32 ) {
	return wp_generate_password( $len, false, false );
}
