<?php
/**
 * Site automation with approval (connector 1.5): internal links inserted in
 * the right sentence, structured data by page type, smart image alt text and
 * WooCommerce attributes per category. Every change is previewed in Sokan,
 * applied only after approval, logged as a done task (activity log + impact)
 * and can be rolled back.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * Common: previews, apply, log, rollback
 * ------------------------------------------------------------------------- */

/**
 * Automation types.
 */
function sap_auto_types() {
	return array(
		'link'       => array( 'لینک داخلی', 'link', 'onpage' ),
		'schema'     => array( 'اسکیما', 'code', 'technical' ),
		'alts'       => array( 'آلت تصاویر', 'image', 'onpage' ),
		'attributes' => array( 'ویژگی محصول', 'tag', 'content_update' ),
	);
}

/**
 * The connector of the project supports a feature?
 */
function sap_auto_ready( $project, $feature = '' ) {
	if ( ! sap_site_connected( $project ) ) {
		return new WP_Error( 'sap_site', 'اتصال به سایت کارفرما (افزونه رابط) برای این پروژه تنظیم نشده.' );
	}
	if ( '' === $feature ) {
		return true;
	}
	$key  = 'sap_auto_ping_' . $project['id'];
	$ping = get_transient( $key );
	if ( ! is_array( $ping ) ) {
		$ping = sap_site_request( $project, 'GET', 'ping' );
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}
		set_transient( $key, $ping, 10 * MINUTE_IN_SECONDS );
	}
	if ( ! in_array( $feature, (array) ( $ping['features'] ?? array() ), true ) ) {
		return new WP_Error( 'sap_conn_old', 'افزونه رابط سایت قدیمی است؛ برای این قابلیت نسخه ۱٫۵ به بالا را نصب کنید.' );
	}
	if ( empty( $ping['write'] ) ) {
		return new WP_Error( 'sap_conn_ro', 'اعمال تغییرات در تنظیمات افزونه رابط سایت غیرفعال است (تیک «اجازه اعمال تغییرات توسط سکان»).' );
	}
	return true;
}

/**
 * Fresh WordPress object of a URL from the connector.
 */
function sap_auto_obj( $project, $url ) {
	$obj = sap_site_request( $project, 'GET', 'resolve', array( 'url' => $url ) );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	set_transient( 'sap_sd_' . md5( $project['id'] . '|' . sap_norm_url( $url ) ), $obj, 6 * HOUR_IN_SECONDS );
	return $obj;
}

/**
 * Keep a preview until it is approved (the exact changes that were shown).
 *
 * @return string token
 */
function sap_auto_preview_put( $project_id, $preview, $ttl = DAY_IN_SECONDS ) {
	$token = strtolower( wp_generate_password( 12, false ) );
	set_transient( 'sap_auto_prev_' . $project_id . '_' . $token, $preview, ! empty( $GLOBALS['sap_autopilot_run'] ) ? 8 * DAY_IN_SECONDS : $ttl );
	return $token;
}

function sap_auto_preview_get( $project_id, $token ) {
	$p = get_transient( 'sap_auto_prev_' . $project_id . '_' . sanitize_key( $token ) );
	return is_array( $p ) ? $p : null;
}

/**
 * The automation log of a project (newest first).
 */
function sap_auto_log( $project_id, $type = '', $limit = 200 ) {
	$log = (array) ( sap_dataset_payload( $project_id, 'automation' )['log'] ?? array() );
	if ( $type ) {
		$log = array_values( array_filter( $log, function ( $e ) use ( $type ) {
			return $e['type'] === $type;
		} ) );
	}
	return array_slice( $log, 0, $limit );
}

function sap_auto_log_save( $project_id, $log ) {
	sap_dataset_put( $project_id, 'automation', array( 'log' => array_slice( array_values( $log ), 0, 600 ) ), array( 'count' => count( $log ) ) );
}

/**
 * Apply an approved preview on the site, log it and record a done task.
 *
 * @param array $edits Changes edited in the preview (replace the previewed ones of the same field).
 * @return array|WP_Error {msg, entry}
 */
