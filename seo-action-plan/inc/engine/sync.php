<?php
/**
 * Sync pipeline: fetch every data source into datasets, then analyze.
 *
 * Each step is a separate request from the project page (progress list, no PHP
 * timeout issues) or runs in sequence from the scheduled job. Metered SEO Signal
 * steps reuse cached results while they are fresh.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Steps in order: id => label.
 */
function sap_sync_steps() {
	return array(
		'site_log'     => 'سایت کارفرما: لاگ تغییرات (افزونه رابط)',
		'gsc_qp'       => 'سرچ کنسول: کوئری‌ها و صفحات',
		'gsc_pages'    => 'سرچ کنسول: مقایسه صفحات با ۲۸ روز قبل',
		'gsc_sitemaps' => 'سرچ کنسول: سایت‌مپ‌ها',
		'sheets'       => 'گوگل شیت: تقویم محتوا و کلاسترینگ',
		'ss_volumes'   => 'سئو سیگنال: حجم جستجوی کلمات',
		'ss_rank'      => 'سئو سیگنال: ردیاب رتبه',
		'ss_compare'   => 'سئو سیگنال: مقایسه با رقبا',
		'ss_questions' => 'سئو سیگنال: سوالات کاربران',
		'pages'        => 'خواندن محتوای صفحات کلیدی',
		'cwv'          => 'Core Web Vitals (PageSpeed)',
		'keymap'       => 'نقشه کلمات: کلمه اصلی هر صفحه (شیت و سرچ کنسول)',
		'ai_keymap'    => 'هوش مصنوعی ۱/۷: تشخیص کلمه اصلی صفحات',
		'analyze'      => 'قوانین تحلیل: کشف ایرادها و فرصت‌ها',
		'ai_cannibal'  => 'هوش مصنوعی ۲/۷: کنیبالیزیشن هوشمند',
		'ai_content'   => 'هوش مصنوعی ۳/۷: تحلیل صفحات و محتوا',
		'ai_links'     => 'هوش مصنوعی ۴/۷: لینک‌سازی داخلی',
		'ai_technical' => 'هوش مصنوعی ۵/۷: تکنیکال، سایت‌مپ، اسکیما و سرعت',
		'ai_ideas'     => 'هوش مصنوعی ۶/۷: ایده‌ها و کلمات کلیدی محتوای جدید',
		'ai_plan'      => 'هوش مصنوعی ۷/۷: جمع‌بندی و اکشن پلن',
		'writeback'    => 'نوشتن ستون‌های خودکار در گوگل شیت',
	);
}

/**
 * Run one step.
 *
 * @return array{ok: bool, skipped: bool, msg: string}
 */
function sap_sync_step( $project, $step, $force = false ) {
	$fn = 'sap_step_' . $step;
	if ( ( ! array_key_exists( $step, sap_sync_steps() ) && ! in_array( $step, array( 'ai_focus' ), true ) ) || ! function_exists( $fn ) ) {
		return array( 'ok' => false, 'skipped' => false, 'msg' => 'مرحله نامعتبر.' );
	}
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}
	$res = call_user_func( $fn, $project, $force );
	if ( is_wp_error( $res ) ) {
		$res = array( 'ok' => false, 'skipped' => false, 'msg' => $res->get_error_message() );
	}
	$res = wp_parse_args( $res, array( 'ok' => true, 'skipped' => false, 'msg' => '' ) );
	$log          = (array) ( $project['options']['sync_log'] ?? array() );
	$log[ $step ] = array( 'ok' => $res['ok'], 'skipped' => $res['skipped'], 'msg' => $res['msg'], 'at' => sap_now() );
	sap_project_set_option( $project['id'], 'sync_log', $log );
	if ( 'writeback' === $step ) {
		sap_project_set_option( $project['id'], 'last_sync', sap_now() );
	}
	return $res;
}

/**
 * Run every step (scheduled sync).
 */
function sap_sync_all( $project_id, $force = false, $scheduled = false ) {
	$out = array();
	foreach ( array_keys( sap_sync_steps() ) as $step ) {
		if ( 0 === strpos( $step, 'ai_' ) && $scheduled && ! sap_setting( 'ai_auto' ) ) {
			continue; // The paid AI steps run on schedule only when enabled.
		}
		if ( $scheduled && in_array( $step, array( 'ss_volumes', 'ss_compare', 'ss_questions' ), true ) && ! sap_setting( 'ss_auto' ) ) {
			continue; // SEO Signal quota is spent on schedule only when enabled.
		}
		do {
			$project      = sap_project( $project_id ); // Fresh options each step.
			$out[ $step ] = sap_sync_step( $project, $step, $force );
		} while ( ! empty( $out[ $step ]['more'] ) );
	}
	return $out;
}

/**
 * Skipped-step result.
 */
function sap_skip( $msg ) {
	return array( 'ok' => true, 'skipped' => true, 'msg' => $msg );
}

