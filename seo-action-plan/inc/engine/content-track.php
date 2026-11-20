<?php
/**
 * Content results: what a new article and an update actually brought.
 *
 *  - New content: from its publish date — impressions, clicks, average position of
 *    the page and of its main keyword in Search Console.
 *  - Update: 28 days before the update against the days after it (per 28 days,
 *    so a fresh update is comparable) — clicks, impressions, position.
 *
 * Measured once a day (and on demand) for the calendar's items; a task finished
 * by the team also finishes its calendar item.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stored results of a project: «i{id}» for ideas, «u{id}» for updates.
 */
function sap_track_all( $project_id ) {
	$t = get_option( 'sap_track_' . (int) $project_id, array() );
	return is_array( $t ) ? $t : array();
}

/**
 * Remember the day a calendar article was published.
 */
function sap_track_published( $project_id, $idea_id, $date = '' ) {
	$all                      = sap_track_all( $project_id );
	$all[ 'i' . $idea_id ]    = array_merge( (array) ( $all[ 'i' . $idea_id ] ?? array() ), array( 'pub' => $date ?: current_time( 'Y-m-d' ) ) );
	update_option( 'sap_track_' . (int) $project_id, $all, false );
}

/**
 * Last day Search Console has final data for.
 */
function sap_track_last_day() {
	return gmdate( 'Y-m-d', strtotime( current_time( 'Y-m-d' ) . ' -3 days' ) );
}

/**
 * Measure one new article.
 */
function sap_track_new( $project, $idea, $prev = array() ) {
	$pub  = (string) ( $idea['published_at'] ?? '' ) ?: ( (string) ( $prev['pub'] ?? '' ) ?: ( $idea['planned_date'] ?: substr( (string) $idea['updated_at'], 0, 10 ) ) );
	$last = sap_track_last_day();
	$out  = array( 'pub' => $pub, 'at' => sap_now() );
	if ( $pub > $last ) {
		$out['wait'] = (int) ceil( ( strtotime( $pub ) - strtotime( $last ) ) / DAY_IN_SECONDS ) + 1;
		return $out;
	}
	$w = sap_impact_window( $project, $idea['published_url'], '', $pub, $last );
	if ( is_wp_error( $w ) ) {
		return $prev + array( 'err' => $w->get_error_message() );
	}
	$out += array( 'days' => $w['days'], 'clicks' => $w['clicks'], 'impr' => $w['impressions'], 'pos' => $w['position'] );
	if ( '' !== $idea['keyword'] ) {
		$k = sap_impact_window( $project, $idea['published_url'], $idea['keyword'], $pub, $last );
		if ( ! is_wp_error( $k ) ) {
			$out['kw_pos'] = $k['position'];
		}
	}
	return $out;
}

/**
 * Measure one update: 28 days before against the days after (scaled to 28).
 */
function sap_track_update( $project, $u ) {
	$done = substr( (string) $u['done_at'], 0, 10 );
	$last = sap_track_last_day();
	$out  = array( 'done' => $done, 'at' => sap_now() );
	$a0   = gmdate( 'Y-m-d', strtotime( $done . ' +1 day' ) );
	$a1   = min( $last, gmdate( 'Y-m-d', strtotime( $done . ' +28 days' ) ) );
	$days = (int) round( ( strtotime( $a1 ) - strtotime( $a0 ) ) / DAY_IN_SECONDS ) + 1;
	if ( $days < 5 ) {
		$out['wait'] = 5 - max( 0, $days );
		return $out;
	}
	$b = sap_impact_window( $project, $u['url'], '', gmdate( 'Y-m-d', strtotime( $done . ' -28 days' ) ), gmdate( 'Y-m-d', strtotime( $done . ' -1 day' ) ) );
	$a = is_wp_error( $b ) ? $b : sap_impact_window( $project, $u['url'], '', $a0, $a1 );
	if ( is_wp_error( $a ) ) {
		return array( 'err' => $a->get_error_message() ) + $out;
	}
	$scale = 28 / max( 1, $a['days'] );
	$out  += array(
		'days'       => $a['days'],
		'before'     => array( 'clicks' => $b['clicks'], 'impr' => $b['impressions'], 'pos' => $b['position'] ),
		'after'      => array( 'clicks' => (int) round( $a['clicks'] * $scale ), 'impr' => (int) round( $a['impressions'] * $scale ), 'pos' => $a['position'] ),
	);
	return $out;
}

/**
 * Measure the calendar items of a period (and keep the rest).
 *
 * @return int Items measured.
 */
function sap_track_refresh( $project, $period, $force = false ) {
	if ( '' === (string) $project['gsc_property'] || ! sap_google_connected() ) {
		return 0;
	}
	$pid   = $project['id'];
	$all   = sap_track_all( $pid );
	$n     = 0;
	$fresh = function ( $r ) use ( $force ) {
		return ! $force && ! empty( $r['at'] ) && substr( $r['at'], 0, 10 ) === current_time( 'Y-m-d' ) && empty( $r['err'] );
	};
	foreach ( sap_calendar_items( $pid, $period ) as $it ) {
		$key = 'i' . $it['id'];
		if ( 'published' !== $it['status'] || '' === (string) $it['published_url'] || $fresh( $all[ $key ] ?? array() ) || $n >= 30 ) {
			continue;
		}
		$all[ $key ] = sap_track_new( $project, $it, (array) ( $all[ $key ] ?? array() ) );
		++$n;
	}
	foreach ( sap_updates( $pid, array( 'period' => $period, 'status' => array( 'done' ) ) ) as $u ) {
		$key = 'u' . $u['id'];
		if ( ! $u['done_at'] || $fresh( $all[ $key ] ?? array() ) || $n >= 30 ) {
			continue;
		}
		$all[ $key ] = sap_track_update( $project, $u );
		++$n;
	}
	update_option( 'sap_track_' . $pid, $all, false );
	return $n;
}

