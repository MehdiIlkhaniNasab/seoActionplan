<?php
/**
 * Judgement layer: what is really a problem, and what was already handled.
 *
 *  - URL value: a filter / sort URL (?stock_status=…), a paginated page, a
 *    cart or checkout page, a URL canonicalised to another one or set to
 *    noindex is not a page to optimise; its missing H1 or its absence from
 *    the sitemap is not an SEO issue. Pages with traffic and money pages are
 *    important; pages without impressions are minor.
 *  - Already handled: a suggestion for a page whose title / meta / content was
 *    changed recently (task done or planned, a content update, a change on the
 *    site) is not made again; the AI gets that history too.
 *  - AI triage: an expert review of the open technical findings with the
 *    crawl facts — real / minor / not an issue, with the reason. Minor items
 *    stay visible but weigh little; non-issues are dismissed with the reason.
 *  - Resolved by you: «حل شد» on a finding keeps it resolved until the data
 *    behind it is refreshed (30 days), instead of reopening on the next run.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/* ---------------------------------------------------------------------------
 * URL value
 * ------------------------------------------------------------------------- */

/**
 * Indexes used by the URL judgements (cached per request).
 */
function sap_url_ctx( $project_id ) {
	static $cache = array();
	if ( isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	$ctx = array( 'crawl' => array(), 'impr' => array() );
	foreach ( (array) ( sap_dataset_payload( $project_id, 'crawl' )['rows'] ?? array() ) as $r ) {
		$ctx['crawl'][ sap_norm_url( $r['url'] ) ] = $r;
	}
	foreach ( (array) ( sap_dataset_payload( $project_id, 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$ctx['impr'][ sap_norm_url( $r['page'] ) ] = (int) $r['impressions'];
	}
	$cache[ $project_id ] = $ctx;
	return $ctx;
}

/**
 * Does the URL carry a query string that is not just tracking?
 */
function sap_url_has_params( $url ) {
	$q = (string) wp_parse_url( (string) $url, PHP_URL_QUERY );
	if ( '' === $q ) {
		return false;
	}
	parse_str( $q, $args );
	foreach ( array_keys( $args ) as $k ) {
		if ( ! preg_match( '/^(utm_|gclid|fbclid|_ga|srsltid)/i', (string) $k ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Why a URL is not a page to optimise ('' when it is one).
 */
function sap_url_skip_reason( $project_id, $url, $title = '' ) {
	$url  = (string) $url;
	$path = rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) );
	if ( sap_url_has_params( $url ) ) {
		return 'آدرس پارامتردار (فیلتر، مرتب‌سازی یا جستجو)؛ نسخه اصلی صفحه ملاک است';
	}
	if ( preg_match( '#/page/\d+/?$#', $path ) ) {
		return 'صفحه‌بندی (صفحه ۲ به بعد)';
	}
	if ( preg_match( '#/(feed|attachment|wp-json|trackback|embed)/#', $path ) ) {
		return 'آدرس سیستمی وردپرس';
	}
	$ctx = sap_url_ctx( $project_id );
	$k   = sap_norm_url( $url );
	$c   = $ctx['crawl'][ $k ] ?? null;
	$it  = function_exists( 'sap_site_item' ) ? sap_site_item( $project_id, $url ) : null;
	// Utility pages are WordPress pages (an article titled «درباره …» is not one).
	if ( ( $it ? 'page' === $it['ctype'] && sap_is_utility_page( $it['title'], $url ) : sap_is_utility_page( '', $url ) ) ) {
		return 'صفحه کاربردی (سبد خرید، تسویه، حساب کاربری، قوانین…)';
	}
	if ( $c && '' !== (string) $c['canon'] && sap_norm_url( $c['canon'] ) !== $k ) {
		return 'به صفحه دیگری کنونیکال شده (' . sap_short_url( $c['canon'], 50 ) . ')';
	}
	if ( ( $c && false !== strpos( (string) $c['robots'], 'noindex' ) ) || ! empty( $it['noindex'] ) ) {
		return 'عمداً noindex است';
	}
	$m = sap_keymap( $project_id )[ $k ] ?? null;
	if ( $m && in_array( $m['status'], array( 'none', 'noindex', 'prune_410', 'prune_301' ), true ) ) {
		return 'در نقشه کلمات «' . ( sap_keymap_statuses()[ $m['status'] ] ?? $m['status'] ) . '»';
	}
	return sap_url_dead_reason( $project_id, $url );
}

/**
 * Not a live page any more (redirected / removed), from the crawl, the WordPress
 * list and the redirect manager. Search Console keeps such URLs for weeks; their
 * data belongs to the target page, so they are left out of page analysis.
 */
function sap_url_dead_reason( $project_id, $url ) {
	$k  = sap_norm_url( $url );
	$c  = sap_url_ctx( $project_id )['crawl'][ $k ] ?? null;
	$it = function_exists( 'sap_site_item' ) ? sap_site_item( $project_id, $url ) : null;
	if ( $c && $c['code'] >= 300 && $c['code'] < 400 ) {
		return 'ریدایرکت می‌شود' . ( '' !== (string) $c['redirect'] ? ' به ' . sap_short_url( $c['redirect'], 50 ) : '' );
	}
	if ( $c && $c['code'] >= 400 ) {
		return 'صفحه وجود ندارد (کد ' . $c['code'] . ')';
	}
	if ( $it && '' !== (string) ( $it['redirect'] ?? '' ) ) {
		return 'در وردپرس ریدایرکت شده به ' . sap_short_url( $it['redirect'], 50 );
	}
	if ( ! $c || 200 !== (int) $c['code'] ) {
		$st = function_exists( 'sap_url_crawl_state' ) ? sap_url_crawl_state( $project_id, $url ) : '';
		if ( 'redirect' === $st ) {
			return 'در افزونه ریدایرکت (رنک‌مث/Redirection) ریدایرکت شده';
		}
		if ( 'gone' === $st ) {
			return 'حذف شده (۴۰۴/۴۱۰)';
		}
		// The WordPress list has every post, page, product and term: an address under one of
		// their bases (/product/…, /product-category/…, root slugs…) that it lacks is gone.
		// Other bases (custom post types, archives) are not judged.
		$path = trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
		if ( ! $it && '' !== $path && function_exists( 'sap_site_inventory' ) ) {
			$bases = sap_inventory_bases( $project_id );
			$seg   = explode( '/', $path );
			$base  = count( $seg ) > 1 ? $seg[0] : '';
			if ( ( $bases[ $base ] ?? 0 ) >= 5 ) {
				return 'در فهرست محتوای وردپرس نیست (حذف یا ریدایرکت شده)';
			}
		}
	}
	return '';
}

/**
 * First path segment of WordPress items => count ('' = items at the root).
 */
function sap_inventory_bases( $project_id ) {
	static $cache = array();
	if ( ! isset( $cache[ $project_id ] ) ) {
		$b = array();
		foreach ( sap_site_inventory( $project_id ) as $it ) {
			$seg = explode( '/', trim( rawurldecode( (string) wp_parse_url( (string) ( $it['url'] ?? '' ), PHP_URL_PATH ) ), '/' ) );
			if ( '' === $seg[0] ) {
				continue;
			}
			$k       = count( $seg ) > 1 ? $seg[0] : '';
			$b[ $k ] = ( $b[ $k ] ?? 0 ) + 1;
		}
		$cache[ $project_id ] = $b;
	}
	return $cache[ $project_id ];
}

/**
 * The live address for a URL: itself, or where it redirects when that page is alive.
 */
function sap_url_live( $project_id, $url ) {
	if ( '' === sap_url_dead_reason( $project_id, $url ) ) {
		return $url;
	}
	$c  = sap_url_ctx( $project_id )['crawl'][ sap_norm_url( $url ) ] ?? null;
	$it = function_exists( 'sap_site_item' ) ? sap_site_item( $project_id, $url ) : null;
	$to = $c && '' !== (string) $c['redirect'] ? (string) $c['redirect'] : (string) ( $it['redirect'] ?? '' );
	for ( $i = 0; $i < 5 && '' !== $to; $i++ ) {
		if ( '' === sap_url_dead_reason( $project_id, $to ) ) {
			return $to;
		}
		$n  = sap_url_ctx( $project_id )['crawl'][ sap_norm_url( $to ) ] ?? null;
		$to = $n ? (string) $n['redirect'] : '';
	}
	return '';
}

/**
 * How much a page matters: high (traffic or money page), normal, low.
 */
function sap_url_importance( $project_id, $url ) {
	$ctx  = sap_url_ctx( $project_id );
	$k    = sap_norm_url( $url );
	$impr = (int) ( $ctx['impr'][ $k ] ?? 0 );
	$it   = function_exists( 'sap_site_item' ) ? sap_site_item( $project_id, $url ) : null;
	$m    = sap_keymap( $project_id )[ $k ] ?? null;
	if ( $impr >= 100 || ( $it && in_array( $it['ctype'], array( 'product_cat', 'brand' ), true ) ) || ( $m && $m['focus_id'] ) || '' === trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) {
		return 'high';
	}
	if ( $impr > 0 || ( $m && '' !== $m['core'] ) ) {
		return 'normal';
	}
	return 'low';
}

/* ---------------------------------------------------------------------------
 * Already handled
 * ------------------------------------------------------------------------- */

/**
 * Work area of a proposal kind.
 */
function sap_kind_context( $kind ) {
	$map = array( 'title_meta' => 'onpage', 'schema' => 'onpage', 'images' => 'onpage', 'content' => 'content_update', 'faq' => 'content_update', 'attributes' => 'content_update', 'merge' => 'content_update', 'links' => 'internal_links', 'cta' => 'cro', 'noindex' => 'technical', 'keyword_fit' => 'research' );
	return $map[ $kind ] ?? '';
}

/**
 * What was done / planned recently on each page: url key => list of {area, what, date}.
 */
function sap_recent_work( $project_id, $days = 75 ) {
	static $cache = array();
	if ( isset( $cache[ $project_id ] ) ) {
		return $cache[ $project_id ];
	}
	global $wpdb;
	$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
	$out   = array();
	$add   = function ( $url, $area, $what, $date ) use ( &$out ) {
		if ( '' !== (string) $url ) {
			$out[ sap_norm_url( $url ) ][] = array( 'area' => $area, 'what' => $what, 'date' => substr( (string) $date, 0, 10 ) );
		}
	};
	foreach ( sap_tasks( array( 'project_id' => $project_id ) ) as $t ) {
		if ( 'dropped' === $t['status'] || '' === (string) $t['url'] ) {
			continue;
		}
		$date = 'done' === $t['status'] ? $t['done_at'] : ( $t['updated_at'] ?? $t['created_at'] ?? '' );
		if ( (string) $date >= $since ) {
			$add( $t['url'], $t['context'], ( 'done' === $t['status'] ? 'انجام شد: ' : 'در برنامه: ' ) . $t['title'], $date );
		}
	}
	foreach ( sap_updates( $project_id ) as $u ) {
		if ( 'cancelled' !== $u['status'] && (string) ( $u['done_at'] ?: $u['created_at'] ) >= $since ) {
			$add( $u['url'], 'content_update', 'آپدیت محتوا (' . ( sap_update_statuses()[ $u['status'] ] ?? $u['status'] ) . ')', $u['done_at'] ?: $u['created_at'] );
		}
	}
	$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT category, title, url, happened_at FROM ' . sap_table( 'activity' ) . " WHERE project_id = %d AND happened_at >= %s AND url <> ''", $project_id, $since ) ); // phpcs:ignore
	foreach ( (array) $rows as $r ) {
		$add( $r->url, $r->category, 'تغییر روی سایت: ' . $r->title, $r->happened_at );
	}
	// Proposals you approved or dismissed (whatever the analysis that made them).
	foreach ( array_merge( sap_findings( $project_id, array( 'status' => 'planned', 'limit' => 2000 ) ), sap_findings( $project_id, array( 'status' => 'dismissed', 'limit' => 2000 ) ) ) as $f ) {
		if ( '' !== (string) $f['url'] && (string) $f['last_seen'] >= $since ) {
			$add( $f['url'], $f['context'], ( 'planned' === $f['status'] ? 'پیشنهاد تأییدشده: ' : 'پیشنهاد ردشده: ' ) . $f['title'], $f['last_seen'] );
		}
	}
	$cache[ $project_id ] = $out;
	return $out;
}

/**
 * Was this kind of work done / planned on the page recently? Returns the reason.
 */
function sap_recently_handled( $project_id, $url, $kind, $context = '' ) {
	$area = sap_kind_context( $kind ) ?: $context;
	foreach ( sap_recent_work( $project_id )[ sap_norm_url( $url ) ] ?? array() as $w ) {
		$same = $w['area'] === $area
			|| ( 'title_meta' === $kind && preg_match( '/تایتل|متا|عنوان|title|meta/u', $w['what'] ) )
			|| ( in_array( $kind, array( 'content', 'faq', 'attributes' ), true ) && preg_match( '/محتوا|بازنویسی|ویژگی|content_words/u', $w['what'] ) );
		if ( $same ) {
			return $w['what'] . ' (' . sap_jdate( $w['date'], 'short' ) . ')';
		}
	}
	return '';
}

/* ---------------------------------------------------------------------------
 * Resolved by you
 * ------------------------------------------------------------------------- */

/**
 * Fingerprints you marked as resolved: fp => time.
 */
function sap_user_resolved( $project_id ) {
	return (array) get_option( 'sap_user_resolved_' . $project_id, array() );
}

function sap_finding_mark_resolved( $finding ) {
	global $wpdb;
	$map                           = sap_user_resolved( $finding['project_id'] );
	$map[ $finding['fingerprint'] ] = time();
	update_option( 'sap_user_resolved_' . $finding['project_id'], array_slice( $map, -500, null, true ), false );
	$wpdb->update( sap_table( 'findings' ), array( 'status' => 'resolved' ), array( 'id' => (int) $finding['id'] ) );
}

/* ---------------------------------------------------------------------------
 * AI triage of technical findings
 * ------------------------------------------------------------------------- */

/**
 * Stored triage decisions: fingerprint => {verdict, priority, reason, at}.
 */
function sap_triage( $project_id ) {
	return (array) get_option( 'sap_triage_' . $project_id, array() );
}

/**
 * Apply triage to a finding before it is stored (minor ones weigh little).
 */
add_filter( 'sap_finding_pre_sync', function ( $f, $project_id ) {
	$fp = substr( hash( 'sha256', $f['detector'] . '|' . $f['key'] ), 0, 40 );
	$t  = sap_triage( $project_id )[ $fp ] ?? null;
	if ( ! $t ) {
		return $f;
	}
	$f['evidence']           = (array) ( $f['evidence'] ?? array() );
	$f['evidence']['triage'] = $t['reason'];
	$f['evidence']['tier']   = 'minor' === $t['verdict'] ? 'minor' : ( 'high' === $t['priority'] ? 'critical' : 'important' );
	if ( 'minor' === $t['verdict'] ) {
		$f['severity'] = min( (float) ( $f['severity'] ?? 20 ), 12 );
		$f['impact']   = 0;
	} elseif ( 'high' === $t['priority'] ) {
		$f['severity'] = max( (float) ( $f['severity'] ?? 50 ), 70 );
	}
	return $f;
}, 10, 2 );

/**
 * Sync step: AI review of the open technical findings.
 */
function sap_step_ai_triage( $project, $force = false ) {
	if ( ! sap_ai_connected() ) {
		return sap_skip( 'کلید هوش مصنوعی وارد نشده.' );
	}
	$pid  = $project['id'];
	$tech = array_values( array_filter( sap_findings( $pid, array( 'status' => 'open', 'limit' => 1000 ) ), function ( $f ) {
		return (bool) sap_tech_section_of( $f );
	} ) );
	$done = sap_triage( $pid );
	if ( ! $force ) {
		$tech = array_values( array_filter( $tech, function ( $f ) use ( $done ) {
			return ! isset( $done[ $f['fingerprint'] ] );
		} ) );
	}
	if ( ! $tech ) {
		return sap_skip( 'مشکل تکنیکال تازه‌ای برای بازبینی نیست.' );
	}
	$tech  = array_slice( $tech, 0, 30 );
	$items = array();
	foreach ( $tech as $f ) {
		$ev     = (array) $f['evidence'];
		$sample = array();
		$its    = (array) ( $ev['items'] ?? array() );
		if ( ! $its && '' !== (string) $f['url'] ) {
			$its = array( array( 'url' => $f['url'] ) );
		}
		foreach ( array_slice( $its, 0, 6 ) as $it ) {
			$u        = (string) ( $it['url'] ?? '' );
			$sample[] = array_filter( array(
				'url'        => $u ? sap_short_url( $u, 80 ) : '',
				'page'       => (string) ( $it['page'] ?? $it['title'] ?? '' ),
				'impr'       => $it['impr'] ?? null,
				'importance' => $u ? sap_url_importance( $pid, $u ) : '',
				'not_a_page' => $u ? sap_url_skip_reason( $pid, $u ) : '',
			), function ( $v ) {
				return null !== $v && '' !== $v;
			} );
		}
		$items[] = array( 'id' => (int) $f['id'], 'section' => sap_tech_section_of( $f ), 'title' => $f['title'], 'detail' => mb_substr( (string) $f['detail'], 0, 400 ), 'affected' => count( (array) ( $ev['items'] ?? array() ) ), 'sample' => $sample );
	}
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array( 'items' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
		'id'       => array( 'type' => 'integer' ),
		'verdict'  => array( 'type' => 'string', 'enum' => array( 'real', 'minor', 'not_issue' ) ),
		'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
		'reason'   => array( 'type' => 'string', 'description' => 'یک جمله فارسی: چرا این مشکل واقعی/جزئی/غیرمشکل است، با استناد به صفحات نمونه' ),
	) ) ) ) );
	$task = implode( "\n", array(
		'تو متخصص ارشد سئو تکنیکال هستی و باید گزارش ابزار را قبل از رفتن به گزارش کارفرما بازبینی کنی. ابزارها معمولاً مشکل را بزرگ‌نمایی می‌کنند؛ تو سخت‌گیر ولی واقع‌بین باش.',
		'برای هر مورد verdict بده:',
		'- real: واقعاً روی ایندکس، رتبه یا تجربه کاربر صفحات مهم اثر دارد.',
		'- minor: درست است ولی اثر سئویی کمی دارد (مثلاً فقط صفحات بدون نمایش، تایتل کمی طولانی، متا خالی در صفحه کم‌اهمیت).',
		'- not_issue: از نظر سئو مشکل نیست؛ مثل صفحات پارامتردار/فیلتر که به صفحه اصلی کنونیکال شده‌اند، صفحه‌بندی، سبد خرید و پیش‌فاکتور و حساب کاربری، صفحات noindex عمدی، یا نبودن چنین صفحاتی در سایت‌مپ.',
		'not_a_page در نمونه‌ها یعنی ابزار تشخیص داده آن آدرس صفحه قابل بهینه‌سازی نیست. importance اهمیت صفحه است (high: ترافیک یا صفحه پولساز).',
		'priority را برای real بر اساس اثر روی صفحات مهم بده. reason را کوتاه و قابل فهم برای کارفرما بنویس.',
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( array( 'site' => sap_project_domain( $project ), 'cms' => 'WordPress + WooCommerce', 'findings' => $items ), JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'light', 'max_tokens' => 3000, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$by_id = array();
	foreach ( $tech as $f ) {
		$by_id[ (int) $f['id'] ] = $f;
	}
	$n = array( 'real' => 0, 'minor' => 0, 'not_issue' => 0 );
	foreach ( (array) ( $res['items'] ?? array() ) as $r ) {
		$f = $by_id[ (int) ( $r['id'] ?? 0 ) ] ?? null;
		$v = $r['verdict'] ?? '';
		if ( ! $f || ! isset( $n[ $v ] ) ) {
			continue;
		}
		++$n[ $v ];
		$reason = (string) ( $r['reason'] ?? '' );
		$done[ $f['fingerprint'] ] = array( 'verdict' => $v, 'priority' => (string) ( $r['priority'] ?? 'medium' ), 'reason' => $reason, 'at' => sap_now() );
		if ( 'not_issue' === $v ) {
			sap_finding_set_status( $f['id'], 'dismissed' );
			sap_comment_add( $pid, 'finding', $f['id'], 'بازبینی هوشمند: مشکل سئویی نیست — ' . $reason );
		}
	}
	update_option( 'sap_triage_' . $pid, array_slice( $done, -800, null, true ), false );
	// AI proposals are not re-made by the rules: weigh the stored ones in place.
	global $wpdb;
	foreach ( $tech as $f ) {
		$t = $done[ $f['fingerprint'] ] ?? null;
		if ( ! $t || 0 !== strpos( $f['detector'], 'ai_' ) || 'not_issue' === $t['verdict'] ) {
			continue;
		}
		$ev           = (array) $f['evidence'];
		$ev['triage'] = $t['reason'];
		$ev['tier']   = 'minor' === $t['verdict'] ? 'minor' : ( 'high' === $t['priority'] ? 'critical' : 'important' );
		$score        = 'minor' === $ev['tier'] ? min( (float) $f['score'], 12 ) : ( 'critical' === $ev['tier'] ? max( (float) $f['score'], 60 ) : (float) $f['score'] );
		$wpdb->update( sap_table( 'findings' ), array( 'evidence' => wp_json_encode( $ev ), 'score' => $score ), array( 'id' => (int) $f['id'] ) );
	}
	// Re-run the rules so the triage weights apply to the stored findings and the score.
	$run = sap_run_detectors( $project );
	sap_findings_sync( $pid, $run['findings'], $run['ran'] );
	return array( 'msg' => sprintf( 'بازبینی هوشمند %s مشکل تکنیکال: %s مشکل واقعی، %s جزئی، %s غیرمشکل (کنار گذاشته شد، با دلیل).', sap_num( array_sum( $n ) ), sap_num( $n['real'] ), sap_num( $n['minor'] ), sap_num( $n['not_issue'] ) ) );
}