/* ---- Client site ------------------------------------------------------- */

function sap_step_site_log( $project ) {
	if ( ! sap_site_connected( $project ) ) {
		return sap_skip( 'اتصال به سایت کارفرما (افزونه رابط) تنظیم نشده — تنظیمات پروژه.' );
	}
	$res = sap_site_pull_changes( $project );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return $res['added'] ? array( 'msg' => sprintf( '%s تغییر جدید سایت در لاگ اقدامات ثبت شد.', sap_num( $res['added'] ) ) ) : sap_skip( 'تغییر جدیدی در سایت ثبت نشده.' );
}

/* ---- Search Console ---------------------------------------------------- */

function sap_step_gsc_qp( $project ) {
	if ( '' === $project['gsc_property'] ) {
		return sap_skip( 'پراپرتی سرچ کنسول برای پروژه انتخاب نشده.' );
	}
	if ( ! sap_google_connected() ) {
		return sap_skip( 'حساب گوگل متصل نیست.' );
	}
	$w    = sap_gsc_windows();
	$rows = sap_gsc_query( $project['gsc_property'], $w['cur'][0], $w['cur'][1], array( 'query', 'page' ), 50000 );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	sap_dataset_put( $project['id'], 'gsc_qp', array( 'rows' => $rows ), array( 'range' => $w['cur'], 'count' => count( $rows ) ) );
	return array( 'msg' => sprintf( '%s ردیف کوئری × صفحه (%s تا %s).', sap_num( count( $rows ) ), sap_jdate( $w['cur'][0], 'short' ), sap_jdate( $w['cur'][1], 'short' ) ) );
}

function sap_step_gsc_pages( $project ) {
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return sap_skip( 'سرچ کنسول متصل نیست.' );
	}
	$w    = sap_gsc_windows();
	$cur  = sap_gsc_query( $project['gsc_property'], $w['cur'][0], $w['cur'][1], array( 'page' ), 10000 );
	if ( is_wp_error( $cur ) ) {
		return $cur;
	}
	$prev = sap_gsc_query( $project['gsc_property'], $w['prev'][0], $w['prev'][1], array( 'page' ), 10000 );
	if ( is_wp_error( $prev ) ) {
		return $prev;
	}
	sap_dataset_put( $project['id'], 'gsc_pages', array( 'cur' => $cur, 'prev' => $prev ), array( 'cur' => $w['cur'], 'prev' => $w['prev'] ) );
	return array( 'msg' => sprintf( '%s صفحه در ۲۸ روز اخیر، %s صفحه در ۲۸ روز قبل.', sap_num( count( $cur ) ), sap_num( count( $prev ) ) ) );
}

function sap_step_gsc_sitemaps( $project ) {
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return sap_skip( 'سرچ کنسول متصل نیست.' );
	}
	$maps = sap_gsc_sitemaps( $project['gsc_property'] );
	if ( is_wp_error( $maps ) ) {
		return $maps;
	}
	sap_dataset_put( $project['id'], 'gsc_sitemaps', array( 'sitemaps' => $maps ) );
	return array( 'msg' => sprintf( '%s سایت‌مپ.', sap_num( count( $maps ) ) ) );
}

/* ---- Google Sheets ----------------------------------------------------- */

function sap_step_sheets( $project ) {
	if ( '' === $project['sheet_id'] ) {
		return sap_skip( 'گوگل شیت برای پروژه ثبت نشده.' );
	}
	if ( ! sap_google_connected() ) {
		return sap_skip( 'حساب گوگل متصل نیست.' );
	}
	if ( ! sap_google_has_sheets() ) {
		return array( 'ok' => false, 'msg' => 'اجازه دسترسی به Google Sheets داده نشده؛ در تنظیمات دوباره حساب گوگل را متصل کنید.' );
	}
	$msgs   = array();
	$errors = array();
	// The agency's «Blog» layout (monthly blocks) or the standard one-row-per-article table.
	$raw = sap_sheet_values( $project['sheet_id'], $project['sheet_calendar_tab'] );
	if ( ! is_wp_error( $raw ) && sap_is_blog_layout( $raw ) ) {
		$items    = sap_blog_parse( $raw );
		$kw_vals  = sap_sheet_values( $project['sheet_id'], SAP_TAB_BKEYWORD );
		$related  = is_wp_error( $kw_vals ) ? array() : sap_bkeyword_parse( $kw_vals );
		$imported = sap_calendar_import_sheet( $project, $items );
		$cal      = array( 'rows' => sap_blog_calendar_rows( $items, $related ), 'header' => array(), 'map' => array(), 'tab' => $project['sheet_calendar_tab'], 'layout' => 'blog' );
	} else {
		$cal = sap_sheet_read( $project['sheet_id'], $project['sheet_calendar_tab'], 'calendar' );
	}
	if ( is_wp_error( $cal ) ) {
		$errors[] = $cal->get_error_message();
	} else {
		$logged = sap_log_published_content( $project, $cal['rows'] );
		sap_dataset_put( $project['id'], 'sheet_calendar', $cal, array( 'count' => count( $cal['rows'] ) ) );
		$msgs[] = sprintf( 'تقویم محتوا: %s ردیف', sap_num( count( $cal['rows'] ) ) ) . ( $logged ? sprintf( ' (%s انتشار جدید در لاگ ثبت شد)', sap_num( $logged ) ) : '' ) . ( ! empty( $imported ) ? sprintf( ' · %s مقاله از شیت وارد تقویم ابزار شد', sap_num( $imported ) ) : '' );
	}
	$cl = sap_sheet_read( $project['sheet_id'], $project['sheet_cluster_tab'], 'clusters' );
	if ( is_wp_error( $cl ) && 'sap_sheet_tab' === $cl->get_error_code() ) {
		$msgs[] = 'تب کلاسترینگ در شیت نیست (اختیاری)';
	} elseif ( is_wp_error( $cl ) ) {
		$errors[] = $cl->get_error_message();
	} else {
		sap_dataset_put( $project['id'], 'sheet_clusters', $cl, array( 'count' => count( $cl['rows'] ), 'clusters' => count( array_unique( array_column( $cl['rows'], 'cluster' ) ) ) ) );
		$msgs[] = sprintf( 'کلاسترینگ: %s کلمه در %s کلاستر', sap_num( count( $cl['rows'] ) ), sap_num( count( array_unique( array_column( $cl['rows'], 'cluster' ) ) ) ) );
	}
	return array( 'ok' => empty( $errors ), 'msg' => implode( ' · ', array_merge( $msgs, $errors ) ) );
}

