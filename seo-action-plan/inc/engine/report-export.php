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
	// Minor items (pages without impressions, AI-triaged as cosmetic) are
	// counted apart so the client report does not inflate the problems.
	$is_minor = function ( $f ) {
		return 'minor' === ( $f['evidence']['tier'] ?? '' ) || (int) $f['score'] <= 12;
	};
	foreach ( $list as $k => $s ) {
		$major  = array_values( array_filter( $s['items'], function ( $f ) use ( $is_minor ) {
			return ! $is_minor( $f );
		} ) );
		$secs[] = array(
			'key'    => $k,
			'label'  => $s['label'],
			'score'  => $s['score'],
			'status' => $s['status'],
			'issues' => count( $major ),
			'minor'  => count( $s['items'] ) - count( $major ),
			'kpis'   => array_map( function ( $kp ) {
				return array( 'value' => $kp[0], 'label' => $kp[1], 'tone' => $kp[2] );
			}, $s['kpis'] ),
			'top'    => array_slice( array_map( function ( $f ) {
				return $f['title'];
			}, $major ), 0, 3 ),
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
		'items'         => array_map( function ( $i ) use ( $pillars, $v, $project ) {
			return array(
				'title'   => $i['title'],
				'story'   => sap_impact_story( $project['id'], $i ),
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

/* ---------------------------------------------------------------------------
 * Full action plan of the month (every task, whatever its state)
 * ------------------------------------------------------------------------- */

/**
 * Every task of the month with its state, plus a review: completion by pillar,
 * open high-priority work, overdue tasks and the measured impact of done ones.
 * After a reset the archived month (planned / done) stands in for the tasks.
 */
function sap_report_action_plan( $project, $period, $m = null ) {
	$pid      = $project['id'];
	list( $from, $to ) = sap_period_range( $period );
	$pillars  = sap_pillars();
	$statuses = sap_task_statuses();
	$fnames   = wp_list_pluck( sap_focuses( $pid ), 'name', 'id' );
	$impact   = array();
	foreach ( sap_impact_items( $pid ) as $i ) {
		$impact[ $i['ref'] ?? '' ] = $i;
	}
	$verdicts = sap_impact_verdicts();
	$sources  = array( 'ai' => 'تحلیل هوشمند', 'finding' => 'پیشنهاد تأییدشده', 'auto' => 'قانون خودکار', 'custom' => 'کار سفارشی (هوش مصنوعی)', 'recurring' => 'کار تکراری', 'manual' => 'دستی' );
	$group    = array( 'done' => 'done', 'doing' => 'doing', 'waiting' => 'doing', 'inbox' => 'todo', 'next' => 'todo', 'someday' => 'deferred', 'dropped' => 'dropped' );
	$today    = current_time( 'Y-m-d' );
	$items    = array();
	foreach ( sap_tasks( array( 'project_id' => $pid, 'period' => $period ) ) as $t ) {
		$pk  = sap_pillar_of_context( $t['context'] );
		$imp = $impact[ 'task:' . $t['id'] ] ?? null;
		$v   = $imp ? ( $imp['verdict'] ?? 'wait' ) : '';
		$due = (string) ( $t['due_date'] ?? '' );
		$items[] = array(
			'title'          => $t['title'],
			'pillar'         => $pk,
			'pillar_label'   => $pillars[ $pk ][0],
			'category'       => $t['context'],
			'category_label' => sap_context_label( $t['context'] ),
			'focus'          => $fnames[ $t['focus_id'] ] ?? '',
			'url'            => (string) $t['url'],
			'keyword'        => (string) $t['keyword'],
			'status'         => $t['status'],
			'state'          => $group[ $t['status'] ] ?? 'todo',
			'status_label'   => $statuses[ $t['status'] ] ?? $t['status'],
			'priority'       => $t['score'] >= 60 ? 'high' : ( $t['score'] >= 35 ? 'medium' : 'low' ),
			'effort'         => (float) $t['effort'],
			'due'            => '0000-00-00' === $due ? '' : $due,
			'overdue'        => '' !== $due && '0000-00-00' !== $due && $due < min( $today, $to ) && 'done' !== $t['status'] && 'dropped' !== $t['status'],
			'done_at'        => substr( (string) $t['done_at'], 0, 10 ),
			'created'        => substr( (string) $t['created_at'], 0, 10 ),
			'result'         => (string) $t['result'],
			'waiting_for'    => 'waiting' === $t['status'] ? (string) $t['waiting_for'] : '',
			'source'         => $sources[ $t['source'] ] ?? ( '' !== (string) $t['recurring'] ? $sources['recurring'] : $sources['manual'] ),
			'impact'         => $imp ? array( 'verdict' => $v, 'label' => $verdicts[ $v ][0] ?? '', 'tone' => $verdicts[ $v ][1] ?? '', 'net' => $imp['net_change'] ?? null, 'clicks' => $imp['clicks_change'] ?? null ) : null,
		);
	}
	$archived = false;
	if ( ! $items && $m ) {
		// The plan was reset: the archived month keeps what was planned and done.
		$archived = true;
		foreach ( (array) ( $m['planned'] ?? array() ) as $k => $p ) {
			$is_done = isset( $m['done'][ $k ] );
			$pk      = sap_pillar_of_context( $p['category'] ?? '' );
			$items[] = array(
				'title' => $p['title'], 'pillar' => $pk, 'pillar_label' => $pillars[ $pk ][0], 'category' => $p['category'] ?? '', 'category_label' => $p['category_label'] ?? '',
				'focus' => $p['focus'] ?? '', 'url' => $p['url'] ?? '', 'keyword' => '', 'status' => $is_done ? 'done' : 'next', 'state' => $is_done ? 'done' : 'todo',
				'status_label' => $is_done ? $statuses['done'] : 'انجام نشده', 'priority' => 'medium', 'effort' => (float) ( $p['effort'] ?? 0 ), 'due' => '', 'overdue' => false,
				'done_at' => substr( (string) ( $p['done_at'] ?? '' ), 0, 10 ), 'created' => '', 'result' => (string) ( $p['result'] ?? '' ), 'waiting_for' => '', 'source' => '', 'impact' => null,
			);
		}
	}
	$order = array( 'done' => 0, 'doing' => 1, 'todo' => 2, 'deferred' => 3, 'dropped' => 4 );
	$prio  = array( 'high' => 0, 'medium' => 1, 'low' => 2 );
	usort( $items, function ( $a, $b ) use ( $order, $prio ) {
		return array( $order[ $a['state'] ], $prio[ $a['priority'] ], $b['done_at'] ) <=> array( $order[ $b['state'] ], $prio[ $b['priority'] ], $a['done_at'] );
	} );

	$count = array_fill_keys( array_keys( $order ), 0 );
	$hours = array_fill_keys( array_keys( $order ), 0.0 );
	$by    = array();
	foreach ( $pillars as $pk => $pl ) {
		$by[ $pk ] = array( 'label' => $pl[0], 'planned' => 0, 'done' => 0, 'open' => 0, 'hours' => 0.0, 'hours_done' => 0.0 );
	}
	foreach ( $items as $i ) {
		++$count[ $i['state'] ];
		$hours[ $i['state'] ] += $i['effort'];
		if ( in_array( $i['state'], array( 'deferred', 'dropped' ), true ) ) {
			continue;
		}
		++$by[ $i['pillar'] ]['planned'];
		$by[ $i['pillar'] ]['hours'] += $i['effort'];
		if ( 'done' === $i['state'] ) {
			++$by[ $i['pillar'] ]['done'];
			$by[ $i['pillar'] ]['hours_done'] += $i['effort'];
		} else {
			++$by[ $i['pillar'] ]['open'];
		}
	}
	$active  = $count['done'] + $count['doing'] + $count['todo'];
	$high    = array_values( array_filter( $items, function ( $i ) {
		return 'high' === $i['priority'] && in_array( $i['state'], array( 'doing', 'todo' ), true );
	} ) );
	$overdue = array_values( array_filter( $items, function ( $i ) {
		return $i['overdue'];
	} ) );
	$measured = array_filter( $items, function ( $i ) {
		return $i['impact'] && in_array( $i['impact']['verdict'], array( 'growth', 'decline', 'neutral' ), true );
	} );
	$review = array();
	if ( $active ) {
		$review[] = sprintf( 'از %s کار برنامه این ماه %s کار انجام شد (%s٪)%s.', sap_num( $active ), sap_num( $count['done'] ), sap_num( round( $count['done'] / $active * 100 ) ), $count['doing'] ? '، ' . sap_num( $count['doing'] ) . ' کار در حال انجام یا در انتظار است' : '' );
	}
	foreach ( $by as $b ) {
		if ( $b['planned'] ) {
			$review[] = sprintf( '%s: %s از %s کار (%s ساعت از %s ساعت).', $b['label'], sap_num( $b['done'] ), sap_num( $b['planned'] ), sap_num( $b['hours_done'], 1 ), sap_num( $b['hours'], 1 ) );
		}
	}
	if ( $high ) {
		$review[] = sprintf( '%s کار با اولویت بالا هنوز باز است و در اولویت ماه بعد قرار می‌گیرد: %s.', sap_num( count( $high ) ), implode( '، ', array_slice( array_column( $high, 'title' ), 0, 3 ) ) );
	}
	if ( $overdue ) {
		$review[] = sprintf( '%s کار از موعد خود گذشته است.', sap_num( count( $overdue ) ) );
	}
	if ( $measured ) {
		$g = count( array_filter( $measured, function ( $i ) {
			return 'growth' === $i['impact']['verdict'];
		} ) );
		$review[] = sprintf( 'اثر %s کار انجام‌شده در سرچ کنسول سنجیده شد: %s مورد رشد.', sap_num( count( $measured ) ), sap_num( $g ) );
	}
	if ( $count['deferred'] || $count['dropped'] ) {
		$review[] = sprintf( '%s کار به «شاید بعداً» منتقل و %s کار حذف شد.', sap_num( $count['deferred'] ), sap_num( $count['dropped'] ) );
	}
	return array(
		'items'      => $items,
		'count'      => $count,
		'hours'      => $hours,
		'active'     => $active,
		'completion' => $active ? round( $count['done'] / $active * 100 ) : null,
		'by_pillar'  => $by,
		'high_open'  => count( $high ),
		'overdue'    => count( $overdue ),
		'review'     => $review,
		'archived'   => $archived,
	);
}

/* ---------------------------------------------------------------------------
 * Technical data as gathered by Sokan (so the report does not fetch it again)
 * ------------------------------------------------------------------------- */

/**
 * Screaming Frog crawl rows (compact columns), URL Inspection, Search Console
 * sitemaps, Core Web Vitals, robots.txt and the sitemap check — in the shapes
 * SeoReport uses for its own data.
 */
function sap_report_tech_data( $project ) {
	$pid = $project['id'];
	$out = array();
	$crawl = sap_dataset( $pid, 'crawl' );
	if ( $crawl && ! empty( $crawl['payload']['rows'] ) ) {
		$cols = array( 'url', 'type', 'code', 'idx', 'idx_why', 'title', 'title_len', 'title_px', 'meta', 'meta_len', 'h1', 'h1_2', 'robots', 'canon', 'size', 'words', 'depth', 'inlinks', 'outlinks', 'rt', 'redirect', 'dupes', 'hash' );
		$rows = array();
		$skip = 0;
		foreach ( (array) $crawl['payload']['rows'] as $r ) {
			// Same judgement as Sokan's own analysis: filter / paginated / cart / canonicalised
			// pages are not counted as problems in the client report (errors always are).
			if ( 200 === (int) ( $r['code'] ?? 0 ) && '' !== sap_url_skip_reason( $pid, (string) ( $r['url'] ?? '' ), (string) ( $r['title'] ?? '' ) ) ) {
				++$skip;
				continue;
			}
			$t = array();
			foreach ( $cols as $c ) {
				$t[] = $r[ $c ] ?? '';
			}
			$rows[] = $t;
		}
		$out['crawl'] = array(
			'date'  => $crawl['fetched_at'],
			'urls'  => (int) ( $crawl['meta']['urls'] ?? count( $rows ) ),
			'codes' => (array) ( $crawl['meta']['codes'] ?? array() ),
			'cols'  => $cols,
			'rows'  => $rows,
			'skipped' => $skip,
		);
	}
	$insp = sap_dataset( $pid, 'gsc_inspect' );
	if ( $insp && ! empty( $insp['payload']['rows'] ) ) {
		$out['inspection'] = array_map( function ( $r ) {
			return array(
				'url'            => $r['url'] ?? '',
				'verdict'        => ( $r['verdict'] ?? '' ) ?: 'VERDICT_UNSPECIFIED',
				'coverage'       => $r['coverage'] ?? '',
				'indexing_state' => $r['indexing'] ?? '',
				'robots'         => $r['robots'] ?? '',
				'fetch'          => $r['fetch'] ?? '',
				'last_crawl'     => $r['last_crawl'] ?? '',
				'google_canon'   => $r['g_canon'] ?? '',
				'user_canon'     => $r['u_canon'] ?? '',
				'rich_verdict'   => $r['rich'] ?? '',
				'rich_types'     => (array) ( $r['rich_types'] ?? array() ),
			);
		}, array_values( (array) $insp['payload']['rows'] ) );
		$out['inspection_date'] = $insp['fetched_at'];
	}
	$maps = sap_dataset_payload( $pid, 'gsc_sitemaps' );
	if ( ! empty( $maps['sitemaps'] ) ) {
		$out['sitemaps'] = array_values( (array) $maps['sitemaps'] );
	}
	$cwv = sap_dataset( $pid, 'cwv' );
	if ( $cwv && ! empty( $cwv['payload']['pages'] ) ) {
		$out['cwv']      = array_values( (array) $cwv['payload']['pages'] );
		$out['cwv_date'] = $cwv['fetched_at'];
	}
	$robots = sap_dataset( $pid, 'robots' );
	if ( $robots ) {
		$r = $robots['payload'];
		$out['robots'] = array(
			'date'     => $robots['fetched_at'],
			'code'     => (int) ( $r['code'] ?? 0 ),
			'sitemaps' => array_values( (array) ( $r['sitemaps'] ?? array() ) ),
			'rules'    => count( (array) ( $r['rules'] ?? array() ) ),
			'blocked'  => array_values( array_map( function ( $t ) {
				return array( 'path' => $t['path'] ?? '', 'label' => $t['label'] ?? '' );
			}, array_filter( (array) ( $r['tests'] ?? array() ), function ( $t ) {
				return is_array( $t ) && empty( $t['allowed'] );
			} ) ) ),
		);
	}
	$sc = sap_dataset( $pid, 'sitemap_check' );
	if ( $sc && ! empty( $sc['payload']['root'] ) ) {
		$s = $sc['payload'];
		$out['sitemap_check'] = array(
			'date'       => $sc['fetched_at'],
			'root'       => $s['root'],
			'files'      => count( (array) ( $s['maps'] ?? array() ) ),
			'total'      => (int) ( $s['total'] ?? 0 ),
			'in_robots'  => ! empty( $s['in_robots'] ),
			'missing'    => array_slice( (array) ( $s['missing'] ?? array() ), 0, 15 ),
			'bad'        => array_slice( (array) ( $s['bad'] ?? array() ), 0, 15 ),
			'other_host' => array_slice( (array) ( $s['other_host'] ?? array() ), 0, 10 ),
			'skipped'    => (int) ( $s['skipped'] ?? 0 ),
		);
	}
	return $out;
}
