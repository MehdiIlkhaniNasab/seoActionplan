<?php
/**
 * Impact of the work: did what we did move the page?
 *
 * Every finished action that has a page (a done task with a URL, a content
 * update or rewrite, a 301 prune into a target page) is measured in Search
 * Console: the 28 days before it against the days after it (a 30-day read
 * from day 3, and a 90-day read once available). Numbers are per day, so an
 * early read of 2 weeks is comparable, and the page's change is shown net of
 * the whole site's change in the same windows (seasonality, updates of Google
 * itself). With the action's keyword, its position before / after too.
 *
 * Verdicts by action type and pillar feed back into the AI action plan: the
 * types of work that grew pages this site get priority, the ones that did not
 * are questioned.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Verdict labels.
 */
function sap_impact_verdicts() {
	return array(
		'growth'  => array( 'رشد', 'good' ),
		'decline' => array( 'افت', 'bad' ),
		'neutral' => array( 'بدون تغییر محسوس', '' ),
		'low'     => array( 'داده کم', '' ),
		'wait'    => array( 'در انتظار داده', '' ),
	);
}

/**
 * Finished actions with a page, newest first.
 *
 * @return array ref => {ref, kind, title, url, keyword, context, pillar, done}
 */
function sap_impact_actions( $project, $days = 200 ) {
	$pid   = $project['id'];
	$since = gmdate( 'Y-m-d', time() - $days * DAY_IN_SECONDS );
	$out   = array();
	$seen  = array();
	foreach ( sap_tasks( array( 'project_id' => $pid, 'status' => 'done' ) ) as $t ) {
		if ( '' === (string) $t['url'] || empty( $t['done_at'] ) || substr( $t['done_at'], 0, 10 ) < $since ) {
			continue;
		}
		$done = substr( $t['done_at'], 0, 10 );
		$seen[ sap_norm_url( $t['url'] ) . '|' . $done ] = true;
		$out[ 'task:' . $t['id'] ] = array( 'ref' => 'task:' . $t['id'], 'kind' => 'task', 'title' => $t['title'], 'url' => $t['url'], 'keyword' => (string) $t['keyword'], 'context' => $t['context'], 'pillar' => sap_pillar_of_context( $t['context'] ), 'done' => $done );
	}
	foreach ( sap_updates( $pid, array( 'status' => 'done' ) ) as $u ) {
		if ( empty( $u['done_at'] ) || substr( $u['done_at'], 0, 10 ) < $since ) {
			continue;
		}
		$done = substr( $u['done_at'], 0, 10 );
		if ( isset( $seen[ sap_norm_url( $u['url'] ) . '|' . $done ] ) ) {
			continue; // Same page and day as a task: one action.
		}
		$out[ 'update:' . $u['id'] ] = array( 'ref' => 'update:' . $u['id'], 'kind' => 'update', 'title' => 'آپدیت محتوای «' . $u['title'] . '»', 'url' => $u['url'], 'keyword' => (string) $u['keyword'], 'context' => 'content_update', 'pillar' => 'content', 'done' => $done );
	}
	uasort( $out, function ( $a, $b ) {
		return strcmp( $b['done'], $a['done'] );
	} );
	return $out;
}

/**
 * Search Console totals of one page (and optionally one keyword) in a window.
 *
 * @return array|WP_Error {clicks, impressions, position, days}
 */
function sap_impact_window( $project, $url, $kw, $start, $end ) {
	$filters = array( array( 'dimension' => 'page', 'operator' => 'equals', 'expression' => $url ) );
	if ( '' !== $kw ) {
		$filters[] = array( 'dimension' => 'query', 'operator' => 'equals', 'expression' => $kw );
	}
	$rows = sap_gsc_query( $project['gsc_property'], $start, $end, array(), 1, array( 'dimensionFilterGroups' => array( array( 'filters' => $filters ) ) ) );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$r = $rows[0] ?? array( 'clicks' => 0, 'impressions' => 0, 'position' => 0 );
	return array( 'clicks' => (int) $r['clicks'], 'impressions' => (int) $r['impressions'], 'position' => $r['impressions'] ? round( (float) $r['position'], 1 ) : null, 'days' => max( 1, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1 ) );
}