/**
 * Log newly published calendar content in the activity log.
 *
 * On the first sync only content published this month is logged, so the log is
 * not flooded with the whole history.
 *
 * @return int Entries added.
 */
function sap_log_published_content( $project, $rows ) {
	$prev     = sap_dataset_payload( $project['id'], 'sheet_calendar' );
	$had      = array();
	$first    = empty( $prev );
	foreach ( (array) ( $prev['rows'] ?? array() ) as $r ) {
		if ( '' !== $r['url'] ) {
			$had[ sap_norm_url( $r['url'] ) ] = true;
		}
	}
	list( $start ) = sap_period_range( sap_period_current() );
	$focuses       = sap_focus_context( $project['id'] );
	$n             = 0;
	foreach ( $rows as $r ) {
		if ( '' === $r['url'] || ! in_array( $r['status_key'], array( 'published', '' ), true ) ) {
			continue;
		}
		$key = sap_norm_url( $r['url'] );
		$ref = 'pub:' . md5( $key );
		if ( isset( $had[ $key ] ) || sap_activity_has_ref( $project['id'], $ref ) ) {
			continue;
		}
		if ( $first && ( ! $r['published_date'] || $r['published_date'] < $start ) ) {
			continue;
		}
		list( $fid ) = sap_focus_match( $focuses, $r['url'], $r['keyword'] );
		sap_activity_add( $project['id'], array(
			'focus_id'    => $fid,
			'category'    => 'content_new',
			'action'      => 'content_published',
			'title'       => sprintf( 'انتشار محتوا: «%s»', $r['keyword'] ),
			'url'         => $r['url'],
			'after_value' => $r['related'] ? 'کلمات هم‌کانسپت: ' . $r['related'] : '',
			'source'      => 'sheet',
			'ref'         => $ref,
			'user_id'     => 0,
			'happened_at' => ( $r['published_date'] ?: current_time( 'Y-m-d' ) ) . ' 12:00:00',
			'meta'        => array( 'owner' => $r['owner'], 'sheet_row' => $r['_row'] ),
		) );
		++$n;
	}
	return $n;
}

/* ---- SEO Signal -------------------------------------------------------- */

/**
 * All keywords of the project (sheets + focus keywords).
 */
function sap_project_keywords( $project ) {
	$kw = array();
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'sheet_calendar' )['rows'] ?? array() ) as $r ) {
		$kw[] = $r['keyword'];
		foreach ( $r['related_list'] as $k ) {
			$kw[] = $k;
		}
	}
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'sheet_clusters' )['rows'] ?? array() ) as $r ) {
		$kw[] = $r['keyword'];
	}
	foreach ( sap_focuses( $project['id'], true ) as $f ) {
		$kw = array_merge( $kw, $f['keywords'] );
	}
	$uniq = array();
	foreach ( $kw as $k ) {
		$k = trim( (string) $k );
		if ( '' !== $k ) {
			$uniq[ sap_kw_key( $k ) ] = $k;
		}
	}
	return array_values( $uniq );
}