function sap_auto_apply( $project, $token, $edits = array(), $source = 'user' ) {
	$pv = sap_auto_preview_get( $project['id'], $token );
	if ( ! $pv ) {
		return new WP_Error( 'sap_prev', 'این پیش‌نمایش منقضی شده؛ دوباره پیش‌نمایش بگیرید.', array( 'status' => 410 ) );
	}
	$changes = $pv['changes'];
	foreach ( (array) $edits as $field => $value ) {
		if ( isset( $changes[ $field ] ) ) {
			$changes[ $field ] = $value;
		}
	}
	// Content may have changed on the site since the preview: never overwrite newer text.
	if ( isset( $changes['content'] ) && ! empty( $pv['content_md5'] ) ) {
		$now = sap_auto_obj( $project, $pv['url'] );
		if ( is_wp_error( $now ) ) {
			return $now;
		}
		if ( md5( (string) $now['content'] ) !== $pv['content_md5'] ) {
			return new WP_Error( 'sap_changed', 'محتوای این صفحه بعد از پیش‌نمایش روی سایت تغییر کرده؛ برای جلوگیری از پاک شدن تغییرات، دوباره پیش‌نمایش بگیرید.', array( 'status' => 409 ) );
		}
	}
	$ref = 'a' . strtolower( wp_generate_password( 10, false ) );
	$res = sap_site_request( $project, 'POST', 'apply', array( 'kind' => $pv['kind'], 'id' => $pv['id'], 'changes' => $changes, 'mode' => 'publish', 'ref' => $ref, 'note' => $pv['label'] ), 60 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$types = sap_auto_types();
	$user  = wp_get_current_user();
	$entry = array(
		'ref'    => $ref,
		'type'   => $pv['type'],
		'kind'   => $pv['kind'],
		'id'     => $pv['id'],
		'url'    => $pv['url'],
		'label'  => $pv['label'],
		'detail' => $pv['detail'],
		'key'    => $pv['key'] ?? '',
		'at'     => sap_now(),
		'user'   => 'autopilot' === $source ? 'دستیار خودکار' : ( $user->exists() ? $user->display_name : '' ),
		'source' => $source,
		'status' => 'live',
	);
	$task_id = sap_task_save( array(
		'project_id' => $project['id'],
		'finding_id' => (int) ( $pv['finding_id'] ?? 0 ),
		'title'      => $pv['label'],
		'notes'      => $pv['detail'],
		'context'    => $types[ $pv['type'] ][2] ?? 'onpage',
		'status'     => 'next',
		'period'     => sap_period_current(),
		'effort'     => 0.25,
		'url'        => $pv['url'],
		'keyword'    => (string) ( $pv['keyword'] ?? '' ),
		'source'     => 'ai',
	) );
	sap_task_save( array( 'status' => 'done', 'result' => ( 'autopilot' === $source ? "اعمال خودکار توسط دستیار خودکار سکان (طبق سیاست پروژه).\n" : "اعمال خودکار روی سایت با سکان (بعد از تایید).\n" ) . $pv['detail'] ), $task_id );
	$entry['task_id'] = $task_id;
	$log              = sap_auto_log( $project['id'], '', 600 );
	array_unshift( $log, $entry );
	sap_auto_log_save( $project['id'], $log );
	delete_transient( 'sap_auto_prev_' . $project['id'] . '_' . sanitize_key( $token ) );
	delete_transient( 'sap_sd_' . md5( $project['id'] . '|' . sap_norm_url( $pv['url'] ) ) );
	if ( ! empty( $pv['finding_id'] ) && ! empty( $pv['finding_done'] ) ) {
		sap_finding_set_status( (int) $pv['finding_id'], 'done', $task_id );
	}
	return array( 'msg' => $pv['done_msg'] ?? 'روی سایت اعمال شد و در لاگ اقدامات ثبت شد؛ از بخش «تغییرات اعمال‌شده» قابل برگشت است.', 'entry' => $entry );
}

/**
 * Roll an applied change back.
 */
function sap_auto_rollback( $project, $ref ) {
	$log = sap_auto_log( $project['id'], '', 600 );
	foreach ( $log as $i => $e ) {
		if ( $e['ref'] !== $ref ) {
			continue;
		}
		if ( 'live' !== $e['status'] ) {
			return new WP_Error( 'sap_rb', 'این تغییر قبلاً برگردانده شده.' );
		}
		$res = sap_site_request( $project, 'POST', 'rollback', array( 'kind' => $e['kind'], 'id' => $e['id'], 'ref' => $ref ), 60 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$log[ $i ]['status'] = 'rolled_back';
		$log[ $i ]['rb_at']  = sap_now();
		sap_auto_log_save( $project['id'], $log );
		sap_activity_add( $project['id'], array( 'category' => 'onpage', 'action' => 'auto_rollback', 'title' => 'برگرداندن: ' . $e['label'], 'url' => $e['url'], 'source' => 'tool', 'ref' => 'rb:' . $ref ) );
		delete_transient( 'sap_sd_' . md5( $project['id'] . '|' . sap_norm_url( $e['url'] ) ) );
		return array( 'msg' => 'تغییر برگردانده شد و در لاگ اقدامات ثبت شد.' );
	}
	return new WP_Error( 'sap_rb', 'این تغییر در لاگ اتوماسیون پیدا نشد.', array( 'status' => 404 ) );
}

/* ---------------------------------------------------------------------------
 * Internal links: insert in the right sentence
 * ------------------------------------------------------------------------- */

/**
 * Text nodes of HTML where a link may go (not inside a, headings, script,
 * style, code, buttons, captions or shortcodes).
 *
 * @return array[] {start (byte offset), text}
 */
function sap_link_text_nodes( $html ) {
	$parts = preg_split( '#(<!--.*?-->|<[^>]*>)#s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_OFFSET_CAPTURE );
	$block = array( 'a', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'script', 'style', 'code', 'pre', 'button', 'figcaption', 'summary', 'title', 'select', 'textarea', 'nav' );
	$depth = array();
	$out   = array();
	foreach ( $parts as $p ) {
		list( $s, $off ) = $p;
		if ( '' === $s ) {
			continue;
		}
		if ( '<' === $s[0] ) {
			if ( preg_match( '#^<(/?)([a-z0-9]+)#i', $s, $m ) ) {
				$tag = strtolower( $m[2] );
				if ( in_array( $tag, $block, true ) && '/' !== substr( $s, -2, 1 ) ) {
					$depth[ $tag ] = max( 0, ( $depth[ $tag ] ?? 0 ) + ( '/' === $m[1] ? -1 : 1 ) );
				}
			}
			continue;
		}
		if ( array_sum( $depth ) > 0 || '' === trim( $s ) ) {
			continue;
		}
		$out[] = array( 'start' => $off, 'text' => $s );
	}
	return $out;
}

/**
 * Find a phrase in a text node with Persian-tolerant matching (ی/ي, ک/ك,
 * half-space, case) on word boundaries, outside shortcodes.
 *
 * @return array|null {offset, length} in bytes of the original text
 */
function sap_link_find( $text, $phrase ) {
	$chars = preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY );
	$norm  = '';
	$map   = array(); // Normalized char index → original char index.
	$prev  = '';
	foreach ( $chars as $i => $c ) {
		$n = sap_norm_text( $c );
		if ( '' === trim( $c ) || "\u{200C}" === $c ) {
			$n = ' ';
		}
		if ( ' ' === $n && ' ' === $prev ) {
			continue;
		}
		$norm .= $n;
		$map[] = $i;
		$prev  = $n;
	}
	$needle = sap_norm_text( $phrase );
	if ( '' === $needle ) {
		return null;
	}
	$nchars = preg_split( '//u', $norm, -1, PREG_SPLIT_NO_EMPTY );
	$hay    = implode( '', $nchars );
	$from   = 0;
	while ( false !== ( $pos = mb_strpos( $hay, $needle, $from ) ) ) {
		$end    = $pos + mb_strlen( $needle );
		$before = $pos > 0 ? $nchars[ $pos - 1 ] : ' ';
		$after  = $end < count( $nchars ) ? $nchars[ $end ] : ' ';
		$inside = substr_count( mb_substr( $hay, 0, $pos ), '[' ) > substr_count( mb_substr( $hay, 0, $pos ), ']' );
		if ( ! preg_match( '/[\p{L}\p{N}]/u', $before ) && ! preg_match( '/[\p{L}\p{N}]/u', $after ) && ! $inside ) {
			$c0 = $map[ $pos ];
			$c1 = $map[ $end - 1 ];
			$b0 = strlen( implode( '', array_slice( $chars, 0, $c0 ) ) );
			$b1 = strlen( implode( '', array_slice( $chars, 0, $c1 + 1 ) ) );
			return array( 'offset' => $b0, 'length' => $b1 - $b0 );
		}
		$from = $pos + 1;
	}
	return null;
}

/**
 * The sentence around a byte range of a text (for the preview).
 */
function sap_link_sentence( $text, $offset, $length ) {
	$start = 0;
	$end   = strlen( $text );
	foreach ( array( '.', '!', '?', '؟', "\n" ) as $d ) {
		$p = strrpos( substr( $text, 0, $offset ), $d );
		if ( false !== $p && $p + strlen( $d ) > $start ) {
			$start = $p + strlen( $d );
		}
		$q = strpos( $text, $d, $offset + $length );
		if ( false !== $q && $q + strlen( $d ) < $end ) {
			$end = $q + strlen( $d );
		}
	}
	return array( ltrim( substr( $text, $start, $offset - $start ) ), substr( $text, $offset, $length ), rtrim( substr( $text, $offset + $length, $end - $offset - $length ) ) );
}

/**
 * Plan the insertion of one link: the exact place in the source page, or an
 * AI rewrite of one sentence (minimal change) when the anchor text does not
 * appear as is.
 *
 * @return array|WP_Error preview
 */
function sap_link_insert_plan( $project, $source, $target, $anchor, $finding_id = 0, $allow_ai = true ) {
	$ok = sap_auto_ready( $project );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$anchor = trim( wp_strip_all_tags( (string) $anchor ) );
	$source = sap_full_url( $project, $source );
	$target = sap_full_url( $project, $target );
	if ( '' === $anchor || ! $source || ! $target ) {
		return new WP_Error( 'sap_link', 'صفحه مبدأ، مقصد و انکر لازم است.' );
	}
	if ( sap_norm_url( $source ) === sap_norm_url( $target ) ) {
		return new WP_Error( 'sap_link', 'صفحه مبدأ و مقصد یکی است؛ لینک به خود صفحه معنی ندارد.' );
	}
	$obj = sap_auto_obj( $project, $source );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	if ( 'elementor' === $obj['editor'] ) {
		return new WP_Error( 'sap_elementor', 'صفحه مبدأ با المنتور ساخته شده و متنش از بیرون قابل ویرایش نیست؛ این لینک را دستی در المنتور اضافه کنید.' );
	}
	$html = (string) $obj['content'];
	// Already links to the target?
	if ( preg_match_all( '#<a\b[^>]*href=(["\'])(.*?)\1#i', $html, $m ) ) {
		foreach ( $m[2] as $href ) {
			if ( sap_norm_url( sap_full_url( $project, html_entity_decode( $href ) ) ) === sap_norm_url( $target ) ) {
				return new WP_Error( 'sap_link_exists', 'صفحه مبدأ همین حالا به صفحه مقصد لینک دارد؛ لینک تکراری اضافه نمی‌شود.' );
			}
		}
	}
	$link   = function ( $text ) use ( $target ) {
		return '<a href="' . esc_url( $target ) . '">' . $text . '</a>';
	};
	$nodes  = sap_link_text_nodes( $html );
	$method = '';
	$new    = '';
	$before = '';
	$after  = '';
	foreach ( $nodes as $n ) {
		$hit = sap_link_find( $n['text'], $anchor );
		if ( ! $hit ) {
			continue;
		}
		$abs    = $n['start'] + $hit['offset'];
		$orig   = substr( $html, $abs, $hit['length'] );
		$new    = substr( $html, 0, $abs ) . $link( $orig ) . substr( $html, $abs + $hit['length'] );
		$sent   = sap_link_sentence( $n['text'], $hit['offset'], $hit['length'] );
		$before = $sent[0] . $sent[1] . $sent[2];
		$after  = $sent[0] . '[[' . $sent[1] . ']]' . $sent[2];
		$method = 'exact';
		break;
	}
	$why = '';
	if ( '' === $method ) {
		if ( ! $allow_ai ) {
			return new WP_Error( 'sap_link_nf', 'عبارت انکر عیناً در متن نیست (دستیار خودکار فقط درج دقیق انجام می‌دهد).' );
		}
		if ( ! sap_ai_connected() ) {
			return new WP_Error( 'sap_link_nf', 'عبارت انکر عیناً در متن صفحه مبدأ نیست و برای پیدا کردن جمله مناسب، کلید هوش مصنوعی لازم است.' );
		}
		$cands = array();
		foreach ( $nodes as $i => $n ) {
			$t = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( $n['text'], ENT_QUOTES, 'UTF-8' ) ) );
			if ( mb_strlen( $t ) >= 60 && count( $cands ) < 45 ) {
				$cands[] = array( 'i' => $i, 'text' => mb_substr( $t, 0, 600 ) );
			}
		}
		if ( ! $cands ) {
			return new WP_Error( 'sap_link_nf', 'صفحه مبدأ متن کافی برای جای دادن لینک ندارد.' );
		}
		$tname  = sap_page_title( $project['id'], $target ) ?: sap_short_url( $target, 60 );
		$str    = array( 'type' => 'string' );
		$schema = sap_ai_obj( array(
			'node'         => array( 'type' => 'integer', 'description' => 'شماره i بندی که لینک در آن می‌نشیند' ),
			'mode'         => array( 'type' => 'string', 'enum' => array( 'rewrite', 'append' ) ),
			'sentence'     => array( 'type' => 'string', 'description' => 'rewrite: جمله اصلی عیناً و حرف به حرف از متن همان بند. append: خالی' ),
			'new_sentence' => array( 'type' => 'string', 'description' => 'جمله جدید که عبارت انکر را دقیقاً یک بار و عیناً دارد' ),
			'why'          => $str,
		) );
		$task = implode( "\n", array(
			'تو ویراستار سئو هستی. باید یک لینک داخلی با انکر دقیق «' . $anchor . '» به صفحه «' . $tname . '» در یکی از بندهای صفحه مبدأ بگذاریم، طوری که طبیعی و مفید برای خواننده باشد.',
			'مرتبط‌ترین بند را انتخاب کن. اگر جمله‌ای هست که با کمترین تغییر می‌تواند عبارت انکر را داشته باشد، mode=rewrite: sentence را حرف به حرف از متن کپی کن و new_sentence همان جمله با حداقل تغییر و حاوی عبارت انکر باشد (معنی و لحن حفظ شود).',
			'اگر هیچ جمله‌ای مناسب نیست، mode=append: یک جمله کوتاه و مفید که عبارت انکر را دارد بنویس تا به انتهای آن بند اضافه شود. هرگز تیتر یا اطلاعات نادرست نساز.',
		) );
		$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( array( 'anchor' => $anchor, 'target' => array( 'title' => $tname, 'url' => rawurldecode( $target ) ), 'source_title' => $obj['title'], 'paragraphs' => $cands ), JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'assist', 'max_tokens' => 900, 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$n  = $nodes[ (int) ( $res['node'] ?? -1 ) ] ?? null;
		$ns = trim( (string) ( $res['new_sentence'] ?? '' ) );
		$in = $ns ? sap_link_find( $ns, $anchor ) : null;
		if ( ! $n || ! $in ) {
			return new WP_Error( 'sap_link_ai', 'هوش مصنوعی جای مطمئنی برای این لینک پیدا نکرد؛ لینک را دستی اضافه کنید.' );
		}
		$ns_html = esc_html( substr( $ns, 0, $in['offset'] ) ) . $link( esc_html( substr( $ns, $in['offset'], $in['length'] ) ) ) . esc_html( substr( $ns, $in['offset'] + $in['length'] ) );
		$marked  = substr( $ns, 0, $in['offset'] ) . '[[' . substr( $ns, $in['offset'], $in['length'] ) . ']]' . substr( $ns, $in['offset'] + $in['length'] );
		if ( 'rewrite' === ( $res['mode'] ?? '' ) ) {
			$old = trim( (string) $res['sentence'] );
			$pos = '' !== $old ? strpos( $n['text'], $old ) : false; // Not verbatim (or entities inside): append instead.
			if ( false !== $pos ) {
				$abs    = $n['start'] + $pos;
				$new    = substr( $html, 0, $abs ) . $ns_html . substr( $html, $abs + strlen( $old ) );
				$before = $old;
				$after  = $marked;
				$method = 'rewrite';
			}
		}
		if ( '' === $method ) {
			$abs    = $n['start'] + strlen( rtrim( $n['text'] ) );
			$new    = substr( $html, 0, $abs ) . ' ' . $ns_html . substr( $html, $abs );
			$tail   = trim( mb_substr( trim( html_entity_decode( $n['text'], ENT_QUOTES, 'UTF-8' ) ), -140 ) );
			$before = '…' . $tail;
			$after  = '…' . $tail . ' ' . $marked;
			$method = 'append';
		}
		$why = (string) ( $res['why'] ?? '' );
	}
	$sname   = $obj['title'] ?: sap_short_url( $source, 50 );
	$tname   = sap_page_title( $project['id'], $target ) ?: sap_short_url( $target, 50 );
	$methods = array( 'exact' => 'عبارت انکر عیناً در متن بود؛ فقط لینک روی آن گذاشته می‌شود.', 'rewrite' => 'یک جمله با کمترین تغییر بازنویسی می‌شود تا انکر را داشته باشد.', 'append' => 'یک جمله کوتاه به انتهای بند مرتبط اضافه می‌شود.' );
	$preview = array(
		'type'        => 'link',
		'kind'        => $obj['kind'],
		'id'          => $obj['id'],
		'url'         => $source,
		'changes'     => array( 'content' => $new ),
		'content_md5' => md5( $html ),
		'label'       => 'لینک داخلی «' . $anchor . '» از «' . $sname . '» به «' . $tname . '»',
		'detail'      => sprintf( "مبدأ: %s\nمقصد: %s\nانکر: «%s»\nروش: %s\nجمله: %s", rawurldecode( $source ), rawurldecode( $target ), $anchor, $methods[ $method ], str_replace( array( '[[', ']]' ), '', $after ) ),
		'key'         => 'link:' . md5( sap_norm_url( $source ) . '|' . sap_norm_url( $target ) ),
		'finding_id'  => (int) $finding_id,
		'done_msg'    => 'لینک در جمله مشخص‌شده روی سایت درج شد و در لاگ ثبت شد.',
	);
	return array(
		'token'   => sap_auto_preview_put( $project['id'], $preview ),
		'method'  => $method,
		'note'    => $methods[ $method ],
		'before'  => trim( $before ),
		'after'   => trim( $after ),
		'why'     => $why,
		'source'  => array( 'title' => $sname, 'url' => $source ),
		'target'  => array( 'title' => $tname, 'url' => $target ),
		'anchor'  => $anchor,
	);
}

/**
 * Open link suggestions of the project as rows {finding_id, i, source, target, anchor, placement, done}.
 */
function sap_link_queue( $project ) {
	$done = array();
	foreach ( sap_auto_log( $project['id'], 'link', 600 ) as $e ) {
		if ( 'live' === $e['status'] ) {
			$done[ $e['key'] ] = $e;
		}
	}
	$rows = array();
	foreach ( array_merge( sap_findings( $project['id'], array( 'status' => 'open' ) ), sap_findings( $project['id'], array( 'status' => 'planned' ) ) ) as $f ) {
		foreach ( (array) ( $f['evidence']['meta']['links'] ?? array() ) as $i => $l ) {
			if ( empty( $l['url'] ) || empty( $l['anchor'] ) ) {
				continue;
			}
			$key    = 'link:' . md5( sap_norm_url( sap_full_url( $project, $l['url'] ) ) . '|' . sap_norm_url( sap_full_url( $project, $f['url'] ) ) );
			$rows[] = array(
				'finding_id' => $f['id'],
				'i'          => $i,
				'source'     => sap_full_url( $project, $l['url'] ),
				'source_t'   => (string) ( $l['page'] ?? '' ),
				'target'     => sap_full_url( $project, $f['url'] ),
				'anchor'     => (string) $l['anchor'],
				'placement'  => (string) ( $l['placement'] ?? '' ),
				'done'       => $done[ $key ] ?? null,
			);
		}
	}
	return $rows;
}

/* ---------------------------------------------------------------------------
 * Structured data by page type
 * ------------------------------------------------------------------------- */

/**
 * Page nature of a resolved object: product | article | category | home | page.
 */
function sap_schema_nature( $obj ) {
	if ( ! empty( $obj['front'] ) ) {
		return 'home';
	}
	$type = (string) $obj['type'];
	if ( 'product' === $type ) {
		return 'product';
	}
	if ( 'post' === $type ) {
		return 'article';
	}
	if ( 'term' === $obj['kind'] ) {
		return 'category';
	}
	return 'page';
}

/**
 * Question → answer pairs written in the content: question headings (ending
 * with ؟/? or starting with a question word) and <details><summary>.
 *
 * @return array[] {q, a}
 */
function sap_schema_faq_from_html( $html ) {
	$out  = array();
	$html = (string) $html;
	if ( preg_match_all( '#<details\b[^>]*>\s*<summary\b[^>]*>(.*?)</summary>(.*?)</details>#is', $html, $m, PREG_SET_ORDER ) ) {
		foreach ( $m as $x ) {
			$out[] = array( 'q' => trim( wp_strip_all_tags( $x[1] ) ), 'a' => trim( preg_replace( '/\s+/u', ' ', wp_strip_all_tags( $x[2] ) ) ) );
		}
	}
	$parts = preg_split( '#(<h[2-4]\b[^>]*>.*?</h[2-4]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$qw    = '/^(چرا|چگونه|چطور|آیا|چه|چی|کدام|چقدر|چند|کی|کجا|how|what|why|which|when|where|can|is|do)\b/iu';
	for ( $i = 1; $i < count( $parts ); $i += 2 ) {
		$q = trim( html_entity_decode( wp_strip_all_tags( $parts[ $i ] ), ENT_QUOTES, 'UTF-8' ) );
		$a = trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<!--.*?-->#s', '', $parts[ $i + 1 ] ?? '' ) ), ENT_QUOTES, 'UTF-8' ) ) );
		if ( mb_strlen( $q ) < 8 || mb_strlen( $a ) < 15 ) {
			continue;
		}
		if ( preg_match( '/[؟?]\s*$/u', $q ) || preg_match( $qw, $q ) ) {
			$out[] = array( 'q' => $q, 'a' => mb_substr( $a, 0, 900 ) );
		}
	}
	$uniq = array();
	foreach ( $out as $x ) {
		$uniq[ sap_norm_text( $x['q'] ) ] = $x;
	}
	return array_slice( array_values( $uniq ), 0, 12 );
}

/**
 * Required / recommended fields check of a block (Google rich results rules).
 *
 * @return array {errors[], warnings[]}
 */
function sap_schema_validate( $b ) {
	$e    = array();
	$w    = array();
	$type = (string) ( $b['@type'] ?? '' );
	$need = function ( $field, $label, $hard = true ) use ( $b, &$e, &$w ) {
		$v = $b[ $field ] ?? null;
		if ( null === $v || '' === $v || array() === $v ) {
			if ( $hard ) {
				$e[] = $label . ' ندارد';
			} else {
				$w[] = $label . ' ندارد (توصیه‌شده)';
			}
		}
	};
	switch ( $type ) {
		case 'Product':
			$need( 'name', 'نام' );
			$need( 'image', 'تصویر' );
			if ( empty( $b['offers'] ) && empty( $b['aggregateRating'] ) && empty( $b['review'] ) ) {
				$e[] = 'باید قیمت (offers) یا امتیاز/نقد داشته باشد';
			}
			if ( ! empty( $b['offers'] ) && ( empty( $b['offers']['price'] ) || '0' === (string) $b['offers']['price'] ) ) {
				$w[] = 'قیمت محصول خالی است (محصول بدون قیمت در نتایج قیمت نشان نمی‌دهد)';
			}
			$need( 'brand', 'برند', false );
			$need( 'sku', 'کد محصول (SKU)', false );
			$need( 'aggregateRating', 'امتیاز کاربران', false );
			break;
		case 'Article':
		case 'BlogPosting':
			$need( 'headline', 'تیتر' );
			$need( 'image', 'تصویر', false );
			$need( 'datePublished', 'تاریخ انتشار', false );
			$need( 'author', 'نویسنده', false );
			if ( mb_strlen( (string) ( $b['headline'] ?? '' ) ) > 110 ) {
				$w[] = 'تیتر بلندتر از ۱۱۰ کاراکتر است';
			}
			break;
		case 'FAQPage':
			if ( count( (array) ( $b['mainEntity'] ?? array() ) ) < 2 ) {
				$e[] = 'حداقل ۲ سوال و جواب لازم است';
			}
			break;
		case 'BreadcrumbList':
			if ( count( (array) ( $b['itemListElement'] ?? array() ) ) < 2 ) {
				$e[] = 'حداقل ۲ سطح لازم است';
			}
			break;
		case 'CollectionPage':
			$need( 'name', 'نام' );
			break;
		case 'WebSite':
			$need( 'name', 'نام' );
			$need( 'url', 'آدرس' );
			break;
	}
	return array( 'errors' => $e, 'warnings' => $w );
}

/**
 * Build the structured data a page needs, from its real data, skipping types
 * another plugin already prints.
 *
 * @return array|WP_Error preview
 */
function sap_schema_plan( $project, $url ) {
	$ok = sap_auto_ready( $project, 'schema' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$url = sap_full_url( $project, $url );
	$obj = sap_auto_obj( $project, $url );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	$d      = (array) ( $obj['details'] ?? array() );
	$site   = (array) ( $obj['site'] ?? array() );
	$page   = $obj['url'] ?: $url;
	$nature = sap_schema_nature( $obj );
	// Types already on the live page from other plugins (ours are replaced as a whole).
	$live = sap_page_fetch( $page );
	$ours = array();
	foreach ( (array) ( $obj['sokan_schema'] ?? array() ) as $j ) {
		$x = json_decode( (string) $j, true );
		if ( ! empty( $x['@type'] ) ) {
			$ours[] = (string) $x['@type'];
		}
	}
	$other = array();
	if ( ! is_wp_error( $live ) ) {
		$count = array_count_values( array_map( function ( $t ) {
			return preg_replace( '/\s*\(microdata\)$/', '', (string) $t );
		}, (array) $live['schema'] ) );
		foreach ( $ours as $t ) {
			if ( isset( $count[ $t ] ) ) {
				--$count[ $t ];
			}
		}
		$other = array_keys( array_filter( $count ) );
	}
	$plain = function ( $html ) {
		return trim( preg_replace( '/\s+/u', ' ', html_entity_decode( wp_strip_all_tags( preg_replace( '#<[^>]+>#', ' ', (string) $html ) ), ENT_QUOTES, 'UTF-8' ) ) );
	};
	$text  = $plain( $obj['content'] );
	// Description: the SEO meta, else the first paragraph (not the FAQ further down).
	$first = preg_match( '#<p\b[^>]*>(.*?)</p>#is', (string) $obj['content'], $fm ) ? $plain( $fm[1] ) : $text;
	$desc  = (string) ( $obj['seo']['meta_description'] ?? '' ) ?: mb_substr( $first, 0, 300 );
	$imgs  = array_values( array_filter( array_unique( array_merge( (array) ( $d['images_full'] ?? array() ), array_column( array_filter( (array) ( $d['images'] ?? array() ), function ( $i ) {
		return 'content' !== $i['where'];
	} ), 'src' ) ) ) ) );
	if ( ! $imgs ) {
		$imgs = array_slice( array_column( (array) ( $d['images'] ?? array() ), 'src' ), 0, 3 );
	}
	$title = html_entity_decode( (string) $obj['title'], ENT_QUOTES, 'UTF-8' );
	$org   = array_filter( array( '@type' => 'Organization', 'name' => (string) ( $site['name'] ?? '' ), 'url' => (string) ( $site['url'] ?? '' ), 'logo' => (string) ( $site['logo'] ?? '' ) ) );
	$cands = array();
	$why   = array();

	// Breadcrumbs for every page but the home page.
	if ( 'home' !== $nature ) {
		$items = array( array( 'name' => 'خانه', 'url' => (string) ( $site['url'] ?? home_url( '/' ) ) ) );
		foreach ( (array) ( $d['crumbs'] ?? array() ) as $c ) {
			$items[] = $c;
		}
		$items[] = array( 'name' => $title, 'url' => $page );
		$cands['BreadcrumbList'] = array(
			'@type'           => 'BreadcrumbList',
			'itemListElement' => array_map( function ( $c, $i ) {
				return array( '@type' => 'ListItem', 'position' => $i + 1, 'name' => $c['name'], 'item' => $c['url'] );
			}, $items, array_keys( $items ) ),
		);
		$why['BreadcrumbList'] = 'مسیر صفحه در نتایج گوگل به‌جای آدرس نمایش داده می‌شود.';
	}
	if ( 'product' === $nature && ! empty( $d['product'] ) ) {
		$p     = $d['product'];
		$code  = strtoupper( (string) ( $p['currency_code'] ?? '' ) );
		$price = (float) $p['price'];
		// Toman is not an ISO currency: Google needs Rial (×10).
		if ( 'IRT' === $code || ( '' === $code && false !== mb_strpos( (string) $p['currency'], 'تومان' ) ) ) {
			$price *= 10;
			$code   = 'IRR';
		}
		$avail = array( 'instock' => 'InStock', 'outofstock' => 'OutOfStock', 'onbackorder' => 'BackOrder' );
		$block = array(
			'@type'       => 'Product',
			'name'        => $title,
			'description' => mb_substr( $p['short'] ? $plain( $p['short'] ) : $desc, 0, 500 ),
			'image'       => array_slice( $imgs, 0, 6 ),
			'sku'         => (string) $p['sku'],
			'url'         => $page,
		);
		if ( ! empty( $p['brands'][0] ) ) {
			$block['brand'] = array( '@type' => 'Brand', 'name' => (string) $p['brands'][0] );
		}
		if ( ! empty( $p['categories'][0] ) ) {
			$block['category'] = (string) $p['categories'][0];
		}
		if ( $price > 0 ) {
			$block['offers'] = array(
				'@type'           => 'Offer',
				'url'             => $page,
				'price'           => (string) round( $price ),
				'priceCurrency'   => $code ?: 'IRR',
				'availability'    => 'https://schema.org/' . ( $avail[ (string) $p['stock'] ] ?? 'InStock' ),
				'itemCondition'   => 'https://schema.org/NewCondition',
				'priceValidUntil' => gmdate( 'Y-12-31', strtotime( '+1 year' ) ),
				'seller'          => $org ?: null,
			);
			$block['offers'] = array_filter( $block['offers'] );
		}
		if ( (int) $p['reviews'] > 0 && (float) $p['rating'] > 0 ) {
			$block['aggregateRating'] = array( '@type' => 'AggregateRating', 'ratingValue' => (string) round( (float) $p['rating'], 1 ), 'reviewCount' => (int) $p['reviews'], 'bestRating' => '5', 'worstRating' => '1' );
		}
		$props = array();
		foreach ( (array) $p['attributes'] as $a ) {
			if ( ! empty( $a['values'] ) ) {
				$props[] = array( '@type' => 'PropertyValue', 'name' => (string) $a['name'], 'value' => implode( '، ', (array) $a['values'] ) );
			}
		}
		if ( $props ) {
			$block['additionalProperty'] = array_slice( $props, 0, 20 );
		}
		$cands['Product'] = array_filter( $block, function ( $v ) {
			return '' !== $v && array() !== $v && null !== $v;
		} );
		$why['Product'] = 'قیمت، موجودی و امتیاز محصول در نتایج گوگل (و مرچنت سنتر) نمایش داده می‌شود.';
	}
	if ( 'article' === $nature ) {
		$cands['BlogPosting'] = array_filter( array(
			'@type'            => 'BlogPosting',
			'headline'         => mb_substr( $title, 0, 110 ),
			'description'      => mb_substr( $desc, 0, 300 ),
			'image'            => array_slice( $imgs, 0, 3 ),
			'datePublished'    => (string) ( $d['date'] ?? '' ),
			'dateModified'     => (string) ( $d['updated'] ?? '' ),
			'author'           => ! empty( $d['author'] ) ? array( '@type' => 'Person', 'name' => (string) $d['author'] ) : null,
			'publisher'        => $org ?: null,
			'mainEntityOfPage' => array( '@type' => 'WebPage', '@id' => $page ),
			'wordCount'        => count( preg_split( '/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY ) ),
		) );
		$why['BlogPosting'] = 'گوگل و موتورهای هوش مصنوعی تاریخ، نویسنده و موضوع مقاله را دقیق می‌فهمند (E-E-A-T).';
	}
	if ( 'category' === $nature ) {
		$prods = (array) ( $d['term']['products'] ?? array() );
		$block = array( '@type' => 'CollectionPage', 'name' => $title, 'url' => $page, 'description' => mb_substr( $desc, 0, 300 ) );
		if ( $prods ) {
			$block['mainEntity'] = array(
				'@type'           => 'ItemList',
				'numberOfItems'   => count( $prods ),
				'itemListElement' => array_map( function ( $x, $i ) {
					return array( '@type' => 'ListItem', 'position' => $i + 1, 'url' => $x['url'], 'name' => $x['title'] );
				}, $prods, array_keys( $prods ) ),
			);
		}
		$cands['CollectionPage'] = array_filter( $block );
		$why['CollectionPage']   = 'گوگل صفحه را فهرست محصولات/مطالب این دسته می‌شناسد.';
	}
	if ( 'home' === $nature ) {
		$cands['WebSite'] = array( '@type' => 'WebSite', 'name' => (string) ( $site['name'] ?? '' ), 'url' => (string) ( $site['url'] ?? $page ), 'potentialAction' => array( '@type' => 'SearchAction', 'target' => array( '@type' => 'EntryPoint', 'urlTemplate' => trailingslashit( (string) ( $site['url'] ?? $page ) ) . '?s={search_term_string}' ), 'query-input' => 'required name=search_term_string' ) );
		$why['WebSite']   = 'نام سایت در نتایج و جعبه جستجوی سایت.';
		if ( $org ) {
			$cands['Organization'] = $org;
			$why['Organization']   = 'هویت برند (لوگو، نام) برای گوگل و هوش مصنوعی.';
		}
	}
	$faq = sap_schema_faq_from_html( (string) $obj['content'] . ( 'product' === $nature ? (string) ( $d['product']['short'] ?? '' ) : '' ) );
	if ( count( $faq ) >= 2 ) {
		$cands['FAQPage'] = array(
			'@type'      => 'FAQPage',
			'mainEntity' => array_map( function ( $x ) {
				return array( '@type' => 'Question', 'name' => $x['q'], 'acceptedAnswer' => array( '@type' => 'Answer', 'text' => $x['a'] ) );
			}, $faq ),
		);
		$why['FAQPage'] = sprintf( '%s سوال و جواب از متن صفحه پیدا شد؛ برای فهم بهتر در جستجوی هوش مصنوعی (GEO). گوگل ریچ‌ریزالت FAQ را فقط برای سایت‌های دولتی و سلامت نشان می‌دهد.', sap_num( count( $faq ) ) );
	}
	$blocks = array();
	foreach ( $cands as $type => $b ) {
		$b      = array( '@context' => 'https://schema.org' ) + $b;
		$v      = sap_schema_validate( $b );
		$skip   = in_array( $type, $other, true ) || ( 'BlogPosting' === $type && array_intersect( array( 'Article', 'NewsArticle', 'BlogPosting' ), $other ) );
		$blocks[] = array(
			'type'     => $type,
			'json'     => $b,
			'errors'   => $v['errors'],
			'warnings' => $v['warnings'],
			'why'      => $why[ $type ] ?? '',
			'status'   => $skip ? 'exists' : ( $v['errors'] ? 'invalid' : ( in_array( $type, $ours, true ) ? 'update' : 'new' ) ),
		);
	}
	$apply = array_values( array_map( function ( $b ) {
		return $b['json'];
	}, array_filter( $blocks, function ( $b ) {
		return in_array( $b['status'], array( 'new', 'update' ), true );
	} ) ) );
	$natures = array( 'product' => 'محصول', 'article' => 'مقاله', 'category' => 'دسته', 'home' => 'صفحه اصلی', 'page' => 'برگه' );
	$name    = $title ?: sap_short_url( $page, 50 );
	$types   = array_column( array_filter( $blocks, function ( $b ) {
		return in_array( $b['status'], array( 'new', 'update' ), true );
	} ), 'type' );
	$preview = array(
		'type'     => 'schema',
		'kind'     => $obj['kind'],
		'id'       => $obj['id'],
		'url'      => $page,
		'changes'  => array( 'schema' => $apply ),
		'label'    => 'اسکیمای ' . implode( '، ', $types ) . ' برای «' . $name . '»',
		'detail'   => sprintf( "صفحه: %s\nنوع صفحه: %s\nاسکیما: %s%s", rawurldecode( $page ), $natures[ $nature ], implode( '، ', $types ), $other ? "\nاز قبل روی صفحه (افزونه‌های دیگر): " . implode( '، ', $other ) : '' ),
		'key'      => 'schema:' . md5( sap_norm_url( $page ) ),
		'done_msg' => 'اسکیما روی صفحه منتشر شد؛ با ابزار Rich Results گوگل هم می‌توانید بررسی کنید.',
	);
	return array(
		'token'   => $apply ? sap_auto_preview_put( $project['id'], $preview ) : '',
		'nature'  => $natures[ $nature ],
		'url'     => $page,
		'title'   => $name,
		'blocks'  => $blocks,
		'other'   => $other,
		'ours'    => $ours,
		'editor'  => (string) $obj['editor'],
		'test'    => 'https://search.google.com/test/rich-results?url=' . rawurlencode( $page ),
	);
}

/* ---------------------------------------------------------------------------
 * Smart image alt text
 * ------------------------------------------------------------------------- */

/**
 * Is an alt weak? (empty, a file name, too short, generic).
 */
function sap_alt_weak( $alt, $src = '' ) {
	$a = trim( (string) $alt );
	if ( mb_strlen( $a ) < 6 ) {
		return true;
	}
	$file = sap_norm_text( preg_replace( '/\.[a-z0-9]+$/i', '', rawurldecode( basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) ) );
	$norm = sap_norm_text( str_replace( array( '-', '_' ), ' ', $a ) );
	return $norm === sap_norm_text( str_replace( array( '-', '_' ), ' ', $file ) ) || (bool) preg_match( '/^(img|image|dsc|photo|pic|screenshot|untitled|تصویر|عکس)[\s_\-0-9]*$/iu', $a );
}

/**
 * Alt text for every image of a page: the AI writes a specific description
 * per image from the page facts (product, attributes, the heading the image
 * sits under, the file name); without AI a clean rule-based alt.
 *
 * @param bool $all Rewrite good alts too (default: only empty / weak ones).
 * @return array|WP_Error preview
 */
function sap_alt_plan( $project, $url, $all = false ) {
	$ok = sap_auto_ready( $project, 'alts' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$url = sap_full_url( $project, $url );
	$obj = sap_auto_obj( $project, $url );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	$d     = (array) ( $obj['details'] ?? array() );
	$title = html_entity_decode( (string) $obj['title'], ENT_QUOTES, 'UTF-8' );
	$kw    = (string) ( $obj['seo']['focus_keyword'] ?? '' );
	foreach ( sap_keymap( $project['id'] ) as $m ) {
		if ( '' === $kw && sap_norm_url( $m['url'] ) === sap_norm_url( $obj['url'] ?: $url ) ) {
			$kw = (string) $m['primary_kw'];
		}
	}
	// One entry per image file (the same file in content and gallery gets one alt).
	$imgs = array();
	foreach ( (array) ( $d['images'] ?? array() ) as $im ) {
		$k = strtolower( preg_replace( '/(-\d+x\d+|-scaled)(\.[a-z0-9]+)$/i', '$2', rawurldecode( basename( (string) wp_parse_url( $im['src'], PHP_URL_PATH ) ) ) ) );
		if ( '' === $k || isset( $imgs[ $k ] ) ) {
			continue;
		}
		$imgs[ $k ] = $im + array( 'file' => $k );
	}
	if ( ! $imgs ) {
		return new WP_Error( 'sap_alt_none', 'این صفحه تصویری ندارد.' );
	}
	// The heading each content image sits under.
	$html  = (string) $obj['content'];
	$parts = preg_split( '#(<h[2-4]\b[^>]*>.*?</h[2-4]>)#is', $html, -1, PREG_SPLIT_DELIM_CAPTURE );
	$under = array();
	$head  = '';
	foreach ( $parts as $p ) {
		if ( preg_match( '#^<h[2-4]#i', $p ) ) {
			$head = trim( wp_strip_all_tags( $p ) );
			continue;
		}
		if ( preg_match_all( '#<img\b[^>]*\ssrc=(["\'])(.*?)\1#i', $p, $m ) ) {
			foreach ( $m[2] as $src ) {
				$under[ strtolower( preg_replace( '/(-\d+x\d+|-scaled)(\.[a-z0-9]+)$/i', '$2', rawurldecode( basename( (string) wp_parse_url( $src, PHP_URL_PATH ) ) ) ) ) ] = $head;
			}
		}
	}
	$list = array();
	$n    = 0;
	foreach ( $imgs as $k => $im ) {
		$weak   = sap_alt_weak( $im['alt'], $im['src'] );
		$list[] = array(
			'i'       => $n++,
			'src'     => $im['src'],
			'where'   => $im['where'],
			'file'    => $k,
			'old'     => (string) $im['alt'],
			'weak'    => $weak,
			'heading' => $under[ $k ] ?? '',
			'change'  => $all || $weak,
		);
	}
	$todo = array_values( array_filter( $list, function ( $x ) {
		return $x['change'];
	} ) );
	if ( ! $todo ) {
		return new WP_Error( 'sap_alt_ok', 'همه تصاویر این صفحه متن جایگزین مناسب دارند. برای بازنویسی همه، گزینه «همه تصاویر» را بزنید.' );
	}
	$p     = (array) ( $d['product'] ?? array() );
	$attrs = array_map( function ( $a ) {
		return $a['name'] . ': ' . implode( '، ', (array) $a['values'] );
	}, (array) ( $p['attributes'] ?? array() ) );
	$new   = array();
	$src   = 'rule';
	if ( sap_ai_connected() ) {
		$schema = sap_ai_obj( array( 'items' => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'i' => array( 'type' => 'integer' ), 'alt' => array( 'type' => 'string' ) ) ) ) ) );
		$task   = implode( "\n", array(
			'برای هر تصویر این صفحه یک متن جایگزین (alt) فارسی حرفه‌ای بنویس.',
			'قواعد: توصیف دقیق همان تصویر (از نام فایل، محل تصویر، تیتری که زیرش است و مشخصات محصول بفهم: رنگ، زاویه، مدل، کاربرد). حداکثر ۱۲۵ کاراکتر. با «تصویر» یا «عکس» شروع نکن. هر alt منحصربه‌فرد باشد.',
			'کلمه کلیدی اصلی فقط در alt تصویر شاخص و حداکثر یک تصویر دیگر، به‌شکل طبیعی بیاید؛ بقیه توصیفی و متنوع باشند (پرهیز از تکرار کلمه کلیدی). اگر چیزی از تصویر معلوم نیست، چیزی از خودت نساز؛ توصیف کلی و درست بنویس.',
		) );
		$input  = array(
			'page'     => array( 'title' => $title, 'type' => (string) $obj['type'], 'keyword' => $kw, 'brand' => (string) ( $p['brands'][0] ?? '' ), 'category' => (string) ( $p['categories'][0] ?? '' ), 'attributes' => array_slice( $attrs, 0, 12 ) ),
			'images'   => array_map( function ( $x ) {
				return array( 'i' => $x['i'], 'where' => $x['where'], 'file' => $x['file'], 'heading' => $x['heading'], 'current_alt' => $x['old'] );
			}, $todo ),
		);
		$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'assist', 'max_tokens' => 1500, 'effort' => 'low' ) );
		if ( ! is_wp_error( $res ) ) {
			foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
				$a = trim( wp_strip_all_tags( (string) ( $it['alt'] ?? '' ) ) );
				if ( '' !== $a ) {
					$new[ (int) $it['i'] ] = mb_substr( $a, 0, 140 );
				}
			}
			$src = 'ai';
		}
	}
	$g = 0;
	foreach ( $list as $i => $x ) {
		if ( ! $x['change'] ) {
			continue;
		}
		if ( empty( $new[ $x['i'] ] ) ) {
			// Rule-based fallback: page title, the heading it illustrates, gallery order.
			$g          += 'gallery' === $x['where'] ? 1 : 0;
			$new[ $x['i'] ] = 'featured' === $x['where'] ? $title : ( 'gallery' === $x['where'] ? $title . ' - نمای ' . sap_num( $g + 1 ) : ( $x['heading'] ? $x['heading'] . ' - ' . $title : $title ) );
		}
		$list[ $i ]['new'] = $new[ $x['i'] ];
	}
	$name    = $title ?: sap_short_url( $url, 50 );
	$changes = array_values( array_map( function ( $x ) {
		return array( 'src' => $x['src'], 'alt' => $x['new'] );
	}, array_filter( $list, function ( $x ) {
		return $x['change'];
	} ) ) );
	$preview = array(
		'type'     => 'alts',
		'kind'     => $obj['kind'],
		'id'       => $obj['id'],
		'url'      => $obj['url'] ?: $url,
		'changes'  => array( 'alts' => $changes ),
		'label'    => sprintf( 'متن جایگزین %s تصویر «%s»', sap_num( count( $changes ) ), $name ),
		'detail'   => "صفحه: " . rawurldecode( $obj['url'] ?: $url ) . "\n" . implode( "\n", array_map( function ( $c ) {
			return '• ' . rawurldecode( basename( (string) wp_parse_url( $c['src'], PHP_URL_PATH ) ) ) . ' ← ' . $c['alt'];
		}, $changes ) ),
		'key'      => 'alts:' . md5( sap_norm_url( $obj['url'] ?: $url ) ),
		'done_msg' => 'متن جایگزین تصاویر روی سایت ثبت شد (هم در کتابخانه رسانه و هم داخل متن صفحه).',
	);
	return array( 'token' => sap_auto_preview_put( $project['id'], $preview ), 'title' => $name, 'url' => $obj['url'] ?: $url, 'images' => $list, 'source' => $src, 'keyword' => $kw, 'elementor' => 'elementor' === $obj['editor'] );
}