/**
 * Whole-site clicks per day in a window (cached for the run).
 */
function sap_impact_site( $project, $start, $end ) {
	static $cache = array();
	$k = $project['id'] . $start . $end;
	if ( ! isset( $cache[ $k ] ) ) {
		$rows        = sap_gsc_query( $project['gsc_property'], $start, $end, array(), 1 );
		$days        = max( 1, (int) round( ( strtotime( $end ) - strtotime( $start ) ) / DAY_IN_SECONDS ) + 1 );
		$cache[ $k ] = is_wp_error( $rows ) ? null : (float) ( $rows[0]['clicks'] ?? 0 ) / $days;
	}
	return $cache[ $k ];
}

/**
 * Measure one action.
 *
 * @return array|WP_Error Measurement.
 */
function sap_impact_measure( $project, $a ) {
	$lag   = 3; // Search Console final data.
	$done  = strtotime( $a['done'] );
	$today = strtotime( gmdate( 'Y-m-d' ) ) - $lag * DAY_IN_SECONDS;
	$b0    = gmdate( 'Y-m-d', $done - 28 * DAY_IN_SECONDS );
	$b1    = gmdate( 'Y-m-d', $done - DAY_IN_SECONDS );
	$a0    = gmdate( 'Y-m-d', $done + 3 * DAY_IN_SECONDS );
	$span  = (int) floor( ( $today - ( $done + 3 * DAY_IN_SECONDS ) ) / DAY_IN_SECONDS ) + 1;
	if ( $span < 10 ) {
		return array( 'verdict' => 'wait', 'after_days' => max( 0, $span ), 'measured_at' => sap_now() );
	}
	$d30   = min( 28, $span );
	$a1    = gmdate( 'Y-m-d', $done + ( 2 + $d30 ) * DAY_IN_SECONDS );
	$kw    = trim( (string) $a['keyword'] );
	$url   = $a['url'];
	$bef   = sap_impact_window( $project, $url, '', $b0, $b1 );
	$aft   = sap_impact_window( $project, $url, '', $a0, $a1 );
	if ( is_wp_error( $bef ) || is_wp_error( $aft ) ) {
		return is_wp_error( $bef ) ? $bef : $aft;
	}
	$out = array( 'before' => $bef, 'after' => $aft, 'after_days' => $d30, 'early' => $d30 < 28, 'measured_at' => sap_now() );
	if ( $span >= 90 ) {
		$out['after90'] = sap_impact_window( $project, $url, '', $a0, gmdate( 'Y-m-d', $done + 92 * DAY_IN_SECONDS ) );
		if ( is_wp_error( $out['after90'] ) ) {
			unset( $out['after90'] );
		}
	}
	if ( '' !== $kw ) {
		$kb = sap_impact_window( $project, $url, $kw, $b0, $b1 );
		$ka = sap_impact_window( $project, $url, $kw, $a0, $a1 );
		if ( ! is_wp_error( $kb ) && ! is_wp_error( $ka ) ) {
			$out['kw_before'] = $kb['position'];
			$out['kw_after']  = $ka['position'];
		}
	}
	// Per day, and net of the whole site's change.
	$cb = $bef['clicks'] / $bef['days'];
	$ca = $aft['clicks'] / $aft['days'];
	$out['clicks_change'] = $cb > 0 ? round( ( $ca - $cb ) / $cb * 100 ) : ( $ca > 0 ? 100 : 0 );
	$ib = $bef['impressions'] / $bef['days'];
	$ia = $aft['impressions'] / $aft['days'];
	$out['impr_change'] = $ib > 0 ? round( ( $ia - $ib ) / $ib * 100 ) : ( $ia > 0 ? 100 : 0 );
	$sb = sap_impact_site( $project, $b0, $b1 );
	$sa = sap_impact_site( $project, $a0, $a1 );
	$out['site_change'] = $sb ? round( ( $sa - $sb ) / $sb * 100 ) : null;
	$out['net_change']  = null === $out['site_change'] ? $out['clicks_change'] : $out['clicks_change'] - $out['site_change'];
	$pos_gain           = ( null !== $bef['position'] && null !== $aft['position'] ) ? round( $bef['position'] - $aft['position'], 1 ) : null;
	if ( isset( $out['kw_before'], $out['kw_after'] ) && null !== $out['kw_before'] && null !== $out['kw_after'] ) {
		$pos_gain = round( $out['kw_before'] - $out['kw_after'], 1 );
	}
	$out['pos_gain'] = $pos_gain;
	if ( $bef['impressions'] < 20 && $aft['impressions'] < 20 ) {
		$out['verdict'] = 'low';
	} elseif ( ( $out['net_change'] >= 15 && $aft['clicks'] >= 3 ) || ( null !== $pos_gain && $pos_gain >= 2 && $aft['impressions'] >= 20 ) ) {
		$out['verdict'] = 'growth';
	} elseif ( ( $out['net_change'] <= -15 && $bef['clicks'] >= 3 ) || ( null !== $pos_gain && $pos_gain <= -2 ) ) {
		$out['verdict'] = 'decline';
	} else {
		$out['verdict'] = 'neutral';
	}
	return $out;
}