function sap_step_ss_volumes( $project, $force = false ) {
	if ( ! sap_ss_connected() ) {
		return sap_skip( 'کلید سئو سیگنال وارد نشده.' );
	}
	$keywords = sap_project_keywords( $project ) ?: sap_ss_track_suggestions( $project, 1000 ); // No sheet / focus keywords yet: the keyword map's.
	if ( ! $keywords ) {
		return sap_skip( 'هنوز کلمه‌ای در شیت‌ها، Focusها یا نقشه کلمات نیست؛ اول مرحله «نقشه کلمات» باید اجرا شود.' );
	}
	$hash = md5( implode( '|', $keywords ) );
	$old  = sap_dataset( $project['id'], 'ss_volumes' );
	$age  = sap_dataset_age_days( $project['id'], 'ss_volumes' );
	if ( ! $force && $old && ( $old['meta']['hash'] ?? '' ) === $hash && $age < (int) sap_setting( 'seosignal_cache_days', 25 ) ) {
		return sap_skip( sprintf( 'حجم %s کلمه از قبل موجود است (%s روز پیش)؛ بدون مصرف سهمیه.', sap_num( count( $keywords ) ), sap_num( floor( $age ) ) ) );
	}
	$vol = sap_ss_volumes( $keywords );
	if ( is_wp_error( $vol ) ) {
		return $vol;
	}
	sap_dataset_put( $project['id'], 'ss_volumes', array( 'volumes' => $vol ), array( 'hash' => $hash, 'count' => count( $keywords ) ) );
	return array( 'msg' => sprintf( 'حجم جستجوی %s کلمه دریافت شد (%s واحد سهمیه).', sap_num( count( $vol ) ), sap_num( (int) ceil( count( $keywords ) / 1000 ) ) ) );
}

function sap_step_ss_rank( $project ) {
	if ( ! sap_ss_connected() ) {
		return sap_skip( 'کلید سئو سیگنال وارد نشده.' );
	}
	if ( ! $project['ss_project_id'] ) {
		return sap_skip( 'پروژه ردیاب رتبه سئو سیگنال به این پروژه وصل نشده.' );
	}
	$detail = sap_ss_rt_detail( $project['ss_project_id'] );
	if ( is_wp_error( $detail ) ) {
		return $detail;
	}
	$device = (int) ( 'desktop' === $project['ss_device'] ? ( $detail['desktop_id'] ?? 0 ) : ( $detail['mobile_id'] ?? 0 ) );
	if ( ! $device ) {
		return array( 'ok' => false, 'msg' => 'شناسه دستگاه ردیاب رتبه پیدا نشد.' );
	}
	$end   = current_time( 'Y-m-d' );
	$start = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $end ) ) );
	$hist  = sap_ss_rt_history( $project['ss_project_id'], $device, '', $start, $end );
	if ( is_wp_error( $hist ) ) {
		return $hist;
	}
	$hist['detail'] = array(
		'name'        => $detail['name'] ?? '',
		'keywords'    => $detail['keywords'] ?? array(),
		'competitors' => $detail['competitor_domains'] ?? array(),
		'location'    => $detail['location'] ?? '',
	);
	sap_dataset_put( $project['id'], 'ss_rank', $hist, array( 'range' => array( $start, $end ), 'count' => count( $hist['keywords'] ) ) );
	return array( 'msg' => sprintf( 'رتبه ۳۰ روزه %s کلمه (رایگان، بدون سقف).', sap_num( count( $hist['keywords'] ) ) ) );
}

function sap_step_ss_compare( $project, $force = false ) {
	if ( ! sap_ss_connected() ) {
		return sap_skip( 'کلید سئو سیگنال وارد نشده.' );
	}
	$domain = sap_project_domain( $project );
	$comps  = array_slice( $project['competitors'], 0, 3 );
	if ( ! $comps || '' === $domain ) {
		return sap_skip( 'رقیبی برای پروژه تعریف نشده.' );
	}
	$old   = sap_dataset_payload( $project['id'], 'ss_compare' );
	$fresh = (int) sap_setting( 'seosignal_cache_days', 25 ) * DAY_IN_SECONDS;
	$data  = (array) ( $old['competitors'] ?? array() );
	$data  = array_intersect_key( $data, array_flip( $comps ) );
	$done  = array();
	$msgs  = array();
	foreach ( $comps as $c ) {
		if ( ! $force && isset( $data[ $c ]['at'] ) && time() - (int) $data[ $c ]['at'] < $fresh ) {
			continue;
		}
		if ( sap_ss_remaining() < 4 ) {
			$msgs[] = sprintf( 'مقایسه با %s به دلیل سقف روزانه به بعد موکول شد.', $c );
			continue;
		}
		$gap = sap_ss_compare( $domain, $c, 'gap' );
		if ( is_wp_error( $gap ) ) {
			$msgs[] = $c . ': ' . $gap->get_error_message();
			continue;
		}
		$worse = sap_ss_compare( $domain, $c, 'worse_than' );
		$data[ $c ] = array(
			'gap'   => array_slice( $gap, 0, 1500 ),
			'worse' => is_wp_error( $worse ) ? array() : array_slice( $worse, 0, 1500 ),
			'at'    => time(),
		);
		$done[] = $c;
	}
	if ( ! $done && ! $data ) {
		return array( 'ok' => false, 'msg' => implode( ' · ', $msgs ) ?: 'داده‌ای دریافت نشد.' );
	}
	if ( $done ) {
		sap_dataset_put( $project['id'], 'ss_compare', array( 'domain' => $domain, 'competitors' => $data ) );
	}
	if ( ! $done && ! $msgs ) {
		return sap_skip( 'مقایسه رقبا از قبل موجود و تازه است؛ بدون مصرف سهمیه.' );
	}
	return array( 'msg' => implode( ' · ', array_merge( $done ? array( 'مقایسه با ' . implode( '، ', $done ) . ' دریافت شد.' ) : array(), $msgs ) ) );
}

