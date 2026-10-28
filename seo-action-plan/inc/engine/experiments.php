<?php
/**
 * SEO experiments: change a group of test pages, keep similar control pages
 * unchanged, and measure the difference in Search Console (difference in
 * differences against the control group, with a significance check on the
 * daily series). Seasonality and Google updates hit both groups, so what is
 * left is the effect of the change.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

function sap_exp_metrics() {
	return array( 'clicks' => 'کلیک', 'impressions' => 'نمایش', 'ctr' => 'CTR', 'position' => 'جایگاه' );
}

/**
 * Experiments of a project, newest first.
 */
function sap_experiments( $project_id ) {
	$items = (array) ( sap_dataset_payload( $project_id, 'experiments' )['items'] ?? array() );
	uasort( $items, function ( $a, $b ) {
		return strcmp( $b['created'], $a['created'] );
	} );
	return $items;
}

function sap_experiments_save( $project_id, $items ) {
	sap_dataset_put( $project_id, 'experiments', array( 'items' => $items ), array( 'count' => count( $items ) ) );
}

/**
 * Suggest control pages: same page type, similar impressions, not in the test group.
 */
function sap_exp_suggest_control( $project, $test, $n = 0 ) {
	$n     = $n ?: max( 3, count( $test ) );
	$cur   = (array) ( sap_dataset_payload( $project['id'], 'gsc_pages' )['cur'] ?? array() );
	$by    = array();
	foreach ( $cur as $r ) {
		$by[ sap_norm_url( $r['page'] ) ] = $r;
	}
	$tset  = array_flip( array_map( 'sap_norm_url', $test ) );
	$types = array();
	$impr  = array();
	foreach ( $test as $u ) {
		$types[ sap_learning_page_type( $project['id'], $u ) ] = true;
		$impr[] = (int) ( $by[ sap_norm_url( $u ) ]['impressions'] ?? 0 );
	}
	$target = $impr ? array_sum( $impr ) / count( $impr ) : 0;
	$cands  = array();
	foreach ( $cur as $r ) {
		$k = sap_norm_url( $r['page'] );
		if ( isset( $tset[ $k ] ) || $r['impressions'] < 30 ) {
			continue;
		}
		// Same page type first, then the closest traffic level.
		$same    = isset( $types[ sap_learning_page_type( $project['id'], $r['page'] ) ] );
		$cands[] = array( 'url' => $r['page'], 'd' => ( $same ? 0 : 10 ) + abs( log( max( 1, $r['impressions'] ) ) - log( max( 1, $target ) ) ) );
	}
	usort( $cands, function ( $a, $b ) {
		return $a['d'] <=> $b['d'];
	} );
	return array_column( array_slice( $cands, 0, $n ), 'url' );
}

/**
 * Create an experiment.
 */
function sap_exp_create( $project, $d ) {
	$test = array_values( array_unique( array_filter( array_map( function ( $u ) use ( $project ) {
		return sap_full_url( $project, esc_url_raw( trim( $u ) ) );
	}, (array) $d['test'] ) ) ) );
	if ( count( $test ) < 1 ) {
		return new WP_Error( 'sap_exp', 'حداقل یک صفحه تست لازم است.' );
	}
	$control = array_values( array_unique( array_filter( array_map( function ( $u ) use ( $project ) {
		return sap_full_url( $project, esc_url_raw( trim( $u ) ) );
	}, (array) $d['control'] ) ) ) );
	if ( ! $control ) {
		$control = sap_exp_suggest_control( $project, $test );
	}
	$control = array_values( array_diff( $control, $test ) );
	if ( ! $control ) {
		return new WP_Error( 'sap_exp', 'صفحه کنترل مشابهی پیدا نشد؛ چند صفحه کنترل را دستی وارد کنید.' );
	}
	$name = trim( sanitize_text_field( (string) $d['name'] ) );
	if ( '' === $name ) {
		return new WP_Error( 'sap_exp', 'نام آزمایش را بنویسید.' );
	}
	$id    = 'e' . strtolower( wp_generate_password( 8, false ) );
	$items = sap_experiments( $project['id'] );
	$items[ $id ] = array(
		'id'         => $id,
		'name'       => $name,
		'hypothesis' => sanitize_textarea_field( (string) $d['hypothesis'] ),
		'change'     => sanitize_textarea_field( (string) $d['change'] ),
		'metric'     => array_key_exists( $d['metric'] ?? '', sap_exp_metrics() ) ? $d['metric'] : 'clicks',
		'test'       => array_slice( $test, 0, 25 ),
		'control'    => array_slice( $control, 0, 25 ),
		'start'      => preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $d['start'] ) ? $d['start'] : current_time( 'Y-m-d' ),
		'days'       => max( 14, min( 90, (int) ( $d['days'] ?? 28 ) ) ),
		'status'     => 'running',
		'created'    => sap_now(),
		'user'       => wp_get_current_user()->display_name,
		'result'     => null,
	);
	sap_experiments_save( $project['id'], $items );
	sap_memory_add( $project['id'], array( 'kind' => 'decision', 'title' => 'آزمایش «' . $name . '» شروع شد', 'body' => trim( $items[ $id ]['hypothesis'] . "\nتغییر: " . $items[ $id ]['change'] ), 'source' => 'experiment', 'ref' => 'exp:' . $id ) );
	return $items[ $id ];
}

