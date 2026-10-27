<?php
/**
 * SEO Signal on demand: quota estimates before each analysis step, the tools of
 * the SEO Signal tab (keyword research, bulk volumes, site compare, rank
 * tracker) with cached results, and AI advice on which queries are worth the
 * quota for this project right now.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Analysis steps that spend SEO Signal quota and are confirmed one by one.
 */
function sap_ss_ask_steps() {
	return array( 'ss_volumes', 'ss_rank', 'ss_compare', 'ss_questions' );
}

/**
 * What a step would cost now.
 *
 * @return array{units:int, msg:string, free:bool}
 */
function sap_ss_estimate( $project, $step ) {
	$fresh = (int) sap_setting( 'seosignal_cache_days', 25 );
	switch ( $step ) {
		case 'ss_volumes':
			$kws = sap_project_keywords( $project );
			$old = sap_dataset( $project['id'], 'ss_volumes' );
			if ( ! $kws ) {
				return array( 'units' => 0, 'free' => true, 'msg' => 'کلمه‌ای برای گرفتن حجم نیست.' );
			}
			if ( $old && ( $old['meta']['hash'] ?? '' ) === md5( implode( '|', $kws ) ) && sap_dataset_age_days( $project['id'], 'ss_volumes' ) < $fresh ) {
				return array( 'units' => 0, 'free' => true, 'msg' => 'حجم کلمات از قبل موجود است (از کش، بدون مصرف).' );
			}
			$u = (int) ceil( count( $kws ) / 1000 );
			return array( 'units' => $u, 'free' => false, 'msg' => sprintf( 'حجم جستجوی %s کلمه شیت‌ها و Focusها', sap_num( count( $kws ) ) ) );
		case 'ss_rank':
			return array( 'units' => 0, 'free' => true, 'msg' => $project['ss_project_id'] ? 'رتبه ۳۰ روزه کلمات ردیاب رتبه (رایگان)' : 'ردیاب رتبه به پروژه وصل نیست.' );
		case 'ss_compare':
			$data = (array) ( sap_dataset_payload( $project['id'], 'ss_compare' )['competitors'] ?? array() );
			$need = array();
			foreach ( array_slice( $project['competitors'], 0, 3 ) as $c ) {
				if ( ! isset( $data[ $c ]['at'] ) || time() - (int) $data[ $c ]['at'] >= $fresh * DAY_IN_SECONDS ) {
					$need[] = $c;
				}
			}
			return $need
				? array( 'units' => 2 * count( $need ), 'free' => false, 'msg' => 'گپ کلمات و کلمات بهتر رقیب: ' . implode( '، ', $need ) )
				: array( 'units' => 0, 'free' => true, 'msg' => $project['competitors'] ? 'مقایسه رقبا تازه است (از کش).' : 'رقیبی تعریف نشده.' );
		case 'ss_questions':
			$data = (array) ( sap_dataset_payload( $project['id'], 'ss_questions' )['focuses'] ?? array() );
			$need = array();
			foreach ( sap_focuses( $project['id'], true ) as $f ) {
				$kw = $f['keywords'][0] ?? $f['name'];
				$c  = $data[ $f['id'] ] ?? null;
				if ( ! $c || $c['keyword'] !== $kw || time() - (int) $c['at'] >= $fresh * DAY_IN_SECONDS ) {
					$need[] = $kw;
				}
			}
			return $need
				? array( 'units' => count( $need ), 'free' => false, 'msg' => 'سوالات واقعی کاربران برای: ' . implode( '، ', $need ) )
				: array( 'units' => 0, 'free' => true, 'msg' => 'سوالات Focusها تازه است (از کش).' );
	}
	return array( 'units' => 0, 'free' => true, 'msg' => '' );
}

/**
 * Run one SEO Signal tool (results cached for the cache period).
 *
 * @param string $tool research|bulk|compare|rt_projects|rt_history.
 * @return array|WP_Error {rows, cached, units}
 */