function sap_step_ss_questions( $project, $force = false ) {
	if ( ! sap_ss_connected() ) {
		return sap_skip( 'کلید سئو سیگنال وارد نشده.' );
	}
	$focuses = sap_focuses( $project['id'], true );
	if ( ! $focuses ) {
		return sap_skip( 'Focus فعالی تعریف نشده.' );
	}
	$old   = sap_dataset_payload( $project['id'], 'ss_questions' );
	$data  = (array) ( $old['focuses'] ?? array() );
	$fresh = (int) sap_setting( 'seosignal_cache_days', 25 ) * DAY_IN_SECONDS;
	$done  = array();
	$keep  = array();
	$errs  = array();
	foreach ( $focuses as $f ) {
		$kw         = $f['keywords'][0] ?? $f['name'];
		$keep[ $f['id'] ] = true;
		$cached     = $data[ $f['id'] ] ?? null;
		if ( ! $force && $cached && $cached['keyword'] === $kw && time() - (int) $cached['at'] < $fresh ) {
			continue;
		}
		if ( sap_ss_remaining() < 1 ) {
			break;
		}
		$rows = sap_ss_research( $kw, 'questions', 100 );
		if ( is_wp_error( $rows ) ) {
			$errs[] = $kw . ': ' . $rows->get_error_message();
			if ( in_array( $rows->get_error_data()['code'] ?? '', array( 'INVALID_API_KEY', 'ACCOUNT_DISABLED', 'PLAN_NOT_ALLOWED', 'RATE_LIMIT_EXCEEDED', 'NETWORK', 'NOT_JSON' ), true ) ) {
				break; // Same failure for every keyword: stop instead of repeating it.
			}
			continue;
		}
		$data[ $f['id'] ] = array( 'keyword' => $kw, 'rows' => $rows, 'at' => time() );
		$done[]           = $kw;
	}
	$data = array_intersect_key( $data, $keep );
	sap_dataset_put( $project['id'], 'ss_questions', array( 'focuses' => $data ) );
	if ( ! $done && $errs ) {
		return array( 'ok' => false, 'msg' => implode( ' · ', array_unique( $errs ) ) );
	}
	return $done ? array( 'msg' => 'سوالات کاربران برای: ' . implode( '، ', $done ) . ( $errs ? ' · خطا: ' . implode( ' · ', $errs ) : '' ) ) : sap_skip( 'سوالات Focusها از قبل موجود است؛ بدون مصرف سهمیه.' );
}

/* ---- Analysis ---------------------------------------------------------- */

function sap_step_analyze( $project ) {
	$run   = sap_run_detectors( $project );
	$stats = sap_findings_sync( $project['id'], $run['findings'], $run['ran'] );
	sap_project_set_option( $project['id'], 'last_analysis', array(
		'at'      => sap_now(),
		'ran'     => count( $run['ran'] ),
		'skipped' => $run['skipped'],
		'stats'   => $stats,
	) );
	return array( 'msg' => sprintf( '%s قانون اجرا شد · %s یافته جدید، %s بروزرسانی، %s برطرف‌شده.', sap_num( count( $run['ran'] ) ), sap_num( $stats['new'] ), sap_num( $stats['updated'] ), sap_num( $stats['resolved'] ) ) );
}

/* ---- Page content & Core Web Vitals ------------------------------------ */

/**
 * Read the key pages (resumable: continues on the next call while time runs out).
 */