/**
 * Daily Search Console series of a group of pages.
 *
 * @return array|WP_Error day => {clicks, impressions, position}
 */
function sap_exp_series( $project, $urls, $start, $end ) {
	$re   = implode( '|', array_map( function ( $u ) {
		return '^' . preg_quote( $u, '/' ) . '$';
	}, $urls ) );
	$rows = sap_gsc_query( $project['gsc_property'], $start, $end, array( 'date' ), 1000, array( 'dimensionFilterGroups' => array( array( 'filters' => array( array( 'dimension' => 'page', 'operator' => 'includingRegex', 'expression' => $re ) ) ) ) ) );
	if ( is_wp_error( $rows ) ) {
		return $rows;
	}
	$out = array();
	foreach ( $rows as $r ) {
		$out[ $r['date'] ] = array( 'clicks' => (float) $r['clicks'], 'impressions' => (float) $r['impressions'], 'position' => (float) $r['position'] );
	}
	ksort( $out );
	return $out;
}

/**
 * Value of a metric on a day (CTR in %, position as is).
 */
function sap_exp_value( $d, $metric ) {
	if ( ! $d ) {
		return 0.0;
	}
	if ( 'ctr' === $metric ) {
		return $d['impressions'] ? $d['clicks'] / $d['impressions'] * 100 : 0.0;
	}
	return (float) $d[ $metric ];
}

/**
 * Measure an experiment: pre 28 days vs post (from start + 3 days).
 *
 * @return array|WP_Error result
 */