/**
 * Pages with images that need alt text, from the WordPress inventory.
 */
function sap_alt_queue( $project, $limit = 40 ) {
	$done = array();
	foreach ( sap_auto_log( $project['id'], 'alts', 600 ) as $e ) {
		if ( 'live' === $e['status'] ) {
			$done[ sap_norm_url( $e['url'] ) ] = true;
		}
	}
	$rows = array();
	foreach ( sap_site_inventory( $project['id'] ) as $k => $it ) {
		if ( $it['redirect'] || $it['noindex'] || $it['no_alt'] < 1 || isset( $done[ $k ] ) ) {
			continue;
		}
		$rows[] = array( 'url' => $it['url'], 'title' => $it['title'], 'type' => $it['ctype'], 'images' => $it['images'], 'no_alt' => $it['no_alt'], 'thumb' => $it['thumb'] );
	}
	usort( $rows, function ( $a, $b ) {
		return $b['no_alt'] <=> $a['no_alt'];
	} );
	return array( 'rows' => array_slice( $rows, 0, $limit ), 'total' => count( $rows ) );
}

/* ---------------------------------------------------------------------------
 * WooCommerce attributes per category
 * ------------------------------------------------------------------------- */

/**
 * Global attributes and product categories of the site (cached 15 minutes).
 */