/**
 * Sync step: measure finished actions (a few per call; re-read weekly until the
 * 30-day read is complete, and once more at 90 days).
 */
function sap_step_impact( $project, $force = false ) {
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return sap_skip( 'سرچ کنسول متصل نیست؛ اثر اقدامات سنجیده نمی‌شود.' );
	}
	$pid     = $project['id'];
	$data    = sap_dataset_payload( $pid, 'impact' ) ?: array( 'items' => array() );
	$items   = (array) ( $data['items'] ?? array() );
	$actions = sap_impact_actions( $project );
	$todo    = array();
	foreach ( $actions as $ref => $a ) {
		$old = $items[ $ref ] ?? null;
		$age = $old ? ( time() - strtotime( $old['measured_at'] ) ) / DAY_IN_SECONDS : 999;
		$need = ! $old
			|| ( 'wait' === $old['verdict'] && $age >= 2 )
			|| ( ! empty( $old['early'] ) && $age >= 7 )
			|| ( empty( $old['after90'] ) && ( time() - strtotime( $a['done'] ) ) / DAY_IN_SECONDS >= 93 && $age >= 7 )
			|| ( $force && $age >= 1 );
		if ( $need ) {
			$todo[ $ref ] = $a;
		}
	}
	$n = 0;
	$t0 = microtime( true );
	foreach ( $todo as $ref => $a ) {
		if ( $n >= 8 || microtime( true ) - $t0 > 25 ) {
			break;
		}
		$m = sap_impact_measure( $project, $a );
		if ( is_wp_error( $m ) ) {
			return $n ? array( 'more' => false, 'msg' => sprintf( 'اثر %s اقدام سنجیده شد؛ بقیه: %s', sap_num( $n ), $m->get_error_message() ) ) : $m;
		}
		$items[ $ref ] = array_merge( $a, $m );
		++$n;
	}
	// Forget actions that are gone (task deleted / reopened).
	$items = array_intersect_key( $items, $actions );
	sap_dataset_put( $pid, 'impact', array( 'items' => $items ), array( 'count' => count( $items ) ) );
	if ( count( $todo ) > $n ) {
		return array( 'more' => true, 'msg' => sprintf( 'سنجش اثر اقدامات: %s از %s…', sap_num( $n ), sap_num( count( $todo ) ) ) );
	}
	if ( ! $actions ) {
		return sap_skip( 'هنوز اقدام انجام‌شده‌ای با آدرس صفحه نیست که اثرش سنجیده شود.' );
	}
	$s = sap_impact_summary( $pid );
	return array( 'msg' => sprintf( 'اثر %s اقدام: %s رشد، %s افت، %s بدون تغییر، %s در انتظار داده.', sap_num( $s['total'] ), sap_num( $s['growth'] ), sap_num( $s['decline'] ), sap_num( $s['neutral'] ), sap_num( $s['wait'] ) ) );
}