function sap_exp_measure( $project, $id ) {
	$items = sap_experiments( $project['id'] );
	$e     = $items[ $id ] ?? null;
	if ( ! $e ) {
		return new WP_Error( 'sap_exp', 'آزمایش پیدا نشد.' );
	}
	if ( '' === $project['gsc_property'] || ! sap_google_connected() ) {
		return new WP_Error( 'sap_gsc', 'سرچ کنسول به این پروژه متصل نیست.' );
	}
	$s0    = strtotime( $e['start'] );
	$pre0  = gmdate( 'Y-m-d', $s0 - 28 * DAY_IN_SECONDS );
	$pre1  = gmdate( 'Y-m-d', $s0 - DAY_IN_SECONDS );
	$post0 = gmdate( 'Y-m-d', $s0 + 3 * DAY_IN_SECONDS );
	$last  = min( time() - 3 * DAY_IN_SECONDS, $s0 + ( 3 + $e['days'] ) * DAY_IN_SECONDS );
	$post1 = gmdate( 'Y-m-d', $last );
	$t     = sap_exp_series( $project, $e['test'], $pre0, $post1 );
	$c     = sap_exp_series( $project, $e['control'], $pre0, $post1 );
	if ( is_wp_error( $t ) || is_wp_error( $c ) ) {
		return is_wp_error( $t ) ? $t : $c;
	}
	$m     = $e['metric'];
	$days  = array();
	for ( $ts = strtotime( $pre0 ); $ts <= strtotime( $post1 ); $ts += DAY_IN_SECONDS ) {
		$days[] = gmdate( 'Y-m-d', $ts );
	}
	$pre  = array_filter( $days, function ( $d ) use ( $pre1 ) {
		return $d <= $pre1;
	} );
	$post = array_filter( $days, function ( $d ) use ( $post0 ) {
		return $d >= $post0;
	} );
	$avg  = function ( $series, $set ) use ( $m ) {
		$v = array_map( function ( $d ) use ( $series, $m ) {
			return sap_exp_value( $series[ $d ] ?? null, $m );
		}, $set );
		return $v ? array_sum( $v ) / count( $v ) : 0.0;
	};
	$tp = $avg( $t, $pre );
	$cp = $avg( $c, $pre );
	$res = array( 'measured' => sap_now(), 'post_days' => count( $post ), 'test_pre' => round( $tp, 2 ), 'control_pre' => round( $cp, 2 ), 'series' => array() );
	// The control scaled to the test group's pre-period level (the counterfactual).
	$k = $cp > 0 ? $tp / $cp : 1;
	foreach ( $days as $d ) {
		$res['series'][ $d ] = array( round( sap_exp_value( $t[ $d ] ?? null, $m ), 2 ), round( sap_exp_value( $c[ $d ] ?? null, $m ) * $k, 2 ) );
	}
	if ( count( $post ) < 7 ) {
		$res['verdict'] = 'wait';
		$items[ $id ]['result'] = $res;
		sap_experiments_save( $project['id'], $items );
		return $res;
	}
	$tq = $avg( $t, $post );
	$cq = $avg( $c, $post );
	$res['test_post']    = round( $tq, 2 );
	$res['control_post'] = round( $cq, 2 );
	$lower_better        = 'position' === $m;
	// Relative lift vs the counterfactual (position: positions gained).
	if ( $lower_better ) {
		$res['lift'] = round( ( $tp - $tq ) - ( $cp - $cq ), 2 );
	} else {
		$exp         = $cp > 0 ? $tp * ( $cq / $cp ) : $tp;
		$res['lift'] = $exp > 0 ? round( ( $tq / $exp - 1 ) * 100, 1 ) : 0;
	}
	// Welch t on the daily gap (test − scaled control), pre vs post.
	$gap = function ( $set ) use ( $res ) {
		return array_values( array_map( function ( $d ) use ( $res ) {
			return $res['series'][ $d ][0] - $res['series'][ $d ][1];
		}, $set ) );
	};
	$a    = $gap( $pre );
	$b    = $gap( $post );
	$mean = function ( $x ) {
		return array_sum( $x ) / max( 1, count( $x ) );
	};
	$var  = function ( $x ) use ( $mean ) {
		$m = $mean( $x );
		return count( $x ) > 1 ? array_sum( array_map( function ( $v ) use ( $m ) {
			return ( $v - $m ) ** 2;
		}, $x ) ) / ( count( $x ) - 1 ) : 0;
	};
	$se          = sqrt( $var( $a ) / max( 1, count( $a ) ) + $var( $b ) / max( 1, count( $b ) ) );
	$tval        = $se > 0 ? ( $mean( $b ) - $mean( $a ) ) / $se : 0;
	$tval        = $lower_better ? -$tval : $tval;
	$res['t']    = round( $tval, 2 );
	$res['conf'] = $tval >= 2.6 ? 99 : ( $tval >= 1.96 ? 95 : ( $tval >= 1.64 ? 90 : ( $tval <= -1.96 ? -95 : 0 ) ) );
	$done        = count( $post ) >= $e['days'];
	if ( abs( $tval ) >= 1.96 ) {
		$res['verdict'] = $tval > 0 ? 'win' : 'loss';
	} else {
		$res['verdict'] = $done ? 'flat' : 'running';
	}
	$items[ $id ]['result'] = $res;
	if ( $done && 'running' === $items[ $id ]['status'] ) {
		$items[ $id ]['status'] = 'done';
		$labels = array( 'win' => 'موفق بود', 'loss' => 'اثر منفی داشت', 'flat' => 'اثر معناداری نداشت' );
		sap_memory_add( $project['id'], array( 'kind' => 'lesson', 'title' => 'آزمایش «' . $e['name'] . '» ' . $labels[ $res['verdict'] ], 'body' => sprintf( 'تغییر: %s · اثر خالص روی %s: %s · اطمینان: %s', $e['change'], sap_exp_metrics()[ $m ], $lower_better ? sap_num( $res['lift'], 1 ) . ' جایگاه' : sap_num( $res['lift'], 1 ) . '٪', $res['conf'] ? sap_num( abs( $res['conf'] ) ) . '٪' : 'کم' ), 'source' => 'experiment', 'ref' => 'exp:' . $id, 'pinned' => 'win' === $res['verdict'] ) );
	}
	sap_experiments_save( $project['id'], $items );
	return $res;
}

function sap_exp_verdicts() {
	return array(
		'wait'    => array( 'در انتظار داده', '' ),
		'running' => array( 'در حال اجرا', 'is-info' ),
		'win'     => array( 'موفق (معنادار)', 'is-good' ),
		'loss'    => array( 'اثر منفی (معنادار)', 'is-bad' ),
		'flat'    => array( 'بدون اثر معنادار', 'is-soft' ),
	);
}

add_action( 'sap_daily', function () {
	foreach ( sap_projects() as $p ) {
		foreach ( sap_experiments( $p['id'] ) as $e ) {
			if ( 'running' === $e['status'] ) {
				sap_exp_measure( $p, $e['id'] );
			}
		}
	}
}, 45 );