function sap_attr_site( $project, $fresh = false ) {
	$key = 'sap_attr_site_' . $project['id'];
	$hit = $fresh ? false : get_transient( $key );
	if ( is_array( $hit ) ) {
		return $hit;
	}
	$res = sap_site_request( $project, 'GET', 'attributes' );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	set_transient( $key, $res, 15 * MINUTE_IN_SECONDS );
	return $res;
}

/**
 * Attribute sets per product category: cat_id => {name, attrs: [{name, slug, values[], filter}], synced}.
 */
function sap_attr_sets( $project ) {
	return (array) ( $project['options']['attr_sets'] ?? array() );
}

/**
 * Clean and save a category set (and create it in WooCommerce when asked).
 */
function sap_attr_set_save( $project, $cat_id, $cat_name, $attrs, $sync = false ) {
	$clean = array();
	foreach ( (array) $attrs as $a ) {
		$name = trim( sanitize_text_field( (string) ( $a['name'] ?? '' ) ) );
		if ( '' === $name ) {
			continue;
		}
		$vals = is_array( $a['values'] ?? null ) ? $a['values'] : preg_split( '/[\n،,]+/u', (string) ( $a['values'] ?? '' ) );
		$vals = array_values( array_unique( array_filter( array_map( function ( $v ) {
			return trim( sanitize_text_field( (string) $v ) );
		}, $vals ) ) ) );
		$slug    = sanitize_title( (string) ( $a['slug'] ?? '' ) );
		$clean[] = array( 'name' => $name, 'slug' => preg_match( '/^[a-z0-9_-]+$/', $slug ) ? substr( $slug, 0, 27 ) : '', 'values' => array_slice( $vals, 0, 80 ), 'filter' => ! empty( $a['filter'] ) );
	}
	$sets = sap_attr_sets( $project );
	if ( ! $clean ) {
		unset( $sets[ $cat_id ] );
		sap_project_set_option( $project['id'], 'attr_sets', $sets );
		return array( 'msg' => 'مجموعه ویژگی این دسته حذف شد.' );
	}
	$sets[ $cat_id ] = array( 'name' => $cat_name, 'attrs' => $clean, 'synced' => $sets[ $cat_id ]['synced'] ?? '', 'at' => sap_now() );
	$msg             = 'مجموعه ویژگی‌های «' . $cat_name . '» ذخیره شد.';
	if ( $sync ) {
		$ok = sap_auto_ready( $project, 'attributes' );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$res = sap_site_request( $project, 'POST', 'attributes', array( 'attributes' => $clean ), 60 );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$sets[ $cat_id ]['synced'] = sap_now();
		delete_transient( 'sap_attr_site_' . $project['id'] );
		sap_activity_add( $project['id'], array( 'category' => 'content', 'action' => 'attr_set', 'title' => 'ثبت ' . sap_num( count( $clean ) ) . ' ویژگی سراسری دسته «' . $cat_name . '» در ووکامرس', 'source' => 'tool', 'ref' => 'attrset:' . $cat_id . ':' . time() ) );
		$msg = 'ویژگی‌های «' . $cat_name . '» به‌صورت ویژگی سراسری در ووکامرس ساخته شد؛ حالا در هر محصول فقط مقدار انتخاب می‌شود و فیلترهای فروشگاه کار می‌کنند.';
	}
	sap_project_set_option( $project['id'], 'attr_sets', $sets );
	return array( 'msg' => $msg );
}

