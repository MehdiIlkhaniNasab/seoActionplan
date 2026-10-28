<?php
/**
 * Report export by pillar: what SeoReport needs for a full, professional
 * report of the month in three parts — technical, content and off-page —
 * plus the measured impact of the work and next month's plan per pillar.
 *
 * The figures of the current month are stored in the month record when the
 * report reads them, so a later report of that month shows the state of that
 * month, not today's.
 *
 * @package SeoActionPlan
 */

defined( 'ABSPATH' ) || exit;

/**
 * Technical pillar: health score, sections with key numbers, crawl / index /
 * speed facts and issues no longer detected this month.
 */
function sap_report_technical( $project, $from, $to ) {
	global $wpdb;
	$pid  = $project['id'];
	$list = sap_tech_checklist( $project );
	$secs = array();
	foreach ( $list as $k => $s ) {
		$secs[] = array(
			'key'    => $k,
			'label'  => $s['label'],
			'score'  => $s['score'],
			'status' => $s['status'],
			'issues' => count( $s['items'] ),
			'kpis'   => array_map( function ( $kp ) {
				return array( 'value' => $kp[0], 'label' => $kp[1], 'tone' => $kp[2] );
			}, $s['kpis'] ),
			'top'    => array_slice( array_map( function ( $f ) {
				return $f['title'];
			}, $s['items'] ), 0, 3 ),
		);
	}
	$tech_det = array();
	foreach ( sap_tech_sections() as $s ) {
		$tech_det = array_merge( $tech_det, $s[3] );
	}
	$in       = implode( ',', array_fill( 0, count( $tech_det ), '%s' ) );
	$resolved = $tech_det ? $wpdb->get_col( $wpdb->prepare( 'SELECT title FROM ' . sap_table( 'findings' ) . " WHERE project_id = %d AND status = 'resolved' AND last_seen BETWEEN %s AND %s AND detector IN ($in) ORDER BY score DESC LIMIT 20", array_merge( array( $pid, $from . ' 00:00:00', $to . ' 23:59:59' ), $tech_det ) ) ) : array(); // phpcs:ignore
	$crawl = sap_dataset( $pid, 'crawl' );
	$insp  = (array) ( sap_dataset_payload( $pid, 'gsc_inspect' )['rows'] ?? array() );
	return array(
		'score'    => sap_tech_score( $list ),
		'sections' => $secs,
		'resolved' => $resolved,
		'crawl'    => $crawl ? array( 'date' => substr( $crawl['fetched_at'], 0, 10 ), 'urls' => (int) ( $crawl['meta']['urls'] ?? 0 ), 'codes' => (array) ( $crawl['meta']['codes'] ?? array() ) ) : null,
		'index'    => $insp ? array( 'inspected' => count( $insp ), 'indexed' => count( array_filter( $insp, function ( $r ) {
			return 'PASS' === ( $r['verdict'] ?? '' );
		} ) ) ) : null,
	);
}

/**
 * Content pillar: keyword-map health, audit by content type, rewrites and
 * the calendar.
 */
function sap_report_content( $project, $period, $from, $to ) {
	global $wpdb;
	$pid    = $project['id'];
	$groups = array();
	foreach ( sap_content_inventory( $project ) as $g => $rows ) {
		if ( ! $rows ) {
			continue;
		}
		$groups[] = array(
			'label' => sap_content_groups()[ $g ][0],
			'pages' => count( $rows ),
			'score' => (int) round( array_sum( array_column( $rows, 'score' ) ) / count( $rows ) ),
			'weak'  => count( array_filter( $rows, function ( $r ) {
				return $r['score'] < 60;
			} ) ),
			'zero'  => count( array_filter( $rows, function ( $r ) {
				return ! $r['impr'];
			} ) ),
		);
	}
	$rewrites = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . sap_table( 'revisions' ) . " WHERE project_id = %d AND status IN ('approved','published') AND created_at BETWEEN %s AND %s", $pid, $from . ' 00:00:00', $to . ' 23:59:59' ) ); // phpcs:ignore
	$updates  = sap_updates( $pid, array( 'period' => $period ) );
	$next     = sap_period_shift( $period, 1 );
	return array(
		'keymap'   => sap_keymap_health( $project ),
		'audit'    => $groups,
		'rewrites' => $rewrites,
		'updates'  => array(
			'planned' => count( $updates ),
			'done'    => count( array_filter( $updates, function ( $u ) {
				return 'done' === $u['status'];
			} ) ),
			'items'   => array_map( function ( $u ) {
				return array( 'title' => $u['title'], 'url' => $u['url'], 'keyword' => $u['keyword'], 'status' => sap_update_statuses()[ $u['status'] ] ?? $u['status'] );
			}, array_slice( $updates, 0, 20 ) ),
		),
		'calendar' => array(
			'this_month' => count( sap_calendar_items( $pid, $period ) ),
			'next_month' => count( sap_calendar_items( $pid, $next ) ),
		),
	);
}

/**
 * Off-page pillar: budget, the plan (reportage, guest posts, brand mentions…)
 * and what of it was done.
 */
