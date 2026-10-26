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
		'gsc_qp'       => 'سرچ کنسول: کوئری‌ها و صفحات',
		'gsc_pages'    => 'سرچ کنسول: مقایسه صفحات با ۲۸ روز قبل',
		'gsc_sitemaps' => 'سرچ کنسول: سایت‌مپ‌ها',
		'sheets'       => 'گوگل شیت: تقویم محتوا و کلاسترینگ',
		'ss_volumes'   => 'سئو سیگنال: حجم جستجوی کلمات',
		'ss_rank'      => 'سئو سیگنال: ردیاب رتبه',
		'ss_compare'   => 'سئو سیگنال: مقایسه با رقبا',
		'ss_questions' => 'سئو سیگنال: سوالات کاربران',
		'analyze'      => 'تحلیل و کشف ایرادها و فرصت‌ها',
		'ai'           => 'تحلیل هوشمند و اکشن پلن پیشنهادی (هوش مصنوعی)',
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
	if ( ! array_key_exists( $step, sap_sync_steps() ) || ! function_exists( $fn ) ) {
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
		if ( 'ai' === $step && $scheduled && ! sap_setting( 'ai_auto' ) ) {
			continue; // The paid AI step runs on schedule only when enabled.
		}
		$project      = sap_project( $project_id ); // Fresh options each step.
		$out[ $step ] = sap_sync_step( $project, $step, $force );
	}
	return $out;
}

/**
 * Skipped-step result.
 */
function sap_skip( $msg ) {
	return array( 'ok' => true, 'skipped' => true, 'msg' => $msg );
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
	$cal    = sap_sheet_read( $project['sheet_id'], $project['sheet_calendar_tab'], 'calendar' );
	if ( is_wp_error( $cal ) ) {
		$errors[] = $cal->get_error_message();
	} else {
		$logged = sap_log_published_content( $project, $cal['rows'] );
		sap_dataset_put( $project['id'], 'sheet_calendar', $cal, array( 'count' => count( $cal['rows'] ) ) );
		$msgs[] = sprintf( 'تقویم محتوا: %s ردیف', sap_num( count( $cal['rows'] ) ) ) . ( $logged ? sprintf( ' (%s انتشار جدید در لاگ ثبت شد)', sap_num( $logged ) ) : '' );
	}
	$cl = sap_sheet_read( $project['sheet_id'], $project['sheet_cluster_tab'], 'clusters' );
	if ( is_wp_error( $cl ) ) {
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
	$keywords = sap_project_keywords( $project );
	if ( ! $keywords ) {
		return sap_skip( 'هنوز کلمه‌ای در شیت‌ها یا Focusها نیست.' );
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
		if ( sap_ss_remaining() < 2 ) {
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
			continue;
		}
		$data[ $f['id'] ] = array( 'keyword' => $kw, 'rows' => $rows, 'at' => time() );
		$done[]           = $kw;
	}
	$data = array_intersect_key( $data, $keep );
	sap_dataset_put( $project['id'], 'ss_questions', array( 'focuses' => $data ) );
	return $done ? array( 'msg' => 'سوالات کاربران برای: ' . implode( '، ', $done ) ) : sap_skip( 'سوالات Focusها از قبل موجود است؛ بدون مصرف سهمیه.' );
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

/**
 * AI diagnosis + suggested plan.
 */
function sap_step_ai( $project ) {
	if ( ! sap_ai_connected() ) {
		return sap_skip( 'کلید هوش مصنوعی وارد نشده؛ تحلیل هوشمند انجام نشد (تنظیمات › هوش مصنوعی).' );
	}
	$res = sap_ai_analyze( $project );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return array( 'msg' => sprintf( 'تشخیص وضعیت و %s کار پیشنهادی آماده شد.', sap_num( count( $res['plan'] ) ) ) );
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
	if ( ! empty( $ctx['sheet_calendar']['rows'] ) ) {
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
	if ( ! $msgs ) {
		return sap_skip( 'داده شیتی برای نوشتن نیست.' );
	}
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