/**
 * Measured actions, optionally of one period (by the month they were done in).
 */
function sap_impact_items( $project_id, $period = '' ) {
	$items = (array) ( sap_dataset_payload( $project_id, 'impact' )['items'] ?? array() );
	if ( $period ) {
		list( $from, $to ) = sap_period_range( $period );
		$items = array_filter( $items, function ( $i ) use ( $from, $to ) {
			return $i['done'] >= $from && $i['done'] <= $to;
		} );
	}
	return $items;
}

/**
 * Totals, by pillar and by type of work, best and worst actions, lessons.
 */
function sap_impact_summary( $project_id, $period = '' ) {
	$items = sap_impact_items( $project_id, $period );
	$s     = array( 'total' => count( $items ), 'growth' => 0, 'decline' => 0, 'neutral' => 0, 'low' => 0, 'wait' => 0, 'clicks_before' => 0, 'clicks_after' => 0, 'by_pillar' => array(), 'by_type' => array(), 'best' => array(), 'worst' => array(), 'lessons' => array() );
	$rated = array();
	foreach ( $items as $i ) {
		$v = $i['verdict'] ?? 'wait';
		$s[ $v ] = ( $s[ $v ] ?? 0 ) + 1;
		$p = $i['pillar'] ?? 'content';
		$s['by_pillar'][ $p ] = $s['by_pillar'][ $p ] ?? array( 'total' => 0, 'growth' => 0, 'decline' => 0, 'net' => array() );
		++$s['by_pillar'][ $p ]['total'];
		if ( in_array( $v, array( 'growth', 'decline' ), true ) ) {
			++$s['by_pillar'][ $p ][ $v ];
		}
		if ( ! in_array( $v, array( 'growth', 'decline', 'neutral' ), true ) ) {
			continue;
		}
		$rated[]               = $i;
		$s['clicks_before']   += (int) round( $i['before']['clicks'] / $i['before']['days'] * 28 );
		$s['clicks_after']    += (int) round( $i['after']['clicks'] / $i['after']['days'] * 28 );
		$s['by_pillar'][ $p ]['net'][] = (int) $i['net_change'];
		$t = $i['context'];
		$s['by_type'][ $t ] = $s['by_type'][ $t ] ?? array( 'label' => sap_context_label( $t ), 'n' => 0, 'growth' => 0, 'decline' => 0, 'net' => array() );
		++$s['by_type'][ $t ]['n'];
		if ( 'neutral' !== $v ) {
			++$s['by_type'][ $t ][ $v ];
		}
		$s['by_type'][ $t ]['net'][] = (int) $i['net_change'];
	}
	foreach ( $s['by_pillar'] as &$bp ) {
		$bp['avg'] = $bp['net'] ? (int) round( array_sum( $bp['net'] ) / count( $bp['net'] ) ) : null;
		unset( $bp['net'] );
	}
	unset( $bp );
	foreach ( $s['by_type'] as &$bt ) {
		$bt['avg'] = $bt['net'] ? (int) round( array_sum( $bt['net'] ) / count( $bt['net'] ) ) : null;
		unset( $bt['net'] );
	}
	unset( $bt );
	uasort( $s['by_type'], function ( $a, $b ) {
		return ( $b['avg'] ?? 0 ) <=> ( $a['avg'] ?? 0 );
	} );
	usort( $rated, function ( $a, $b ) {
		return $b['net_change'] <=> $a['net_change'];
	} );
	$s['best']  = array_slice( array_values( array_filter( $rated, function ( $i ) {
		return 'growth' === $i['verdict'];
	} ) ), 0, 5 );
	$s['worst'] = array_slice( array_reverse( array_values( array_filter( $rated, function ( $i ) {
		return 'decline' === $i['verdict'];
	} ) ) ), 0, 5 );
	// Lessons: types of work with at least 2 rated actions.
	foreach ( $s['by_type'] as $bt ) {
		if ( $bt['n'] < 2 || null === $bt['avg'] ) {
			continue;
		}
		if ( $bt['avg'] >= 15 ) {
			$s['lessons'][] = sprintf( '«%s» روی این سایت جواب داده: %s اقدام، میانگین %s٪ رشد خالص کلیک (%s رشد، %s افت). از این نوع کار بیشتر برنامه‌ریزی کنید.', $bt['label'], sap_num( $bt['n'] ), sap_num( $bt['avg'] ), sap_num( $bt['growth'] ), sap_num( $bt['decline'] ) );
		} elseif ( $bt['avg'] <= -10 ) {
			$s['lessons'][] = sprintf( '«%s» نتیجه نداده: %s اقدام، میانگین %s٪ تغییر خالص کلیک. روش اجرا را بازبینی کنید (کیفیت، انتخاب صفحه، کلمه هدف).', $bt['label'], sap_num( $bt['n'] ), sap_num( $bt['avg'] ) );
		} else {
			$s['lessons'][] = sprintf( '«%s»: %s اقدام، اثر خالص متوسط %s٪؛ هنوز نتیجه قطعی ندارد.', $bt['label'], sap_num( $bt['n'] ), sap_num( $bt['avg'] ) );
		}
	}
	return $s;
}