/**
 * AI suggestion of a category's attribute set (reusing the site's global attributes).
 */
function sap_attr_set_suggest( $project, $cat_id ) {
	$site = sap_attr_site( $project );
	if ( is_wp_error( $site ) ) {
		return $site;
	}
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی در تنظیمات وارد نشده است.' );
	}
	$cat = null;
	foreach ( (array) $site['categories'] as $c ) {
		if ( (int) $c['id'] === (int) $cat_id ) {
			$cat = $c;
		}
	}
	if ( ! $cat ) {
		return new WP_Error( 'sap_cat', 'دسته پیدا نشد.' );
	}
	$titles = array();
	foreach ( sap_site_inventory( $project['id'] ) as $it ) {
		if ( 'product' === $it['ctype'] && in_array( $cat['name'], (array) ( $it['product']['cats'] ?? array() ), true ) && count( $titles ) < 25 ) {
			$titles[] = $it['title'];
		}
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array( 'attrs' => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'name' => $str, 'slug' => array( 'type' => 'string', 'description' => 'نامک لاتین کوتاه مثل color یا battery-range' ), 'values' => array( 'type' => 'array', 'items' => $str ), 'filter' => array( 'type' => 'boolean', 'description' => 'برای فیلتر فروشگاه مفید است؟' ), 'why' => $str ) ) ) ) );
	$task   = implode( "\n", array(
		'تو متخصص سئو و تجربه کاربری فروشگاه ووکامرس هستی. برای دسته «' . $cat['name'] . '» مجموعه ویژگی‌های ثابت (۴ تا ۹ ویژگی) پیشنهاد بده که در همه محصولات این دسته پر شود و برای فیلتر فروشگاه و جدول مشخصات مفید باشد.',
		'اگر ویژگی سراسری هم‌معنی از قبل در سایت هست، دقیقاً همان نام و نامک را استفاده کن (ویژگی تکراری نساز). مقادیر را استاندارد و یکدست بنویس (واحد یکسان، بدون تکرار، مثل «۳۵۰ وات»). نامک لاتین کوتاه بده.',
	) );
	$input  = array( 'category' => $cat['name'], 'sample_products' => $titles, 'existing_global_attributes' => array_map( function ( $a ) {
		return array( 'name' => $a['name'], 'slug' => $a['slug'], 'values' => array_slice( (array) $a['terms'], 0, 30 ) );
	}, (array) $site['attributes'] ) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'assist', 'max_tokens' => 2000, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array( 'attrs' => array_slice( (array) ( $res['attrs'] ?? array() ), 0, 12 ) );
}