/**
 * One line of result for the calendar.
 *
 * @return array {text, tone}
 */
function sap_track_line( $r, $kind ) {
	if ( ! $r ) {
		return array( 'text' => '', 'tone' => '' );
	}
	if ( ! empty( $r['err'] ) ) {
		return array( 'text' => 'سنجش نشد: ' . $r['err'], 'tone' => 'is-warn' );
	}
	if ( ! empty( $r['wait'] ) ) {
		return array( 'text' => sprintf( 'منتظر داده سرچ کنسول (حدود %s روز دیگر)', sap_num( $r['wait'] ) ), 'tone' => '' );
	}
	if ( 'new' === $kind ) {
		if ( ! isset( $r['impr'] ) ) {
			return array( 'text' => '', 'tone' => '' );
		}
		$t = sprintf( '%s روز از انتشار: %s نمایش · %s کلیک', sap_num( $r['days'] ), sap_num( $r['impr'] ), sap_num( $r['clicks'] ) );
		if ( isset( $r['kw_pos'] ) && null !== $r['kw_pos'] ) {
			$t .= ' · جایگاه کلمه اصلی ' . sap_num( $r['kw_pos'], 1 );
		}
		return array( 'text' => $t, 'tone' => $r['impr'] ? ( $r['clicks'] ? 'is-good' : 'is-info' ) : 'is-warn' );
	}
	if ( ! isset( $r['before'] ) ) {
		return array( 'text' => '', 'tone' => '' );
	}
	$b  = $r['before'];
	$a  = $r['after'];
	$pc = $b['clicks'] ? round( 100 * ( $a['clicks'] - $b['clicks'] ) / $b['clicks'] ) : null;
	$t  = sprintf( 'کلیک ۲۸ روزه %s → %s%s', sap_num( $b['clicks'] ), sap_num( $a['clicks'] ), null !== $pc ? ' (' . ( $pc > 0 ? '+' : '' ) . sap_num( $pc ) . '٪)' : '' );
	if ( $b['pos'] && $a['pos'] ) {
		$t .= ' · جایگاه ' . sap_num( $b['pos'], 1 ) . ' → ' . sap_num( $a['pos'], 1 );
	}
	$t .= sprintf( ' · %s روز بعد از آپدیت', sap_num( $r['days'] ) );
	return array( 'text' => $t, 'tone' => $a['clicks'] > $b['clicks'] * 1.05 ? 'is-good' : ( $a['clicks'] < $b['clicks'] * 0.9 ? 'is-bad' : '' ) );
}

/**
 * A finished task finishes its calendar item: an update is done; an article is
 * published when the task carries the published link.
 */
add_action( 'sap_task_saved', function ( $before, $after ) {
	if ( ! $after || 'done' !== $after['status'] || ( $before && 'done' === $before['status'] ) ) {
		return;
	}
	global $wpdb;
	$uid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'updates' ) . " WHERE task_id = %d AND status <> 'done'", $after['id'] ) ); // phpcs:ignore
	if ( $uid ) {
		sap_update_save( array( 'status' => 'done' ), $uid );
	}
	$iid = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . sap_table( 'ideas' ) . " WHERE task_id = %d AND status = 'scheduled'", $after['id'] ) ); // phpcs:ignore
	if ( $iid && preg_match( '#https?://\S+#u', (string) $after['result'] . ' ' . (string) $after['url'], $m ) ) {
		sap_idea_save( array( 'status' => 'published', 'published_url' => esc_url_raw( rtrim( $m[0], '.,)،' ) ) ), $iid );
		sap_track_published( $after['project_id'], $iid );
	}
}, 10, 2 );

/**
 * Once a day (after the daily-check hour): measure this and last period's items.
 */
add_action( 'sap_hourly', function () {
	if ( (int) current_time( 'G' ) < (int) sap_setting( 'alert_hour', 9 ) ) {
		return;
	}
	foreach ( sap_projects() as $p ) {
		if ( ! sap_project_is_seo( $p ) || get_option( 'sap_track_day_' . $p['id'] ) === current_time( 'Y-m-d' ) ) {
			continue;
		}
		update_option( 'sap_track_day_' . $p['id'], current_time( 'Y-m-d' ), false );
		$cur = sap_period_current( $p['id'] );
		sap_track_refresh( $p, $cur );
		sap_track_refresh( $p, sap_period_shift( $cur, -1 ) );
	}
} );

add_action( 'rest_api_init', function () {
	register_rest_route( 'seoactionplan/v1', '/track/(?P<project>\d+)', array(
		'methods'             => 'POST',
		'permission_callback' => 'sap_can',
		'callback'            => function ( WP_REST_Request $req ) {
			$p = sap_project( (int) $req['project'] );
			if ( ! $p ) {
				return new WP_Error( 'sap_track', 'پروژه پیدا نشد.', array( 'status' => 404 ) );
			}
			if ( '' === (string) $p['gsc_property'] || ! sap_google_connected() ) {
				return new WP_Error( 'sap_track', 'برای سنجش نتیجه، سرچ کنسول باید وصل باشد.' );
			}
			sap_period_project( $p['id'] );
			$n = sap_track_refresh( $p, sap_period_sanitize( (string) $req->get_param( 'period' ) ), true );
			return array( 'ok' => true, 'msg' => $n ? sprintf( 'نتیجه %s مورد از سرچ کنسول به‌روز شد.', sap_num( $n ) ) : 'موردی برای سنجش نیست (مقاله منتشرشده با لینک یا آپدیت انجام‌شده).' );
		},
	) );
} );