function sap_step_pages( $project, $force = false ) {
	$ds    = sap_dataset( $project['id'], 'pages' );
	$data  = $ds ? $ds['payload'] : array( 'pages' => array() );
	$meta  = $ds ? $ds['meta'] : array();
	$queue = (array) ( $meta['queue'] ?? array() );
	if ( ! $queue ) {
		$age = $ds ? ( current_time( 'timestamp' ) - strtotime( $ds['fetched_at'] ) ) / DAY_IN_SECONDS : 999; // phpcs:ignore
		if ( ! $force && $age < 7 && ! empty( $data['pages'] ) ) {
			return sap_skip( sprintf( '%s صفحه در %s روز اخیر خوانده شده؛ دوباره خوانده نمی‌شوند.', sap_num( count( $data['pages'] ) ), sap_num( floor( $age ) ) ) );
		}
		$queue = sap_pages_to_read( $project, 30 );
		if ( ! $queue ) {
			return sap_skip( 'هنوز صفحه‌ای برای خواندن نیست (اول سرچ کنسول را همگام کنید).' );
		}
		$data = array( 'pages' => array() );
		$meta = array( 'errors' => array() );
	}
	$start = microtime( true );
	while ( $queue && microtime( true ) - $start < 35 ) {
		$url  = array_shift( $queue );
		$page = sap_page_fetch( $url );
		if ( is_wp_error( $page ) ) {
			$meta['errors'][ $url ] = $page->get_error_message();
			continue;
		}
		$data['pages'][ sap_norm_url( $url ) ] = $page;
	}
	$meta['queue'] = $queue;
	$meta['count'] = count( $data['pages'] );
	sap_dataset_put( $project['id'], 'pages', $data, $meta );
	if ( $queue ) {
		return array( 'more' => true, 'msg' => sprintf( '%s صفحه خوانده شد، %s صفحه باقی مانده…', sap_num( count( $data['pages'] ) ), sap_num( count( $queue ) ) ) );
	}
	$err = count( (array) $meta['errors'] );
	return array( 'ok' => (bool) $data['pages'], 'msg' => sprintf( 'متن، تیترها، اسکیما و لینک‌های %s صفحه خوانده شد', sap_num( count( $data['pages'] ) ) ) . ( $err ? sprintf( ' (%s صفحه باز نشد)', sap_num( $err ) ) : '' ) . '.' );
}

/**
 * URLs for Core Web Vitals: home, focus main pages, top pages.
 */
function sap_cwv_urls( $project ) {
	$urls  = array();
	$home  = $project['site_url'] ?: 'https://' . sap_project_domain( $project ) . '/';
	$urls[ sap_norm_url( $home ) ] = $home;
	$ctx = array( 'focuses' => sap_focus_context( $project['id'] ), 'sheet_clusters' => sap_dataset_payload( $project['id'], 'sheet_clusters' ), 'page_impr' => array(), 'page_url' => array() );
	foreach ( (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() ) as $r ) {
		$ctx['page_impr'][ sap_norm_url( $r['page'] ) ] = $r['impressions'];
		$ctx['page_url'][ sap_norm_url( $r['page'] ) ]  = $r['page'];
	}
	foreach ( $ctx['focuses'] as $f ) {
		$pillar = sap_focus_pillar( $ctx, $f['id'] );
		if ( $pillar ) {
			$urls[ sap_norm_url( $pillar ) ] = $pillar;
		}
	}
	arsort( $ctx['page_impr'] );
	foreach ( array_slice( array_keys( $ctx['page_impr'] ), 0, 3 ) as $k ) {
		$urls[ $k ] = $ctx['page_url'][ $k ];
	}
	return array_slice( array_values( $urls ), 0, 4 );
}

/**
 * Core Web Vitals of the key pages (mobile), resumable, refreshed weekly.
 */
function sap_step_cwv( $project, $force = false ) {
	$ds    = sap_dataset( $project['id'], 'cwv' );
	$data  = $ds ? $ds['payload'] : array( 'pages' => array() );
	$meta  = $ds ? $ds['meta'] : array();
	$queue = (array) ( $meta['queue'] ?? array() );
	if ( ! $queue ) {
		$age = $ds ? ( current_time( 'timestamp' ) - strtotime( $ds['fetched_at'] ) ) / DAY_IN_SECONDS : 999; // phpcs:ignore
		if ( ! $force && $age < 7 && ! empty( $data['pages'] ) ) {
			return sap_skip( 'Core Web Vitals در هفته اخیر گرفته شده.' );
		}
		$queue = sap_cwv_urls( $project );
		$data  = array( 'pages' => array() );
		$meta  = array( 'errors' => array() );
	}
	$start = microtime( true );
	while ( $queue && microtime( true ) - $start < 20 ) {
		$url = array_shift( $queue );
		$psi = sap_psi_run( $url, 'mobile' );
		if ( is_wp_error( $psi ) ) {
			$meta['errors'][ $url ] = $psi->get_error_message();
			continue;
		}
		$data['pages'][ sap_norm_url( $url ) ] = $psi;
	}
	$meta['queue'] = $queue;
	sap_dataset_put( $project['id'], 'cwv', $data, $meta );
	if ( $queue ) {
		return array( 'more' => true, 'msg' => sprintf( '%s صفحه تست شد، %s صفحه باقی مانده…', sap_num( count( $data['pages'] ) ), sap_num( count( $queue ) ) ) );
	}
	if ( ! $data['pages'] ) {
		return array( 'ok' => false, 'msg' => (string) ( array_values( (array) $meta['errors'] )[0] ?? 'PageSpeed پاسخی نداد.' ) );
	}
	return array( 'msg' => sprintf( 'Core Web Vitals موبایل برای %s صفحه کلیدی.', sap_num( count( $data['pages'] ) ) ) );
}