function sap_ss_tool( $project, $tool, $params, $force = false ) {
	if ( ! sap_ss_connected() ) {
		return new WP_Error( 'sap_ss_key', 'کلید API سئو سیگنال در تنظیمات وارد نشده.' );
	}
	$key    = 'sap_ss_' . md5( $tool . wp_json_encode( $params ) );
	$cached = get_transient( $key );
	if ( ! $force && is_array( $cached ) ) {
		return array( 'rows' => $cached['rows'], 'meta' => $cached['meta'] ?? array(), 'cached' => true, 'at' => $cached['at'] );
	}
	$meta = array();
	switch ( $tool ) {
		case 'research':
			$kw = trim( (string) ( $params['keyword'] ?? '' ) );
			if ( '' === $kw ) {
				return new WP_Error( 'sap_ss', 'کلمه را وارد کنید.' );
			}
			$rows = sap_ss_research( $kw, in_array( $params['type'] ?? '', array( 'similar', 'questions' ), true ) ? $params['type'] : 'similar', min( 300, max( 10, (int) ( $params['count'] ?? 100 ) ) ) );
			break;
		case 'bulk':
			$list = array_slice( sap_list( (string) ( $params['keywords'] ?? '' ) ), 0, 1000 );
			if ( ! $list ) {
				return new WP_Error( 'sap_ss', 'کلمات را وارد کنید (هر خط یکی).' );
			}
			$v    = sap_ss_volumes( $list );
			$rows = is_wp_error( $v ) ? $v : array_values( $v );
			break;
		case 'compare':
			$s2 = sap_clean_domain( (string) ( $params['site2'] ?? '' ) );
			if ( '' === $s2 ) {
				return new WP_Error( 'sap_ss', 'سایت رقیب را وارد کنید.' );
			}
			$type = in_array( $params['type'] ?? '', array( 'gap', 'worse_than', 'better_than', 'common' ), true ) ? $params['type'] : 'gap';
			$rows = sap_ss_compare( sap_clean_domain( (string) ( $params['site1'] ?? '' ) ) ?: sap_project_domain( $project ), $s2, $type );
			break;
		case 'rt_projects':
			$rows = sap_ss_rt_projects();
			break;
		case 'rt_history':
			$pid    = (int) ( $params['project_id'] ?? $project['ss_project_id'] );
			$detail = sap_ss_rt_detail( $pid );
			if ( is_wp_error( $detail ) ) {
				return $detail;
			}
			$device = (int) ( 'desktop' === ( $params['device'] ?? '' ) ? ( $detail['desktop_id'] ?? 0 ) : ( $detail['mobile_id'] ?? 0 ) );
			$days   = min( 90, max( 7, (int) ( $params['days'] ?? 30 ) ) );
			$end    = current_time( 'Y-m-d' );
			$hist   = sap_ss_rt_history( $pid, $device, (string) ( $params['domain'] ?? '' ), gmdate( 'Y-m-d', strtotime( "-$days days", strtotime( $end ) ) ), $end );
			if ( is_wp_error( $hist ) ) {
				return $hist;
			}
			$rows = $hist['keywords'];
			$meta = array( 'name' => $detail['name'] ?? '', 'location' => $detail['location'] ?? '', 'competitors' => $detail['competitor_domains'] ?? array(), 'range' => $hist['date_range'] );
			break;
		default:
			return new WP_Error( 'sap_ss_tool', 'ابزار نامعتبر.' );
	}
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$ttl = in_array( $tool, array( 'rt_projects', 'rt_history' ), true ) ? 6 * HOUR_IN_SECONDS : (int) sap_setting( 'seosignal_cache_days', 25 ) * DAY_IN_SECONDS;
	set_transient( $key, array( 'rows' => $rows, 'meta' => $meta, 'at' => sap_now() ), $ttl );
	$recent   = (array) get_option( 'sap_ss_recent_' . $project['id'], array() );
	array_unshift( $recent, array( 'tool' => $tool, 'params' => $params, 'count' => count( $rows ), 'at' => sap_now() ) );
	update_option( 'sap_ss_recent_' . $project['id'], array_slice( $recent, 0, 15 ), false );
	return array( 'rows' => $rows, 'meta' => $meta, 'cached' => false, 'at' => sap_now() );
}

/**
 * AI advice: which SEO Signal queries are worth the quota now, with the exact
 * parameters, so each can be run with one click.
 *
 * @return array|WP_Error
 */