/**
 * Extract the values of a category set from a product description (only
 * allowed values; a value missing from the set is offered as "new").
 *
 * @return array|WP_Error preview
 */
function sap_attr_extract( $project, $url, $cat_id, $description = '' ) {
	$ok = sap_auto_ready( $project, 'attributes' );
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	$sets = sap_attr_sets( $project );
	$set  = $sets[ $cat_id ] ?? null;
	if ( ! $set ) {
		return new WP_Error( 'sap_attr_set', 'اول مجموعه ویژگی این دسته را تعریف و در ووکامرس ثبت کنید.' );
	}
	$url = sap_full_url( $project, $url );
	$obj = sap_auto_obj( $project, $url );
	if ( is_wp_error( $obj ) ) {
		return $obj;
	}
	if ( 'product' !== $obj['type'] ) {
		return new WP_Error( 'sap_attr', 'این صفحه محصول ووکامرس نیست.' );
	}
	$p       = (array) ( $obj['details']['product'] ?? array() );
	$current = array();
	foreach ( (array) ( $p['attributes'] ?? array() ) as $a ) {
		$current[ sap_norm_text( $a['name'] ) ] = (array) $a['values'];
	}
	$text = trim( (string) $description );
	if ( '' === $text ) {
		$text = trim( wp_strip_all_tags( (string) ( $p['short'] ?? '' ) . "\n" . (string) $obj['content'] ) );
	}
	$text  = mb_substr( preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ), 0, 6000 );
	$found = array();
	$src   = 'rule';
	if ( '' !== $text && sap_ai_connected() ) {
		$str    = array( 'type' => 'string' );
		$schema = sap_ai_obj( array( 'items' => array( 'type' => 'array', 'items' => sap_ai_obj( array( 'name' => $str, 'values' => array( 'type' => 'array', 'items' => $str ), 'new_values' => array( 'type' => 'array', 'items' => $str, 'description' => 'فقط اگر مقدار درست در لیست مجاز نیست' ), 'evidence' => array( 'type' => 'string', 'description' => 'عبارت متن که مقدار از آن فهمیده شد' ) ) ) ) ) );
		$task   = implode( "\n", array(
			'از توضیحات این محصول، مقدار ویژگی‌های تعریف‌شده را استخراج کن.',
			'برای هر ویژگی فقط از values مجاز همان ویژگی انتخاب کن (عین متن مقدار مجاز). اگر مقدار درست در لیست نیست، آن را استاندارد و هم‌شکل با بقیه در new_values بگذار. اگر متن چیزی درباره ویژگی نمی‌گوید، values خالی بگذار؛ حدس نزن.',
		) );
		$input  = array( 'product' => html_entity_decode( (string) $obj['title'], ENT_QUOTES, 'UTF-8' ), 'description' => $text, 'attributes' => array_map( function ( $a ) {
			return array( 'name' => $a['name'], 'values' => $a['values'] );
		}, $set['attrs'] ) );
		$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'assist', 'max_tokens' => 1500, 'effort' => 'low' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		foreach ( (array) ( $res['items'] ?? array() ) as $it ) {
			$found[ sap_norm_text( (string) ( $it['name'] ?? '' ) ) ] = $it;
		}
		$src = 'ai';
	}
	$rows = array();
	foreach ( $set['attrs'] as $a ) {
		$k       = sap_norm_text( $a['name'] );
		$allowed = array();
		foreach ( $a['values'] as $v ) {
			$allowed[ sap_norm_text( $v ) ] = $v;
		}
		$pick = array();
		$new  = array();
		foreach ( (array) ( $found[ $k ]['values'] ?? array() ) as $v ) {
			if ( isset( $allowed[ sap_norm_text( $v ) ] ) ) {
				$pick[] = $allowed[ sap_norm_text( $v ) ];
			} else {
				$new[] = trim( sanitize_text_field( (string) $v ) );
			}
		}
		foreach ( (array) ( $found[ $k ]['new_values'] ?? array() ) as $v ) {
			$v = trim( sanitize_text_field( (string) $v ) );
			if ( '' !== $v && ! isset( $allowed[ sap_norm_text( $v ) ] ) ) {
				$new[] = $v;
			}
		}
		if ( 'rule' === $src ) {
			// Without AI: allowed values written as-is in the description.
			foreach ( $a['values'] as $v ) {
				if ( '' !== $text && false !== mb_strpos( sap_norm_text( $text ), sap_norm_text( $v ) ) ) {
					$pick[] = $v;
				}
			}
		}
		$rows[] = array( 'name' => $a['name'], 'slug' => $a['slug'], 'allowed' => $a['values'], 'pick' => array_values( array_unique( $pick ) ), 'new' => array_values( array_unique( array_filter( $new ) ) ), 'current' => $current[ $k ] ?? array(), 'evidence' => (string) ( $found[ $k ]['evidence'] ?? '' ) );
	}
	$name    = html_entity_decode( (string) $obj['title'], ENT_QUOTES, 'UTF-8' );
	$preview = array(
		'type'     => 'attributes',
		'kind'     => $obj['kind'],
		'id'       => $obj['id'],
		'url'      => $obj['url'] ?: $url,
		'changes'  => array( 'attributes' => array_values( array_filter( array_map( function ( $r ) {
			return $r['pick'] ? array( 'name' => $r['name'], 'slug' => $r['slug'], 'values' => $r['pick'] ) : null;
		}, $rows ) ) ) ),
		'label'    => 'ثبت ویژگی‌های محصول «' . $name . '»',
		'detail'   => 'محصول: ' . rawurldecode( $obj['url'] ?: $url ),
		'key'      => 'attr:' . md5( sap_norm_url( $obj['url'] ?: $url ) ),
		'cat'      => $cat_id,
		'done_msg' => 'ویژگی‌ها به‌صورت ویژگی سراسری روی محصول ثبت شد (قابل فیلتر در فروشگاه).',
	);
	return array( 'token' => sap_auto_preview_put( $project['id'], $preview ), 'title' => $name, 'url' => $obj['url'] ?: $url, 'rows' => $rows, 'source' => $src, 'text_used' => mb_substr( $text, 0, 300 ) );
}