/* ---- AI stages --------------------------------------------------------- */

function sap_step_keymap( $project ) {
	$st = sap_keymap_build( $project );
	if ( ! $st['pages'] ) {
		return sap_skip( 'هنوز صفحه‌ای برای نقشه کلمات نیست (اول سرچ کنسول را همگام کنید).' );
	}
	$map  = sap_keymap( $project['id'] );
	$conf = count( array_filter( $map, function ( $m ) {
		return $m['confirmed'] || in_array( $m['source'], array( 'manual', 'sheet' ), true );
	} ) );
	return array( 'msg' => sprintf( 'کلمه اصلی %s صفحه مشخص شد (%s از شیت یا تأیید شما، بقیه تشخیص خودکار).', sap_num( $st['pages'] ), sap_num( $conf ) ) );
}
function sap_step_ai_keymap( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'keymap', $force );
}
function sap_step_ai_focus( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'focus', $force );
}
function sap_step_ai_cannibal( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'cannibal', $force );
}
function sap_step_ai_content( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'content', $force );
}
function sap_step_ai_links( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'links', $force );
}
function sap_step_ai_technical( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'technical', $force );
}
function sap_step_ai_ideas( $project, $force = false ) {
	if ( ! sap_ai_connected() ) {
		return sap_skip( 'کلید هوش مصنوعی وارد نشده.' );
	}
	$pending = count( sap_ideas( $project['id'], array( 'status' => 'new' ) ) );
	if ( ! $force && $pending >= 5 ) {
		return sap_skip( sprintf( '%s ایده هنوز منتظر بررسی شماست (تب «ایده‌های محتوا»)؛ ایده جدید ساخته نشد.', sap_num( $pending ) ) );
	}
	$res = sap_ideas_generate( $project, 8 );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$u = sap_ai_usage();
	return array( 'msg' => sprintf( '%s ایده محتوای جدید (کلمه اصلی، فرعی، هدف و ساختار) در تب «ایده‌های محتوا»', sap_num( $res['added'] ) ) . sprintf( ' · هزینه حدود %s دلار.', sap_num( $u['last_cost'], 3 ) ) );
}
function sap_step_ai_plan( $project, $force = false ) {
	return sap_ai_stage_step( $project, 'plan', $force );
}

/**
 * Run one AI stage as a sync step.
 */
function sap_ai_stage_step( $project, $stage, $force ) {
	if ( ! sap_ai_connected() ) {
		return sap_skip( 'کلید هوش مصنوعی وارد نشده (تنظیمات › هوش مصنوعی).' );
	}
	// The gateway refused the key / model a moment ago: don't repeat the same failing call for every stage.
	$denied = get_transient( 'sap_ai_denied' );
	if ( $denied ) {
		return new WP_Error( 'sap_ai_auth', 'انجام نشد؛ همان خطای مرحله قبل: ' . $denied );
	}
	$res = sap_ai_stage_run( $project, $stage, $force );
	if ( is_wp_error( $res ) ) {
		if ( 'sap_ai_auth' === $res->get_error_code() ) {
			set_transient( 'sap_ai_denied', $res->get_error_message(), 5 * MINUTE_IN_SECONDS );
		}
		return $res;
	}
	if ( ! empty( $res['skipped'] ) ) {
		return sap_skip( $res['skipped'] );
	}
	if ( ! empty( $res['more'] ) ) {
		return array( 'more' => true, 'msg' => $res['summary_line'] );
	}
	$u = sap_ai_usage();
	return array( 'msg' => $res['summary_line'] . sprintf( ' · هزینه حدود %s دلار (این ماه %s از %s دلار).', sap_num( $u['last_cost'], 3 ), sap_num( $u['cost'], 2 ), sap_num( $u['budget'], 2 ) ) );
}

/**
 * Write the auto columns of both sheets.
 */