/**
 * Short feedback lines for the AI action plan.
 *
 * @return string[]
 */
function sap_impact_feedback( $project_id ) {
	$s = sap_impact_summary( $project_id );
	if ( ! ( $s['growth'] + $s['decline'] + $s['neutral'] ) ) {
		return array();
	}
	$out = $s['lessons'];
	foreach ( $s['worst'] as $w ) {
		$out[] = sprintf( 'افت بعد از «%s» (%s، %s٪ خالص)؛ بررسی شود.', $w['title'], sap_short_url( $w['url'], 60 ), sap_num( $w['net_change'] ) );
	}
	return array_slice( $out, 0, 10 );
}

/**
 * This week at a glance: done in the last 7 days, overdue, due in the next 7,
 * by Focus.
 *
 * @return array{done:array, overdue:array, due:array, by_focus:array}
 */
function sap_week_digest( $project ) {
	$pid   = $project['id'];
	$today = current_time( 'Y-m-d' );
	$ago   = gmdate( 'Y-m-d', strtotime( $today ) - 7 * DAY_IN_SECONDS );
	$ahead = gmdate( 'Y-m-d', strtotime( $today ) + 7 * DAY_IN_SECONDS );
	$names = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
	$out   = array( 'done' => array(), 'overdue' => array(), 'due' => array(), 'by_focus' => array() );
	$cur   = sap_period_current();
	foreach ( array_merge( sap_tasks( array( 'project_id' => $pid, 'period' => $cur ) ), sap_tasks( array( 'project_id' => $pid, 'period' => sap_period_shift( $cur, -1 ), 'status' => 'done' ) ) ) as $t ) {
		$f = $names[ $t['focus_id'] ] ?? 'بدون Focus';
		$out['by_focus'][ $f ] = $out['by_focus'][ $f ] ?? array( 'done' => 0, 'overdue' => 0, 'hours' => 0 );
		if ( 'done' === $t['status'] && ! empty( $t['done_at'] ) && substr( $t['done_at'], 0, 10 ) >= $ago ) {
			$out['done'][] = $t;
			++$out['by_focus'][ $f ]['done'];
			$out['by_focus'][ $f ]['hours'] += (float) $t['effort'];
		} elseif ( ! in_array( $t['status'], array( 'done', 'dropped', 'someday' ), true ) && $t['due_date'] ) {
			if ( $t['due_date'] < $today ) {
				$out['overdue'][] = $t;
				++$out['by_focus'][ $f ]['overdue'];
			} elseif ( $t['due_date'] <= $ahead ) {
				$out['due'][] = $t;
			}
		}
	}
	$out['by_focus'] = array_filter( $out['by_focus'], function ( $x ) {
		return $x['done'] || $x['overdue'];
	} );
	return $out;
}