function sap_report_offpage( $project, $done ) {
	$pid  = $project['id'];
	$set  = sap_offpage_settings( $project );
	$plan = sap_dataset_payload( $pid, 'ai_offpage' );
	$done_titles = array();
	foreach ( $done as $t ) {
		if ( 'offpage' === ( $t['category'] ?? '' ) ) {
			$done_titles[ sap_norm_text( $t['title'] ) ] = true;
		}
	}
	$types   = sap_offpage_types();
	$actions = array();
	foreach ( (array) ( $plan['actions'] ?? array() ) as $a ) {
		$actions[] = array(
			'type'   => $types[ $a['type'] ?? '' ] ?? (string) ( $a['type'] ?? '' ),
			'title'  => (string) ( $a['title'] ?? '' ),
			'media'  => (string) ( $a['media'] ?? '' ),
			'target' => (string) ( $a['target_url'] ?? '' ),
			'anchor' => (string) ( $a['anchor'] ?? '' ),
			'price'  => (int) ( $a['price'] ?? 0 ),
			'done'   => isset( $done_titles[ sap_norm_text( (string) ( $a['title'] ?? '' ) ) ] ),
		);
	}
	return array(
		'budget'   => (int) ( $set['budget'] ?? 0 ),
		'strategy' => (string) ( $plan['strategy'] ?? '' ),
		'anchor'   => (string) ( $plan['anchor_policy'] ?? '' ),
		'actions'  => $actions,
		'planned_cost' => array_sum( array_column( $actions, 'price' ) ),
		'done_cost'    => array_sum( array_map( function ( $a ) {
			return $a['done'] ? $a['price'] : 0;
		}, $actions ) ),
	);
}

/**
 * Measured impact for the report: totals, by pillar, lessons and the actions
 * with the clearest result.
 */
function sap_report_impact( $project ) {
	$s     = sap_impact_summary( $project['id'] );
	$items = array_values( array_filter( sap_impact_items( $project['id'] ), function ( $i ) {
		return in_array( $i['verdict'] ?? '', array( 'growth', 'decline', 'neutral' ), true );
	} ) );
	usort( $items, function ( $a, $b ) {
		return abs( $b['net_change'] ) <=> abs( $a['net_change'] );
	} );
	$pillars = sap_pillars();
	$v       = sap_impact_verdicts();
	return array(
		'total'         => $s['total'],
		'growth'        => $s['growth'],
		'decline'       => $s['decline'],
		'neutral'       => $s['neutral'],
		'waiting'       => $s['wait'] + $s['low'],
		'clicks_before' => $s['clicks_before'],
		'clicks_after'  => $s['clicks_after'],
		'by_pillar'     => array_map( function ( $bp ) {
			return $bp;
		}, $s['by_pillar'] ),
		'pillar_labels' => array_map( function ( $p ) {
			return $p[0];
		}, $pillars ),
		'lessons'       => $s['lessons'],
		'items'         => array_map( function ( $i ) use ( $pillars, $v ) {
			return array(
				'title'   => $i['title'],
				'url'     => $i['url'],
				'pillar'  => $pillars[ $i['pillar'] ][0] ?? '',
				'done'    => $i['done'],
				'clicks_before' => round( $i['before']['clicks'] / $i['before']['days'] * 28 ),
				'clicks_after'  => round( $i['after']['clicks'] / $i['after']['days'] * 28 ),
				'change'  => $i['clicks_change'],
				'net'     => $i['net_change'],
				'pos_before' => $i['kw_before'] ?? $i['before']['position'],
				'pos_after'  => $i['kw_after'] ?? $i['after']['position'],
				'keyword' => $i['keyword'],
				'verdict' => $v[ $i['verdict'] ][0],
				'tone'    => $v[ $i['verdict'] ][1],
				'early'   => ! empty( $i['early'] ),
			);
		}, array_slice( $items, 0, 15 ) ),
	);
}

/**
 * Pillar part of the month report (stored in the month record for the current month).
 */
function sap_report_pillars( $project, $period, $m ) {
	$cur = sap_period_current() === $period;
	if ( ! $cur && ! empty( $m['pillars'] ) ) {
		return $m['pillars']; // A past month: the state stored back then.
	}
	list( $from, $to ) = sap_period_range( $period );
	$done    = array_values( (array) $m['done'] );
	$planned = array_values( (array) $m['planned'] );
	$work    = array();
	foreach ( array_keys( sap_pillars() ) as $pk ) {
		$work[ $pk ] = array( 'done' => 0, 'planned' => 0, 'hours' => 0, 'tasks' => array() );
	}
	foreach ( $planned as $t ) {
		++$work[ sap_pillar_of_context( $t['category'] ?? '' ) ]['planned'];
	}
	foreach ( $done as $t ) {
		$pk = sap_pillar_of_context( $t['category'] ?? '' );
		++$work[ $pk ]['done'];
		$work[ $pk ]['hours'] += (float) ( $t['effort'] ?? 0 );
		$work[ $pk ]['tasks'][] = array( 'title' => $t['title'], 'url' => $t['url'] ?? '', 'label' => $t['category_label'] ?? '', 'focus' => $t['focus'] ?? '' );
	}
	$out = array(
		'labels'    => array_map( function ( $p ) {
			return $p[0];
		}, sap_pillars() ),
		'work'      => $work,
		'technical' => sap_report_technical( $project, $from, $to ),
		'content'   => sap_report_content( $project, $period, $from, $to ),
		'offpage'   => sap_report_offpage( $project, $done ),
	);
	if ( $cur ) {
		sap_month_snapshot( $project, $period, array( 'pillars' => $out ) );
	}
	return $out;
}