function sap_step_writeback( $project ) {
	if ( '' === $project['sheet_id'] || ! sap_google_connected() || ! sap_google_has_sheets() ) {
		return sap_skip( 'گوگل شیت متصل نیست.' );
	}
	if ( ! empty( $project['options']['no_writeback'] ) ) {
		return sap_skip( 'نوشتن در شیت برای این پروژه غیرفعال است.' );
	}
	$ctx   = sap_analysis_context( $project );
	$today = sap_jdate( current_time( 'Y-m-d' ), 'short' );
	$msgs  = array();
	$fail  = false;
	if ( ! empty( $ctx['sheet_calendar']['rows'] ) && 'blog' !== ( $ctx['sheet_calendar']['layout'] ?? '' ) ) {
		$cols = array_fill_keys( array( 'a_volume', 'a_position', 'a_clicks', 'a_impr', 'a_missing', 'a_updated' ), array() );
		foreach ( $ctx['sheet_calendar']['rows'] as $r ) {
			$row  = $r['_row'];
			$vol  = sap_ctx_volume( $ctx, $r['keyword'] );
			$best = '' !== $r['url'] ? sap_ctx_best( $ctx, $r['keyword'], $r['url'] ) : sap_ctx_best( $ctx, $r['keyword'] );
			$cols['a_volume'][ $row ]   = $vol ?: '';
			$cols['a_position'][ $row ] = $best ? round( $best['position'], 1 ) : '';
			if ( '' !== $r['url'] ) {
				$prow                     = (array) ( $ctx['by_page'][ sap_norm_url( $r['url'] ) ] ?? array() );
				$cols['a_clicks'][ $row ] = array_sum( array_column( $prow, 'clicks' ) );
				$cols['a_impr'][ $row ]   = array_sum( array_column( $prow, 'impressions' ) );
				$missing                  = array();
				foreach ( $r['related_list'] as $k ) {
					if ( ! sap_ctx_best( $ctx, $k, $r['url'] ) ) {
						$missing[] = $k;
					}
				}
				$cols['a_missing'][ $row ] = implode( '، ', $missing );
			}
			$cols['a_updated'][ $row ] = $today;
		}
		$res    = sap_sheet_write_auto( $project['sheet_id'], $ctx['sheet_calendar'], 'calendar', $cols );
		$fail   = $fail || is_wp_error( $res );
		$msgs[] = is_wp_error( $res ) ? 'تقویم: ' . $res->get_error_message() : 'تقویم محتوا بروز شد';
	}
	if ( ! empty( $ctx['sheet_clusters']['rows'] ) ) {
		$cols = array_fill_keys( array( 'a_volume', 'a_competition', 'a_position', 'a_rank_page', 'a_clicks', 'a_coverage', 'a_updated' ), array() );
		foreach ( sap_cluster_coverage( $ctx ) as $row => $c ) {
			$vk                            = $ctx['volumes'][ sap_kw_key( $c['row']['keyword'] ) ] ?? array();
			$cols['a_volume'][ $row ]      = $c['volume'] ?: '';
			$cols['a_competition'][ $row ] = $vk['competition'] ?? '';
			$cols['a_position'][ $row ]    = $c['best'] ? round( $c['best']['position'], 1 ) : '';
			$cols['a_rank_page'][ $row ]   = $c['best'] ? rawurldecode( $c['best']['page'] ) : '';
			$cols['a_clicks'][ $row ]      = $c['best'] ? $c['best']['clicks'] : '';
			$cols['a_coverage'][ $row ]    = $c['label'];
			$cols['a_updated'][ $row ]     = $today;
		}
		$res    = sap_sheet_write_auto( $project['sheet_id'], $ctx['sheet_clusters'], 'clusters', $cols );
		$fail   = $fail || is_wp_error( $res );
		$msgs[] = is_wp_error( $res ) ? 'کلاسترینگ: ' . $res->get_error_message() : 'کلاسترینگ بروز شد';
	}
	// Content calendar (Blog + B keyword), keyword map and change log tabs.
	if ( sap_calendar_months( $project['id'] ) ) {
		$res    = sap_calendar_write_sheet( $project );
		$fail   = $fail || is_wp_error( $res );
		$msgs[] = is_wp_error( $res ) ? 'تقویم Blog: ' . $res->get_error_message() : $res;
	}
	if ( sap_keymap( $project['id'] ) ) {
		$res    = sap_keymap_write_sheet( $project );
		$fail   = $fail || is_wp_error( $res );
		$msgs[] = is_wp_error( $res ) ? 'نقشه کلمات: ' . $res->get_error_message() : $res;
	}
	$res    = sap_log_write_sheet( sap_project( $project['id'] ) );
	$fail   = $fail || is_wp_error( $res );
	$msgs[] = is_wp_error( $res ) ? 'لاگ تغییرات: ' . $res->get_error_message() : $res;
	return array( 'ok' => ! $fail, 'msg' => implode( ' · ', $msgs ) );
}

/* ---- Scheduling -------------------------------------------------------- */

add_action( 'sap_daily', function () {
	$mode = sap_setting( 'auto_sync', 'weekly' );
	if ( 'off' === $mode ) {
		return;
	}
	$every = 'daily' === $mode ? DAY_IN_SECONDS - HOUR_IN_SECONDS : 7 * DAY_IN_SECONDS - HOUR_IN_SECONDS;
	$i     = 0;
	foreach ( sap_projects() as $p ) {
		$last = strtotime( (string) ( $p['options']['last_sync'] ?? '' ) );
		if ( ! $last || current_time( 'timestamp' ) - $last >= $every ) { // phpcs:ignore
			wp_schedule_single_event( time() + 60 + 600 * $i++, 'sap_sync_project', array( $p['id'] ) );
		}
	}
} );

add_action( 'sap_sync_project', function ( $project_id ) {
	sap_sync_all( (int) $project_id, false, true );
} );