function sap_ss_advise( $project ) {
	if ( ! sap_ai_connected() ) {
		return new WP_Error( 'sap_ai_key', 'کلید هوش مصنوعی وارد نشده است.' );
	}
	$pid   = $project['id'];
	$ctx   = sap_analysis_context( $project );
	$vols  = $ctx['volumes'];
	$no_v  = array();
	foreach ( sap_keymap( $pid ) as $m ) {
		if ( '' !== $m['primary_kw'] && ! isset( $vols[ sap_kw_key( $m['primary_kw'] ) ] ) ) {
			$no_v[] = $m['primary_kw'];
		}
	}
	$signals = array();
	foreach ( sap_findings_active( $pid, 200 ) as $f ) {
		if ( in_array( $f['detector'], array( 'striking', 'kw_unowned', 'rank_drop', 'cl_no_page', 'decay', 'worse_than' ), true ) ) {
			$signals[] = array( $f['detector'], $f['title'], $f['keyword'] );
		}
	}
	$compared = array_keys( (array) ( $ctx['ss_compare']['competitors'] ?? array() ) );
	$input    = array(
		'site'                    => sap_project_domain( $project ),
		'quota_left_today'        => sap_ss_remaining(),
		'focuses'                 => array_map( function ( $f ) {
			return array( 'name' => $f['name'], 'keywords' => array_slice( $f['keywords'], 0, 3 ) );
		}, $ctx['focuses'] ),
		'competitors'             => $project['competitors'],
		'competitors_compared'    => $compared,
		'rank_tracker_connected'  => (bool) $project['ss_project_id'],
		'questions_for_focuses'   => array_keys( (array) ( $ctx['ss_questions']['focuses'] ?? array() ) ),
		'page_keywords_no_volume' => array_slice( $no_v, 0, 60 ),
		'signals'                 => array_slice( $signals, 0, 30 ),
		'ideas_waiting'           => count( sap_ideas( $pid, array( 'status' => 'new' ) ) ),
	);
	$str    = array( 'type' => 'string' );
	$schema = sap_ai_obj( array( 'actions' => array( 'type' => 'array', 'items' => sap_ai_obj( array(
		'tool'     => array( 'type' => 'string', 'enum' => array( 'research', 'bulk', 'compare', 'rt_history' ) ),
		'title'    => $str,
		'why'      => array( 'type' => 'string', 'description' => 'چرا الان و چه خروجی‌ای به کار پروژه می‌آید' ),
		'keyword'  => array( 'type' => 'string', 'description' => 'برای research' ),
		'type'     => array( 'type' => 'string', 'description' => 'برای research: similar یا questions؛ برای compare: gap/worse_than/better_than/common' ),
		'keywords' => array( 'type' => 'array', 'items' => $str, 'description' => 'برای bulk' ),
		'site2'    => array( 'type' => 'string', 'description' => 'برای compare' ),
		'units'    => array( 'type' => 'integer', 'description' => 'واحد سهمیه (research/compare = ۱، bulk = ۱ به ازای هر ۱۰۰۰ کلمه، rt_history = ۰)' ),
		'priority' => array( 'type' => 'string', 'enum' => array( 'high', 'medium', 'low' ) ),
	) ) ) ) );
	$task = implode( "\n", array(
		'تو مدیر سئو هستی و باید سهمیه محدود روزانه API سئو سیگنال را هوشمندانه خرج کنی. ابزارها: research (تحقیق کلمه: کلمات مشابه similar یا سوالات کاربران questions برای یک کلمه)، bulk (حجم جستجوی دسته‌ای کلمات)، compare (مقایسه سایت با رقیب: gap = کلماتی که رقیب دارد و ما نداریم، worse_than = کلماتی که رقیب بهتر است، better_than، common)، rt_history (تاریخچه ردیاب رتبه، رایگان).',
		'با توجه به داده‌های پروژه، ۳ تا ۷ اقدام پیشنهاد بده که بیشترین ارزش را برای تصمیم‌های سئوی همین ماه دارند (مثلاً حجم کلمات اصلی صفحاتی که حجمشان معلوم نیست، سوالات کاربران برای Focus بدون سوال، گپ رقیبی که هنوز مقایسه نشده). از اقدام تکراری یا بی‌اثر پرهیز کن و مجموع واحدها از quota_left_today بیشتر نشود. برای هر اقدام پارامترهای دقیق را پر کن.',
	) );
	$res = sap_ai_request( sap_ai_system_prompt(), $task . "\n\n" . wp_json_encode( $input, JSON_UNESCAPED_UNICODE ), $schema, array( 'purpose' => 'analysis', 'max_tokens' => 2500, 'effort' => 'low' ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$actions = array();
	foreach ( (array) ( $res['actions'] ?? array() ) as $a ) {
		if ( ! is_array( $a ) || ! in_array( $a['tool'] ?? '', array( 'research', 'bulk', 'compare', 'rt_history' ), true ) ) {
			continue;
		}
		$params = array();
		if ( 'research' === $a['tool'] ) {
			$params = array( 'keyword' => (string) ( $a['keyword'] ?? '' ), 'type' => 'questions' === ( $a['type'] ?? '' ) ? 'questions' : 'similar', 'count' => 100 );
		} elseif ( 'bulk' === $a['tool'] ) {
			$params = array( 'keywords' => implode( "\n", array_map( 'strval', (array) ( $a['keywords'] ?? array() ) ) ) );
		} elseif ( 'compare' === $a['tool'] ) {
			$params = array( 'site2' => (string) ( $a['site2'] ?? '' ), 'type' => in_array( $a['type'] ?? '', array( 'gap', 'worse_than', 'better_than', 'common' ), true ) ? $a['type'] : 'gap' );
		} else {
			$params = array( 'project_id' => $project['ss_project_id'], 'days' => 30 );
		}
		$actions[] = array( 'tool' => $a['tool'], 'title' => (string) ( $a['title'] ?? '' ), 'why' => (string) ( $a['why'] ?? '' ), 'params' => $params, 'units' => (int) ( $a['units'] ?? 1 ), 'priority' => (string) ( $a['priority'] ?? 'medium' ) );
	}
	sap_dataset_put( $pid, 'ai_ss', array( 'actions' => $actions ), array( 'model' => sap_ai_model_for( 'analysis' ), 'cost' => sap_ai_usage()['last_cost'] ?? 0 ) );
	return $actions;
}

/**
 * Use SEO Signal results in the project.
 *
 * @param string $action idea (one idea from all items) | ideas (one per item) | secondary (add to a page) | volumes (save).
 * @param array  $items  [{word, volume, competition}].
 */
function sap_ss_use( $project, $action, $items, $url = '' ) {
	$items = array_values( array_filter( array_map( function ( $i ) {
		return is_array( $i ) && '' !== trim( (string) ( $i['word'] ?? '' ) ) ? array( 'word' => trim( (string) $i['word'] ), 'volume' => (int) ( $i['volume'] ?? 0 ), 'competition' => (string) ( $i['competition'] ?? '' ) ) : null;
	}, $items ) ) );
	if ( ! $items ) {
		return new WP_Error( 'sap_ss_use', 'موردی انتخاب نشده.', array( 'status' => 400 ) );
	}
	$pid = $project['id'];
	switch ( $action ) {
		case 'idea':
			$main = array_shift( $items );
			sap_idea_save( array(
				'project_id' => $pid,
				'keyword'    => $main['word'],
				'secondary'  => array_map( function ( $i ) {
					return array( $i['word'], $i['volume'] );
				}, array_slice( $items, 0, 15 ) ),
				'volume'     => $main['volume'],
				'kp'         => $main['volume'],
				'links'      => sap_idea_fill_links( $project, $main['word'], array() ),
				'evidence'   => array( 'تحقیق کلمه سئو سیگنال' ),
				'origin'     => 'ss',
				'status'     => 'new',
			) );
			return array( 'ok' => true, 'msg' => sprintf( 'ایده «%s» با %s کلمه فرعی در تب «ایده‌های محتوا» ساخته شد.', $main['word'], sap_num( count( $items ) ) ) );
		case 'ideas':
			$n = 0;
			foreach ( array_slice( $items, 0, 30 ) as $i ) {
				sap_idea_save( array( 'project_id' => $pid, 'keyword' => $i['word'], 'volume' => $i['volume'], 'kp' => $i['volume'], 'links' => sap_idea_fill_links( $project, $i['word'], array() ), 'evidence' => array( 'سئو سیگنال' ), 'origin' => 'ss', 'status' => 'new' ) );
				++$n;
			}
			return array( 'ok' => true, 'msg' => sprintf( '%s ایده در تب «ایده‌های محتوا» ساخته شد.', sap_num( $n ) ) );
		case 'secondary':
			$m = sap_keymap( $pid )[ sap_norm_url( $url ) ] ?? null;
			if ( ! $m ) {
				return new WP_Error( 'sap_ss_use', 'صفحه را انتخاب کنید.', array( 'status' => 400 ) );
			}
			$sec = array_values( array_unique( array_merge( $m['secondary'], array_column( $items, 'word' ) ) ) );
			sap_keymap_save( $pid, array( 'url' => $m['url'], 'primary_kw' => $m['primary_kw'], 'secondary' => $sec, 'source' => $m['source'], 'confirmed' => $m['confirmed'] ), true );
			return array( 'ok' => true, 'msg' => sprintf( '%s کلمه به کلمات فرعی «%s» اضافه شد.', sap_num( count( $items ) ), $m['title'] ) );
		case 'volumes':
			$ds   = sap_dataset( $pid, 'ss_volumes' );
			$vols = (array) ( $ds['payload']['volumes'] ?? array() );
			foreach ( $items as $i ) {
				$vols[ sap_kw_key( $i['word'] ) ] = $i;
			}
			sap_dataset_put( $pid, 'ss_volumes', array( 'volumes' => $vols ), (array) ( $ds['meta'] ?? array() ) );
			return array( 'ok' => true, 'msg' => sprintf( 'حجم %s کلمه در داده‌های پروژه ذخیره شد.', sap_num( count( $items ) ) ) );
	}
	return new WP_Error( 'sap_ss_use', 'عملیات نامعتبر.', array( 'status' => 400 ) );
}