/**
 * Turn the edited attribute picks of the preview into the change (and the
 * log detail), allowing only values of the set or confirmed new ones.
 */
function sap_attr_edits( $project, $token, $picks ) {
	$pv = sap_auto_preview_get( $project['id'], $token );
	if ( ! $pv || 'attributes' !== $pv['type'] ) {
		return array();
	}
	$set   = sap_attr_sets( $project )[ $pv['cat'] ] ?? array( 'attrs' => array() );
	$attrs = array();
	$lines = array();
	foreach ( $set['attrs'] as $a ) {
		$vals = array_values( array_unique( array_filter( array_map( function ( $v ) {
			return trim( sanitize_text_field( (string) $v ) );
		}, (array) ( $picks[ $a['name'] ] ?? array() ) ) ) ) );
		$attrs[] = array( 'name' => $a['name'], 'slug' => $a['slug'], 'values' => $vals );
		if ( $vals ) {
			$lines[] = '• ' . $a['name'] . ': ' . implode( '، ', $vals );
		}
	}
	// Empty values leave the attribute untouched rather than clearing it.
	$attrs = array_values( array_filter( $attrs, function ( $a ) {
		return (bool) $a['values'];
	} ) );
	$pv['detail'] .= "\n" . implode( "\n", $lines );
	set_transient( 'sap_auto_prev_' . $project['id'] . '_' . sanitize_key( $token ), $pv, DAY_IN_SECONDS );
	return array( 'attributes' => $attrs );
}

/**
 * Products of a category from the inventory (with their attribute count).
 */
function sap_attr_products( $project, $cat_name, $limit = 300 ) {
	$rows = array();
	foreach ( sap_site_inventory( $project['id'] ) as $it ) {
		if ( 'product' === $it['ctype'] && ! $it['redirect'] && in_array( $cat_name, (array) ( $it['product']['cats'] ?? array() ), true ) ) {
			$rows[] = array( 'url' => $it['url'], 'title' => $it['title'], 'attrs' => (int) ( $it['product']['attrs'] ?? 0 ), 'thumb' => $it['thumb'], 'short' => (int) ( $it['product']['short'] ?? 0 ) );
		}
	}
	usort( $rows, function ( $a, $b ) {
		return $a['attrs'] <=> $b['attrs'];
	} );
	return array_slice( $rows, 0, $limit );
}
